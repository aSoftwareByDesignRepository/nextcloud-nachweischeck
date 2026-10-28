// @ts-check
/**
 * ATLAS dialog-dismiss + filter-interaction + stale-response contract legs.
 *
 *   nw-crit1-dialog-dismiss-proof-gap — cancel/Escape close without
 *     mutation (plain/dirty/destructive/validation variants), confirm →
 *     persisted write.
 *   nw-crit1-ui-matrix-filter-inventory-gap — every filter/option control
 *     gets a leg that changes the rendered result set.
 *   nw-crit1-filter-stale-response-clobber — retriggerable loaders carry
 *     generation guards; a stale response must not paint over the newest
 *     selection.
 *
 * Locale-safe: only structural selectors + fixture MARK data text —
 * never translated label text (check-e2e-i18n-selectors gate).
 */
const { test, expect, request: pwRequest } = require('@playwright/test');
const { BASE, APP, USERS, loginAs, nwApi } = require('./helpers/auth-guard');

const MARK = `nw-dlg-${Date.now() % 1000000}-${Math.floor(Math.random() * 1000)}`;
const DIALOG = 'dialog.nw-dialog[open]';
const READY = '#nw-main .nw-card, #nw-main .nw-empty-state, #nw-main table, #nw-main form';

async function goto(page, path) {
	await page.goto(`${BASE}${APP}${path}`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector(READY, { timeout: 45_000 });
}

/** Full ANBest-P project seed via the real API (same recipe as auth-guard). */
async function seedProject(page) {
	const created = await nwApi(page, 'POST', '/projects', {
		name: `${MARK} project`, funder_name: 'BMBF', funder_ref: MARK,
		bew_start: '2026-01-01', bew_end: '2027-06-30',
		template_key: 'anbest-p', financing_mode: 'ausgaben',
	});
	const pid = created.json && created.json.data && created.json.data.id;
	expect(pid, `project seed failed: ${JSON.stringify(created.json)}`).toBeTruthy();
	const vers = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions`);
	const vid = vers.json.data[0].id;
	await nwApi(page, 'POST', `/projects/${pid}/finplan/versions/${vid}/activate`, { base_version: 0 });
	const ap = await nwApi(page, 'POST', `/projects/${pid}/workpackages`, {
		code: 'AP1', title: `${MARK} ap`, start_date: '2026-01-01', end_date: '2026-06-30',
	});
	const apId = ap.json.data.id;
	const pos = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions/${vid}/positions`);
	const posId = pos.json.data[0].id;
	await nwApi(page, 'PUT', `/projects/${pid}/workpackages/${apId}/positions`, { position_ids: [posId] });
	await nwApi(page, 'POST', `/projects/${pid}/activate`, {});
	return { pid, vid, apId, posId };
}

async function entryCount(page, pid) {
	const res = await nwApi(page, 'GET', `/projects/${pid}/time-entries`);
	return (res.json && res.json.data) ? res.json.data.length : -1;
}

async function receiptCount(page, pid) {
	const res = await nwApi(page, 'GET', `/projects/${pid}/receipts`);
	return (res.json && res.json.data) ? res.json.data.length : -1;
}

/** Row count inside the currently painted list card (first table). */
function listRows(page) {
	return page.locator('#nw-main table tbody tr');
}

test.describe('ATLAS_DIALOG_FILTERS', () => {
	test.describe.configure({ mode: 'serial' });

	let pid, vid, apId, posId;
	let janEntryId, febEntryId;

	test.beforeAll(async ({ browser }) => {
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await loginAs(page, 'admin');
		const seed = await seedProject(page);
		pid = seed.pid; vid = seed.vid; apId = seed.apId; posId = seed.posId;
		// Time entries on two months for the period-filter legs.
		const jan = await nwApi(page, 'POST', `/projects/${pid}/time-entries`, {
			ap_id: apId, entry_date: '2026-01-15', minutes: 60, note: `${MARK}-jan` });
		janEntryId = jan.json.data.id;
		const feb = await nwApi(page, 'POST', `/projects/${pid}/time-entries`, {
			ap_id: apId, entry_date: '2026-02-10', minutes: 90, note: `${MARK}-feb` });
		febEntryId = feb.json.data.id;
		await ctx.close();
	});

	test.beforeEach(async ({ page }) => {
		await loginAs(page, 'admin');
	});

	/* ── dialog dismiss legs ─────────────────────────────────────────── */

	test('dlg-book-time: clean Escape closes without mutation', async ({ page }) => {
		await goto(page, `/?project=${pid}&view=time`);
		const before = await entryCount(page, pid);
		await page.locator('#nw-page-actions .nw-btn--primary').first().click();
		await expect(page.locator(DIALOG)).toHaveCount(1, { timeout: 15_000 });
		await page.keyboard.press('Escape');
		await expect(page.locator(DIALOG)).toHaveCount(0, { timeout: 10_000 });
		expect(await entryCount(page, pid)).toBe(before);
	});

	test('dlg-book-time: clean Cancel button closes without mutation', async ({ page }) => {
		await goto(page, `/?project=${pid}&view=time`);
		const before = await entryCount(page, pid);
		await page.locator('#nw-page-actions .nw-btn--primary').first().click();
		const dlg = page.locator(DIALOG);
		await expect(dlg).toHaveCount(1, { timeout: 15_000 });
		await dlg.locator('button.nw-btn--secondary').click();
		await expect(page.locator(DIALOG)).toHaveCount(0, { timeout: 10_000 });
		expect(await entryCount(page, pid)).toBe(before);
	});

	test('dlg-book-time: dirty Escape stacks discard-confirm, Discard closes without mutation', async ({ page }) => {
		await goto(page, `/?project=${pid}&view=time`);
		const before = await entryCount(page, pid);
		await page.locator('#nw-page-actions .nw-btn--primary').first().click();
		const dlg = page.locator(DIALOG);
		await expect(dlg).toHaveCount(1, { timeout: 15_000 });
		await dlg.locator('input[name="note"]').fill('dirty-marks-the-guard');
		await page.keyboard.press('Escape');
		// Dirty-close guard stacks a second confirm dialog on top.
		await expect(page.locator(DIALOG)).toHaveCount(2, { timeout: 10_000 });
		await page.locator(DIALOG).last().locator('button[type="submit"]').click();
		await expect(page.locator(DIALOG)).toHaveCount(0, { timeout: 10_000 });
		expect(await entryCount(page, pid)).toBe(before);
	});

	test('dlg-book-time: dirty Escape then keep-editing retains the open dialog + value', async ({ page }) => {
		await goto(page, `/?project=${pid}&view=time`);
		await page.locator('#nw-page-actions .nw-btn--primary').first().click();
		const dlg = page.locator(DIALOG);
		await expect(dlg).toHaveCount(1, { timeout: 15_000 });
		await dlg.locator('input[name="note"]').fill('keep-me');
		await page.keyboard.press('Escape');
		await expect(page.locator(DIALOG)).toHaveCount(2, { timeout: 10_000 });
		// Cancel on the stacked confirm → back to the dirty form.
		await page.locator(DIALOG).last().locator('button.nw-btn--secondary').click();
		await expect(page.locator(DIALOG)).toHaveCount(1, { timeout: 10_000 });
		await expect(dlg.locator('input[name="note"]')).toHaveValue('keep-me');
	});

	test('dlg-book-time: invalid submit stays open (validation variant)', async ({ page }) => {
		await goto(page, `/?project=${pid}&view=time`);
		const before = await entryCount(page, pid);
		await page.locator('#nw-page-actions .nw-btn--primary').first().click();
		const dlg = page.locator(DIALOG);
		await expect(dlg).toHaveCount(1, { timeout: 15_000 });
		// minutes is required — empty submit must not close or mutate.
		await dlg.locator('button[type="submit"]').click();
		await expect(page.locator(DIALOG)).toHaveCount(1, { timeout: 5_000 });
		expect(await entryCount(page, pid)).toBe(before);
	});

	test('dlg-delete-time-entry: confirm cancel keeps row, confirm deletes (destructive variant)', async ({ page }) => {
		const seeded = await nwApi(page, 'POST', `/projects/${pid}/time-entries`, {
			ap_id: apId, entry_date: '2026-02-20', minutes: 45, note: `${MARK}-del` });
		const entryId = seeded.json.data.id;
		await goto(page, `/?project=${pid}&view=time`);
		const row = page.locator('tr', { hasText: `${MARK}-del` });
		await expect(row).toHaveCount(1, { timeout: 15_000 });

		// Cancel leg: dialog closes, row survives.
		await row.locator('button').last().click();
		const confirm = page.locator(DIALOG);
		await expect(confirm).toHaveCount(1, { timeout: 10_000 });
		await confirm.locator('button.nw-btn--secondary').click();
		await expect(page.locator(DIALOG)).toHaveCount(0, { timeout: 10_000 });
		let list = await nwApi(page, 'GET', `/projects/${pid}/time-entries`);
		expect(list.json.data.some((e) => e.id === entryId)).toBe(true);

		// Confirm leg: delete persists (durable re-read).
		await row.locator('button').last().click();
		await expect(page.locator(DIALOG)).toHaveCount(1, { timeout: 10_000 });
		await page.locator(DIALOG).locator('button[type="submit"]').click();
		await expect(page.locator(DIALOG)).toHaveCount(0, { timeout: 10_000 });
		list = await nwApi(page, 'GET', `/projects/${pid}/time-entries`);
		expect(list.json.data.some((e) => e.id === entryId)).toBe(false);
	});

	test('dlg-new-plan-draft: Cancel closes without mutation', async ({ page }) => {
		await goto(page, `/?project=${pid}&view=finplan`);
		const versBefore = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions`);
		await page.locator('#nw-page-actions .nw-btn--primary').first().click();
		const dlg = page.locator(DIALOG);
		await expect(dlg).toHaveCount(1, { timeout: 15_000 });
		await dlg.locator('button.nw-btn--secondary').click();
		await expect(page.locator(DIALOG)).toHaveCount(0, { timeout: 10_000 });
		const versAfter = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions`);
		expect(versAfter.json.data.length).toBe(versBefore.json.data.length);
	});

	/* ── filter / list-option legs ───────────────────────────────────── */

	test('filter-receipts: each pill value + search narrows the rendered set', async ({ page }) => {
		// Fixture: one file-backed receipt (not flagged), one no-attachment,
		// one duplicate of the file-backed, one salary — every pill has a
		// distinct expected set.
		const dav = `${BASE}/remote.php/dav/files/${USERS.admin.username}`;
		// Pure basic-auth context — the browser context's session cookies make
		// NC prefer session auth, which then demands a requesttoken (401).
		const davCtx = await pwRequest.newContext({
			httpCredentials: { username: USERS.admin.username, password: USERS.admin.password },
		});
		const up = await davCtx.put(`${dav}/${MARK}.txt`, { data: 'attachment' });
		expect(up.status(), `dav upload ${up.status()}`).toBeLessThan(300);
		await davCtx.dispose();
		const resolved = await nwApi(page, 'GET', `/projects/${pid}/files/resolve?path=${encodeURIComponent('/' + MARK + '.txt')}`);
		const fileId = resolved.json && resolved.json.data && resolved.json.data.file_id;
		expect(fileId, `resolve ${JSON.stringify(resolved.json)}`).toBeTruthy();

		const mk = (body) => nwApi(page, 'POST', `/projects/${pid}/receipts`, body);
		const r1 = await mk({ vendor: `${MARK}-alpha`, invoice_no: 'A-1', invoice_date: '2026-02-01',
			currency: 'EUR', total_minor: 1000, tax_minor: 0, purpose: 'x',
			splits: [{ position_id: posId, amount_minor: 1000 }] });
		expect(r1.status, JSON.stringify(r1.json)).toBe(201);
		const r2 = await mk({ vendor: `${MARK}-beta`, invoice_no: 'B-1', invoice_date: '2026-02-02',
			currency: 'EUR', total_minor: 2000, tax_minor: 0, purpose: 'x', file_id: fileId,
			splits: [{ position_id: posId, amount_minor: 2000 }] });
		expect(r2.status, JSON.stringify(r2.json)).toBe(201);
		const r3 = await mk({ vendor: `${MARK}-beta`, invoice_no: 'B-1', invoice_date: '2026-02-02',
			currency: 'EUR', total_minor: 2000, tax_minor: 0, purpose: 'x', file_id: fileId,
			splits: [{ position_id: posId, amount_minor: 2000 }] });
		expect(r3.status, JSON.stringify(r3.json)).toBe(201);
		const r4 = await mk({ vendor: `${MARK}-payroll`, invoice_date: '2026-02-15',
			currency: 'EUR', total_minor: 3000, tax_minor: 0, purpose: 'x', kind: 'salary',
			salary_period: '2026-02',
			splits: [{ position_id: posId, amount_minor: 3000 }] });
		expect(r4.status, JSON.stringify(r4.json)).toBe(201);

		await goto(page, `/?project=${pid}&view=receipts`);
		const pills = page.locator('.nw-filter-bar .nw-filter');
		await expect(pills).toHaveCount(5, { timeout: 15_000 });
		const rows = listRows(page);

		// all → 4 rows
		await expect(rows).toHaveCount(4);
		// flagged (dup OR no-attachment): alpha(no file) + beta-dup + payroll(no file) = 3
		await pills.nth(1).click();
		await expect(rows).toHaveCount(3);
		// duplicate only: the beta pair's second = 1
		await pills.nth(2).click();
		await expect(rows).toHaveCount(1);
		// no_attachment: alpha + payroll = 2
		await pills.nth(3).click();
		await expect(rows).toHaveCount(2);
		// salary: payroll = 1
		await pills.nth(4).click();
		await expect(rows).toHaveCount(1);
		// back to all + search narrows to the alpha vendor only
		await pills.nth(0).click();
		await expect(rows).toHaveCount(4);
		await page.locator('#nw-main input[name="rq"]').fill(`${MARK}-alpha`);
		await expect(rows).toHaveCount(1, { timeout: 10_000 });
	});

	test('filter-time-period: Apply narrows the rendered list', async ({ page }) => {
		await goto(page, `/?project=${pid}&view=time`);
		await page.locator('#nw-time-from').fill('2026-02-01');
		await page.locator('#nw-time-to').fill('2026-02-28');
		await page.locator('form.nw-filter-panel__form button[type="submit"]').click();
		const listCard = page.locator('#nw-main .nw-card table tbody tr');
		await expect(page.locator('tr', { hasText: `${MARK}-feb` })).toHaveCount(1, { timeout: 15_000 });
		await expect(page.locator('tr', { hasText: `${MARK}-jan` })).toHaveCount(0);
		// Clear → full set returns.
		await page.locator('[data-filter-clear]').click();
		await expect(page.locator('tr', { hasText: `${MARK}-jan` })).toHaveCount(1, { timeout: 15_000 });
		await expect(page.locator('tr', { hasText: `${MARK}-feb` })).toHaveCount(1);
	});

	test('opts-finplan-version: pill switches the positions detail to that version', async ({ page }) => {
		await nwApi(page, 'POST', `/projects/${pid}/finplan/draft`, { note: `${MARK}-draft` });
		await goto(page, `/?project=${pid}&view=finplan`);
		const pills = page.locator('.nw-filter-bar .nw-filter');
		await expect(pills).toHaveCount(2, { timeout: 15_000 });
		const head = page.locator('.nw-list-head__title').first();
		const secondLabel = await pills.nth(1).textContent();
		const verNum = (secondLabel.match(/v(\d+)/) || [])[1];
		expect(verNum, `pill label ${secondLabel}`).toBeTruthy();
		await pills.nth(1).click();
		await expect(head).toContainText(verNum, { timeout: 15_000 });
		await expect(pills.nth(1)).toHaveAttribute('aria-pressed', 'true');
	});

	/* ── stale-response clobber legs (generation guards) ─────────────── */

	test('stale-time-filter: delayed first Apply must not clobber the newest range', async ({ page }) => {
		await goto(page, `/?project=${pid}&view=time`);
		// Delay only range-A requests; the initial bare load already ran.
		await page.route('**/time-entries?from=2026-01-01*', async (route) => {
			await new Promise((r) => setTimeout(r, 2000));
			await route.continue();
		});
		await page.locator('#nw-time-from').fill('2026-01-01');
		await page.locator('#nw-time-to').fill('2026-01-31');
		await page.locator('form.nw-filter-panel__form button[type="submit"]').click();
		// Immediately re-apply a different range — B must win once painted
		// and stay painted after A resolves late.
		await page.locator('#nw-time-from').fill('2026-02-01');
		await page.locator('#nw-time-to').fill('2026-02-28');
		await page.locator('form.nw-filter-panel__form button[type="submit"]').click();
		await expect(page.locator('tr', { hasText: `${MARK}-feb` })).toHaveCount(1, { timeout: 15_000 });
		await page.waitForTimeout(2500);
		await expect(page.locator('tr', { hasText: `${MARK}-feb` })).toHaveCount(1);
		await expect(page.locator('tr', { hasText: `${MARK}-jan` })).toHaveCount(0);
	});

	test('stale-finplan-pill: delayed first pill must not clobber the newest selection', async ({ page }) => {
		const vers = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions`);
		const list = vers.json.data;
		expect(list.length).toBeGreaterThanOrEqual(2);
		await goto(page, `/?project=${pid}&view=finplan`);
		const pills = page.locator('.nw-filter-bar .nw-filter');
		await expect(pills).toHaveCount(2, { timeout: 15_000 });
		const head = page.locator('.nw-list-head__title').first();

		// Delay positions of version[0]; select it, then reselect version[1].
		await page.route(`**/finplan/versions/${list[0].id}/positions`, async (route) => {
			await new Promise((r) => setTimeout(r, 2000));
			await route.continue();
		});
		const v1label = await pills.nth(1).textContent();
		const v1num = (v1label.match(/v(\d+)/) || [])[1];
		await pills.nth(0).click();
		await pills.nth(1).click();
		await expect(head).toContainText(v1num, { timeout: 15_000 });
		await page.waitForTimeout(2500);
		await expect(head).toContainText(v1num);
		await expect(pills.nth(1)).toHaveAttribute('aria-pressed', 'true');
	});
});
