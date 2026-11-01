// @ts-check
/**
 * ATLAS chrome-literal l10n leg — intake 20261101T173222Z
 * (raw_i18n_key_rendered): PageController shipped raw English literals
 * into the rendered chrome params — pageHelp ('Select a project on the
 * left…'), roleLabel ('App admin'/'Member'), and the settings pageTitle
 * ('NachweisCheck settings') painted English on every locale.
 *
 * Legs (de catalog):
 *   member index  — pageHelp 'Wählen Sie links…' + roleLabel 'Mitglied'
 *                   (brand badge AND scope-strip badge share the param)
 *   admin  index  — roleLabel 'App-Verwaltung'
 *   admin  /settings/access — h1 = section label 'Zugriff' + badge
 *
 * Fixture isolation: disposable `nwt-chr-*` users created via occ for
 * this spec and deleted in afterAll — the shared nw_* fixture users and
 * their locale pins are never touched (sibling lanes hold them mid-run).
 * core.lang=de on each user drives t() against the de catalog end-to-end.
 */
const { test, expect } = require('@playwright/test');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const { BASE, APP, nwApi } = require('./helpers/auth-guard');

const COMPOSE_ROOT = path.join(__dirname, '..', '..', '..');
const SFX = `${Date.now() % 1000000}-${Math.floor(Math.random() * 1000)}`;
const MEMBER = `nwt-mem-${SFX}`.replace(/[^a-z0-9._@-]/gi, '');
const ADMIN = `nwt-adm-${SFX}`.replace(/[^a-z0-9._@-]/gi, '');
const PASS = `NwTmp!${SFX}`;
const READY = '#nw-main .nw-card, #nw-main .nw-empty-state, #nw-main table, #nw-main form, #nw-main .nw-callout, #nw-settings .nw-card, #nw-settings .nw-empty-state';

function occ(args) {
	return spawnSync(
		'docker',
		['compose', 'exec', '-u', 'www-data', '-T', 'nextcloud', 'php', 'occ', ...args],
		{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
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

test.describe('CHROME_LITERAL_L10N', () => {
	test.describe.configure({ mode: 'serial' });

	test.beforeAll(() => {
		addUser(MEMBER, 'de');
		addUser(ADMIN, 'de');
		const grp = occ(['group:adduser', 'admin', ADMIN]);
		expect(grp.status, `group:adduser admin failed: ${grp.stderr || grp.stdout}`).toBe(0);
	});

	test.afterAll(() => {
		occ(['user:delete', MEMBER]);
		occ(['user:delete', ADMIN]);
	});

	test('de.member.index.chrome_literals.render_translated', async ({ page }) => {
		await loginWith(page, MEMBER, PASS);
		await page.goto(`${BASE}${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector(READY, { timeout: 45_000 });

		// pageHelp — the defect's literal: must resolve the de msgstr,
		// never the raw 'Select a project on the left…' English.
		await expect(page.locator('.nw-page-header__lead')).toHaveText(
			'Wählen Sie links ein Projekt oder legen Sie Ihr erstes Förderprojekt an.',
		);

		// roleLabel — painted twice (nav brand badge + scope-strip badge);
		// both must read 'Mitglied', never raw 'Member'.
		await expect(page.locator('.nw-scope-strip__badge')).toHaveText('Mitglied');
		await expect(page.locator('.nw-brand .nw-badge')).toHaveText('Mitglied');
	});

	test('de.member.nav_project_state_hint.render_translated', async ({ page }) => {
		// Sibling site swept in the same intake: Chrome::apply bound the
		// sidebar project-state hint to the raw enum — de nav showed
		// 'draft'/'active' next to every project name.
		await loginWith(page, MEMBER, PASS);
		await page.goto(`${BASE}${APP}/`, { waitUntil: 'domcontentloaded' });
		const created = await nwApi(page, 'POST', '/projects', {
			name: `NWT Hint ${SFX}`,
			funder_name: 'BMBF',
			funder_ref: `NWT-HINT-${SFX}`,
			bew_start: '2026-01-01',
			bew_end: '2026-12-31',
			template_key: 'anbest-p',
			financing_mode: 'ausgaben',
		});
		const pid = created.json && created.json.data && created.json.data.id;
		expect(pid, `seed project failed: ${JSON.stringify(created.json)}`).toBeTruthy();
		try {
			await page.goto(`${BASE}${APP}/?project=${pid}`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector(READY, { timeout: 45_000 });
			const item = page.locator('.nw-nav__item', { has: page.locator(`a[href*="project=${pid}"]`) }).first();
			// fresh project is 'draft' — de catalog resolves it to 'Entwurf'.
			await expect(item.locator('.nw-nav__hint')).toHaveText('Entwurf');
		} finally {
			// draft-only projects are deletable — teardown leaves no orphans.
			await nwApi(page, 'DELETE', `/projects/${pid}`);
		}
	});

	test('de.admin.index_role_and_settings_title.render_translated', async ({ page }) => {
		await loginWith(page, ADMIN, PASS);
		await page.goto(`${BASE}${APP}/`, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector(READY, { timeout: 45_000 });
		await expect(page.locator('.nw-scope-strip__badge')).toHaveText('App-Verwaltung');

		// settings chrome: h1 must carry the translated section label
		// ('Zugriff'), never raw 'NachweisCheck settings'.
		await page.goto(`${BASE}${APP}/settings/access`, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector(READY, { timeout: 45_000 });
		await expect(page.locator('#nw-page-title')).toHaveText('Zugriff');
		await expect(page.locator('.nw-scope-strip__badge')).toHaveText('App-Verwaltung');
	});
});
