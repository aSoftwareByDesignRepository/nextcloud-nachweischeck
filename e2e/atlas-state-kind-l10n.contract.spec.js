// @ts-check
/**
 * ATLAS state/kind l10n render legs — nw-crit6-state-kind-enums-raw-i18n.
 *
 * Server-emitted enums must render translated, never raw. The fixture
 * user's NC language is forced to `de` for the duration of this spec via
 * occ user:setting (removed again in afterAll), so every badge/cell/pill
 * painted by workspace.js proves the de catalog resolution end-to-end:
 *
 *   finance view  — drawdown badges beantragt/eingegangen, claim kind
 *                   cells Zinsen/Rückforderung, claim state offen/beglichen
 *   finplan view  — version pills "v1 — ersetzt"/"v2 — aktiv"
 *
 * Selectors stay structural (rows located via the seeded MARK fixture
 * data, not UI text); the literal assertions below are the subject of
 * the proof itself, not locale-fragile navigation.
 */
const { test, expect } = require('@playwright/test');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const { BASE, APP, USERS, loginAs, nwApi } = require('./helpers/auth-guard');

const COMPOSE_ROOT = path.join(__dirname, '..', '..', '..');
const MARK = `nw-l10n-${Date.now() % 1000000}-${Math.floor(Math.random() * 1000)}`;
const READY = '#nw-main .nw-card, #nw-main .nw-empty-state, #nw-main table, #nw-main form';

function occUserSetting(uid, args) {
	return spawnSync(
		'docker',
		['compose', 'exec', '-u', 'www-data', '-T', 'nextcloud', 'php', 'occ',
			'user:setting', uid, ...args],
		{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
	);
}

async function goto(page, path) {
	await page.goto(`${BASE}${APP}${path}`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector(READY, { timeout: 45_000 });
}

test.describe('STATE_KIND_L10N', () => {
	test.describe.configure({ mode: 'serial' });

	let pid;
	const uid = USERS.admin.username;

	test.beforeAll(async ({ browser }) => {
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await loginAs(page, 'admin');

		const created = await nwApi(page, 'POST', '/projects', {
			name: `${MARK} project`, funder_name: 'BMBF', funder_ref: MARK,
			bew_start: '2026-01-01', bew_end: '2027-06-30',
			template_key: 'anbest-p', financing_mode: 'ausgaben',
		});
		pid = created.json && created.json.data && created.json.data.id;
		expect(pid, `project seed failed: ${JSON.stringify(created.json)}`).toBeTruthy();

		// v1 draft → activate; v2 draft → activate again so v1 goes
		// superseded — the pill bar then shows ersetzt + aktiv.
		const vers = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions`);
		const vid = vers.json.data[0].id;
		const act1 = await nwApi(page, 'POST', `/projects/${pid}/finplan/versions/${vid}/activate`, { base_version: 0 });
		expect(act1.status, `v1 activate failed: ${JSON.stringify(act1.json)}`).toBe(200);
		const draft = await nwApi(page, 'POST', `/projects/${pid}/finplan/draft`, { note: MARK });
		const vid2 = draft.json && draft.json.data && draft.json.data.id;
		expect(vid2, `finplan draft failed: ${JSON.stringify(draft.json)}`).toBeTruthy();
		const act2 = await nwApi(page, 'POST', `/projects/${pid}/finplan/versions/${vid2}/activate`, { base_version: 1 });
		expect(act2.status, `v2 activate failed: ${JSON.stringify(act2.json)}`).toBe(200);

		// Workpackage + position link — sibling specs resolve the newest
		// project (id DESC), so the fixture must be fully usable: Book
		// time refuses to open a dialog without an open AP.
		const ap = await nwApi(page, 'POST', `/projects/${pid}/workpackages`, {
			code: 'AP1', title: `${MARK} ap`, start_date: '2026-01-01', end_date: '2026-06-30',
		});
		const apId = ap.json && ap.json.data && ap.json.data.id;
		expect(apId, `workpackage seed failed: ${JSON.stringify(ap.json)}`).toBeTruthy();
		const pos = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions/${vid2}/positions`);
		const posId = pos.json && pos.json.data && pos.json.data[0] && pos.json.data[0].id;
		expect(posId, `positions fetch failed: ${JSON.stringify(pos.json)}`).toBeTruthy();
		const link = await nwApi(page, 'PUT', `/projects/${pid}/workpackages/${apId}/positions`, { position_ids: [posId] });
		expect(link.status, `position link failed: ${JSON.stringify(link.json)}`).toBe(200);

		// Drawdown requested (beantragt) + a second one marked received
		// against a real incoming payment (eingegangen).
		await nwApi(page, 'POST', `/projects/${pid}/drawdowns`, {
			requested_date: '2026-03-01', amount_minor: 500000, call_ref: `${MARK}-req`,
		});
		const pay = await nwApi(page, 'POST', `/projects/${pid}/payments`, {
			received_date: '2026-03-15', amount_minor: 250000,
		});
		const payId = pay.json && pay.json.data && pay.json.data.id;
		expect(payId, `payment seed failed: ${JSON.stringify(pay.json)}`).toBeTruthy();
		const dd2 = await nwApi(page, 'POST', `/projects/${pid}/drawdowns`, {
			requested_date: '2026-03-02', amount_minor: 250000, call_ref: `${MARK}-recv`,
		});
		const dd2Id = dd2.json && dd2.json.data && dd2.json.data.id;
		await nwApi(page, 'POST', `/projects/${pid}/drawdowns/${dd2Id}/decide`, {
			decision: 'receive', payment_id: payId,
		});

		// Claims: interest open (Zinsen/offen) + recovery settled
		// (Rückforderung/beglichen).
		const cl1 = await nwApi(page, 'POST', `/projects/${pid}/claims`, {
			kind: 'interest', title: `${MARK} interest`, occurred_date: '2026-04-01',
			principal_minor: 100000, rate_bp: 500,
			period_from: '2026-01-01', period_to: '2026-03-31',
		});
		expect(cl1.json && cl1.json.data, `interest claim failed: ${JSON.stringify(cl1.json)}`).toBeTruthy();
		const cl2 = await nwApi(page, 'POST', `/projects/${pid}/claims`, {
			kind: 'recovery', title: `${MARK} recovery`, occurred_date: '2026-04-02',
			amount_minor: 12345,
		});
		const cl2Id = cl2.json && cl2.json.data && cl2.json.data.id;
		expect(cl2Id, `recovery claim failed: ${JSON.stringify(cl2.json)}`).toBeTruthy();
		const settle = await nwApi(page, 'POST', `/projects/${pid}/claims/${cl2Id}/settle`, {});
		expect(settle.status, `claim settle failed: ${JSON.stringify(settle.json)}`).toBe(200);

		// Activate so the fixture does not leave a draft project as the
		// newest row — resolveProjectId picks id DESC and sibling specs
		// would inherit the Draft-state dashboard.
		const act = await nwApi(page, 'POST', `/projects/${pid}/activate`, {});
		expect(act.status, `project activate failed: ${JSON.stringify(act.json)}`).toBe(200);

		const set = occUserSetting(uid, ['core', 'lang', 'de']);
		expect(set.status, `occ lang=de failed: ${set.stderr || set.stdout}`).toBe(0);
		await ctx.close();
	});

	test.afterAll(() => {
		occUserSetting(uid, ['core', 'lang', '--delete']);
	});

	test('de.finance.drawdowns_claims.render_translated', async ({ page }) => {
		await loginAs(page, 'admin');
		await goto(page, `/?project=${pid}&view=finance`);

		// Drawdown rows located via the seeded call_ref markers.
		const reqRow = page.locator('#nw-main table tbody tr', { hasText: `${MARK}-req` });
		await expect(reqRow).toHaveCount(1);
		await expect(reqRow).toContainText('beantragt');
		const recvRow = page.locator('#nw-main table tbody tr', { hasText: `${MARK}-recv` });
		await expect(recvRow).toHaveCount(1);
		await expect(recvRow).toContainText('eingegangen');

		// Claim rows via seeded titles — kind cell + state badge translated.
		const intRow = page.locator('#nw-main table tbody tr', { hasText: `${MARK} interest` });
		await expect(intRow).toHaveCount(1);
		await expect(intRow).toContainText('Zinsen');
		await expect(intRow).toContainText('offen');
		const recRow = page.locator('#nw-main table tbody tr', { hasText: `${MARK} recovery` });
		await expect(recRow).toHaveCount(1);
		await expect(recRow).toContainText('Rückforderung');
		await expect(recRow).toContainText('beglichen');

		// Raw enums must not surface anywhere on the painted view.
		for (const raw of ['requested', 'received', 'settled', 'interest', 'recovery']) {
			await expect(page.locator('#nw-main').getByText(raw, { exact: true })).toHaveCount(0);
		}
	});

	test('de.finplan.version_pills.render_translated', async ({ page }) => {
		await loginAs(page, 'admin');
		await goto(page, `/?project=${pid}&view=finplan`);

		const pills = page.locator('.nw-filter');
		const texts = await pills.allTextContents();
		const joined = texts.join('\n');
		expect(joined).toContain('v1 — ersetzt');
		expect(joined).toContain('v2 — aktiv');
		for (const raw of ['superseded', 'active', 'draft']) {
			expect(joined).not.toContain(`— ${raw}`);
		}
	});
});
