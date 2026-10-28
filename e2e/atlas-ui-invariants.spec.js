// @ts-check
/**
 * ATLAS_UI_INVARIANTS — mechanical cross-cutting checks on representative
 * NachweisCheck surfaces (checklists/UI-INVARIANTS.md):
 *   stored_xss · a11y DOM · mutation freshness · double-submit guard ·
 *   form-survival on 5xx · O(1) list requests · console errors · raw keys
 */
const { test, expect } = require('@playwright/test');
const {
	ATLAS_XSS_PAYLOADS, assertNoInjection, assertA11yDom,
	assertSurfaceFresh, assertNoDuplicateSubmit,
	assertFormSurvivesFailure, countApiRequests,
	trackConsoleErrors, assertNoConsoleErrors, assertNoRawI18nKeys,
} = require('../../_shared/e2e/atlas-ui-invariants');
const { BASE, APP, loginAs, nwApi, resolveProjectId } = require('./helpers/auth-guard');

const CONTENT = '#app-content.nw-app';
const READY = '#nw-main .nw-card, #nw-main .nw-empty-state, #nw-main table, #nw-main form, #nw-settings .nw-card, #nw-settings .nw-empty-state';

async function goto(page, path) {
	await page.goto(`${BASE}${APP}${path}`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector(READY, { timeout: 45_000 });
}

test.describe('ATLAS_UI_INVARIANTS', () => {
	test.describe.configure({ mode: 'serial' });

	let projectId;

	test.beforeAll(async ({ browser }) => {
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await loginAs(page, 'admin');
		projectId = await resolveProjectId(page);
		await ctx.close();
	});

	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'admin');
	});

	test('stored_xss_unescaped: payload project name inert on home + dashboard', async ({ page }) => {
		// name column is varchar(200) — the server now 400-validates
		// over-length input; keep the seed under the limit while carrying
		// the first payloads (img / script / svg injection classes).
		const name = `XSS-${Date.now()} ${ATLAS_XSS_PAYLOADS.join(' ')}`.slice(0, 196);
		const created = await nwApi(page, 'POST', '/projects', {
			name,
			funder_name: 'BMBF',
			funder_ref: `XSS-${Date.now()}`,
			template_key: 'anbest-p',
			financing_mode: 'ausgaben',
		});
		const xid = created.json && created.json.data && created.json.data.id;
		expect(xid, `xss project seed failed: ${JSON.stringify(created.json)}`).toBeTruthy();
		try {
			await goto(page, '/');
			await assertNoInjection(page);
			await goto(page, `/?project=${xid}&view=dashboard`);
			await assertNoInjection(page);
		} finally {
			await nwApi(page, 'DELETE', `/projects/${xid}`);
		}
	});

	test('a11y_dom_violations: dashboard surface clean', async ({ page }) => {
		await goto(page, `/?project=${projectId}&view=dashboard`);
		const findings = await assertA11yDom(page, { content: CONTENT });
		expect(findings, findings.join('\n')).toEqual([]);
	});

	test('a11y_dom_violations: open dialog state clean', async ({ page }) => {
		await goto(page, `/?project=${projectId}&view=time`);
		const btn = page.locator('#nw-page-actions button').first();
		await expect(btn).toBeVisible({ timeout: 15_000 });
		await btn.click();
		await expect(page.locator('dialog.nw-dialog[open]')).toBeVisible({ timeout: 15_000 });
		const findings = await assertA11yDom(page, { content: 'dialog.nw-dialog[open]' });
		expect(findings, findings.join('\n')).toEqual([]);
	});

	test('mutation_freshness: api-created deviation surfaces without manual F5', async ({ page }) => {
		const marker = `fresh-${Date.now()}`;
		await assertSurfaceFresh(page, {
			content: CONTENT,
			mutate: async () => {
				const aps = await nwApi(page, 'GET', `/projects/${projectId}/workpackages`);
				const apId = ((aps.json && aps.json.data) || [])[0] && aps.json.data[0].id;
				expect(apId, 'no workpackage seeded for deviation scope').toBeTruthy();
				const res = await nwApi(page, 'POST', `/projects/${projectId}/deviations`, {
					scope: 'ap', ref_id: apId, kind: 'cost', reason_text: marker,
				});
				expect(res.status, `deviation create failed: ${JSON.stringify(res.json)}`).toBeLessThan(400);
			},
			visit: async () => { await goto(page, `/?project=${projectId}&view=deviations`); },
			expect: { present: marker },
		});
	});

	test('double_submit_dup: book-time dialog fires ≤1 POST', async ({ page }) => {
		await goto(page, `/?project=${projectId}&view=time`);
		const btn = page.locator('#nw-page-actions button').first();
		await expect(btn).toBeVisible({ timeout: 15_000 });
		await btn.click();
		const dlg = page.locator('dialog.nw-dialog[open]');
		await expect(dlg).toBeVisible({ timeout: 15_000 });
		await dlg.locator('input[name="minutes"]').fill('15');
		const submitBtn = dlg.locator('button[type="submit"]');
		const before = await nwApi(page, 'GET', `/projects/${projectId}/time-entries`);
		const beforeIds = new Set(((before.json && before.json.data) || []).map((r) => r.id));
		await assertNoDuplicateSubmit(page, {
			mutatingUrl: '/time-entries',
			method: 'POST',
			// DOM click — bypasses Playwright's disabled-button actionability
			// wait so the app's own busyGuard is what absorbs the second hit.
			submit: () => submitBtn.evaluate((el) => /** @type {HTMLElement} */ (el).click()),
		});
		// The one admitted write still lands — delete it so repeats stay clean.
		const after = await nwApi(page, 'GET', `/projects/${projectId}/time-entries`);
		const created = ((after.json && after.json.data) || []).filter((r) => !beforeIds.has(r.id));
		expect(created.length, `double-submit created ${created.length} rows`).toBeLessThanOrEqual(1);
		for (const row of created) {
			await nwApi(page, 'DELETE', `/projects/${projectId}/time-entries/${row.id}`);
		}
	});

	test('form_data_loss_on_5xx: book-time form retains values + renders error', async ({ page }) => {
		await goto(page, `/?project=${projectId}&view=time`);
		const btn = page.locator('#nw-page-actions button').first();
		await expect(btn).toBeVisible({ timeout: 15_000 });
		await btn.click();
		const dlg = page.locator('dialog.nw-dialog[open]');
		await expect(dlg).toBeVisible({ timeout: 15_000 });
		await assertFormSurvivesFailure(page, {
			failUrl: '/api/v1/projects/',
			method: 'POST',
			fields: {
				'dialog.nw-dialog[open] input[name="minutes"]': '45',
				'dialog.nw-dialog[open] input[name="note"]': 'survive-me',
			},
			submit: () => dlg.locator('button[type="submit"]').click(),
			errorSel: '.nw-toast--error, .nw-toast',
		});
	});

	test('n_plus_one_requests: receipts list render is O(1)', async ({ page }) => {
		const reqs = await countApiRequests(page, async () => {
			await goto(page, `/?project=${projectId}&view=receipts`);
			await page.waitForTimeout(500);
		}, '/api/v1/');
		const perRow = reqs.filter((r) => /\/receipts\/\d+/.test(r.url));
		expect(perRow, `per-row receipt fetches: ${perRow.map((r) => r.url).join(', ')}`).toEqual([]);
	});

	test('console errors: none on dashboard', async ({ page }) => {
		const errs = trackConsoleErrors(page);
		await goto(page, `/?project=${projectId}&view=dashboard`);
		assertNoConsoleErrors(errs, { allow: [/favicon/, /Failed to load resource.*404/] });
	});

	test('raw_i18n_keys: no leaked catalog keys on dashboard + settings', async ({ page }) => {
		await goto(page, `/?project=${projectId}&view=dashboard`);
		await assertNoRawI18nKeys(page, { content: CONTENT });
		await goto(page, '/settings/notifications');
		await assertNoRawI18nKeys(page, { content: CONTENT });
	});
});
