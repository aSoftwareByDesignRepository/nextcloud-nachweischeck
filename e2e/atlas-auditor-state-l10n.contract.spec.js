// @ts-check
/**
 * ATLAS auditor dl-state l10n render leg — intake 20261101T0651Z
 * (raw_i18n_enum_render): the auditor project-head "State" dl row
 * rendered the raw enum ('active' in a de UI, expected 'aktiv').
 *
 * The auditor surface is license-gated for GRANT creation (Pro
 * FEATURE_AUDITOR_SEAT, no license on the lab), so this spec seeds the
 * grant row directly into oc_nw_auditor_grant — the read path
 * (auditorApi#myProjects) carries no license check and renders the
 * same data a licensed instance would.
 *
 * Fixture isolation: BOTH users are disposable `nwt-*` accounts created
 * via occ for this spec and deleted in afterAll — the shared nw_*
 * fixture users and their locale pins are never touched (a live clio
 * capture sweep holds them mid-run). The auditor user's own
 * core.lang=de drives t() against the de catalog end-to-end.
 */
const { test, expect } = require('@playwright/test');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const { BASE, APP, nwApi, resolveProjectId } = require('./helpers/auth-guard');

const COMPOSE_ROOT = path.join(__dirname, '..', '..', '..');
const SFX = `${Date.now() % 1000000}-${Math.floor(Math.random() * 1000)}`;
const OWNER = `nwt-own-${SFX}`.replace(/[^a-z0-9._@-]/gi, '');
const AUDITOR = `nwt-aud-${SFX}`.replace(/[^a-z0-9._@-]/gi, '');
const PASS = `NwTmp!${SFX}`;
const READY = '#nw-main .nw-card, #nw-main .nw-empty-state, #nw-main table, #nw-main form, #nw-main .nw-callout';

function occ(args) {
	return spawnSync(
		'docker',
		['compose', 'exec', '-u', 'www-data', '-T', 'nextcloud', 'php', 'occ', ...args],
		{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
	);
}

function sql(stmt) {
	return spawnSync(
		'docker',
		['compose', 'exec', '-T', 'mariadb', 'mariadb', '-unextcloud', '-pnextcloud_password', 'nextcloud', '-e', stmt],
		{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 30_000 },
	);
}

function addUser(uid, lang) {
	const add = spawnSync(
		'docker',
		['compose', 'exec', '-e', `OC_PASS=${PASS}`, '-u', 'www-data', '-T', 'nextcloud',
			'php', 'occ', 'user:add', '--password-from-env', '--display-name', uid, uid],
		{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
	);
	expect(add.status, `user:add ${uid} failed: ${add.stderr || add.stdout}`).toBe(0);
	const set = occ(['user:setting', uid, 'core', 'lang', lang]);
	expect(set.status, `occ lang=${lang} ${uid} failed: ${set.stderr || set.stdout}`).toBe(0);
}

async function loginWith(page, username, password) {
	for (let attempt = 1; attempt <= 3; attempt++) {
		await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
		await page.locator('#user, input[name="user"]').first().fill(username, { force: true });
		await page.locator('#password, input[name="password"]').first().fill(password, { force: true });
		await page.locator('button[type="submit"], input[type="submit"], button.login-button').first().click();
		await page.waitForTimeout(2500);
		await page.goto(`${BASE}${APP}/`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
		if (!page.url().includes('/login')) {
			return;
		}
	}
	throw new Error(`login failed for ${username} — bounced back to /login`);
}

test.describe('AUDITOR_STATE_L10N', () => {
	test.describe.configure({ mode: 'serial' });

	let pid;

	test.beforeAll(async ({ browser }) => {
		addUser(OWNER, 'en');
		addUser(AUDITOR, 'de');

		// Owner seeds a fully-usable activated project (state=active).
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await loginWith(page, OWNER, PASS);
		pid = await resolveProjectId(page);
		await ctx.close();

		// Grant row: a licensed instance writes exactly this via
		// adminApi#createGrant; the lab has no license, so the seed goes
		// straight to the table (scope NULL = all sections).
		const ins = sql(
			`INSERT INTO oc_nw_auditor_grant ` +
			`(project_id, nc_uid, name, firm, scope_json, valid_from, valid_to, revoked_at, created_by, created_at) ` +
			`VALUES (${pid}, '${AUDITOR}', 'e2e auditor', 'e2e', NULL, '2026-01-01', '2027-12-31', NULL, '${OWNER}', NOW())`
		);
		expect(ins.status, `grant insert failed: ${ins.stderr || ins.stdout}`).toBe(0);
	});

	test.afterAll(() => {
		sql(`DELETE FROM oc_nw_auditor_grant WHERE project_id = ${pid} AND nc_uid = '${AUDITOR}'`);
		occ(['user:delete', AUDITOR]);
		occ(['user:delete', OWNER]);
	});

	test('de.auditor.project_head_state.render_translated', async ({ page }) => {
		await loginWith(page, AUDITOR, PASS);
		await page.goto(`${BASE}${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector(READY, { timeout: 45_000 });

		// The dl paints once /auditor/projects resolves — wait for it.
		const dl = page.locator('#nw-main dl.nw-dl');
		await expect(dl.first()).toBeVisible({ timeout: 45_000 });

		// The State row: dt = t('State') = 'Status', dd must be the de
		// msgstr 'aktiv' — never the raw enum 'active' (the defect).
		const stateRow = dl.locator('.nw-dl__row', { has: page.locator('dt', { hasText: 'Status' }) });
		await expect(stateRow).toHaveCount(1);
		await expect(stateRow.locator('dd')).toHaveText('aktiv');
	});
});
