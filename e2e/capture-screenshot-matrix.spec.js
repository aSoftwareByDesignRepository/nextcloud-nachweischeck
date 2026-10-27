// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/nachweischeck/craft/web/. The companion
 * gate .cursor/atlas-farm-v3/scripts/check-screenshot-matrix.py verifies
 * completeness — a declared cell without a real PNG fails the craft stage.
 *
 * States:
 *   populated — a real project renders (first project of the capture user;
 *               if the instance has none, a minimal ANBest-P project is
 *               seeded through the real API — honest data, no stubs).
 *   current   — state-invariant chrome (settings underpages, denied).
 *
 * denied: non-app-admin user hits the admin-only settings surface; the
 *   door answers with the real 403 denied shell (no config mutation —
 *   the app is in default open mode).
 *
 * Serial: user theme is shared mutable state on the one dev instance;
 * siblings must not interleave.
 *
 * Credentials: E2E_USER/E2E_PASS (default nw_ds_admin fixture) and
 * E2E_DENIED_USER/E2E_DENIED_PASS (default nw_ds_denied fixture).
 *
 * Run (from the app dir):
 *   npx playwright test -c e2e/playwright.screenshot-matrix.config.js
 */
const { test, expect, devices } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { setUserTheme, resetUserTheme } = require('./helpers/theming');

const BASE = (process.env.NACHWEISCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
const APP = '/index.php/apps/nachweischeck';
const MANIFEST = JSON.parse(fs.readFileSync(path.join(__dirname, 'screenshot-matrix.json'), 'utf8'));
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/nachweischeck', MANIFEST.outDir);

const USERS = {
	admin: {
		username: process.env.E2E_USER || process.env.NACHWEISCHECK_E2E_USER || 'nw_ds_admin',
		password: process.env.E2E_PASS || process.env.NACHWEISCHECK_E2E_PASS || process.env.DS_PROBE_PASS || 'DsProbe!nw2026',
	},
	denied: {
		username: process.env.E2E_DENIED_USER || process.env.NACHWEISCHECK_E2E_DENIED_USER || 'nw_ds_denied',
		password: process.env.E2E_DENIED_PASS || process.env.NACHWEISCHECK_E2E_DENIED_PASS || process.env.DS_PROBE_PASS || 'DsProbe!nw2026',
	},
};

/** Selector that proves the intended state actually rendered. */
const READY = {
	'home': { populated: '.nw-card-grid, #nw-main .nw-empty-state' },
	'dashboard': { populated: '.nw-stat-strip, #nw-main .nw-card' },
	'finplan': { populated: '#nw-main .nw-card, #nw-main .nw-filter-bar' },
	'receipts': { populated: '#nw-main .nw-card' },
	'nachweis': { populated: '#nw-main .nw-card, .nw-readiness-item' },
	'deadlines': { populated: '#nw-main .nw-card' },
	'settings-access': { current: '#nw-settings .nw-card' },
	'settings-templates': { current: '#nw-settings .nw-card' },
	'settings-notifications': { current: '#nw-settings .nw-card' },
	'denied': { current: '.nw-empty-state.nw-card' },
};

const capturedFiles = [];

/**
 * Form-login a fresh page for the given role. NC's login JS issues a second
 * client-side redirect after submit, so success is proven by navigating to
 * the app — an authenticated session must not bounce back to /login.
 * @param {import('@playwright/test').Page} page
 * @param {'admin'|'denied'} role
 */
async function loginAs(page, role) {
	const creds = USERS[role];
	for (let attempt = 1; attempt <= 3; attempt++) {
		await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded', timeout: 45000 });
		const html = await page.content();
		if (/maintenance mode|update is in progress/i.test(html)) {
			throw new Error('NC in maintenance/upgrade mode');
		}
		await page.locator('#user, input[name="user"]').first().fill(creds.username, { force: true });
		await page.locator('#password, input[name="password"]').first().fill(creds.password, { force: true });
		await page.locator('button[type="submit"], input[type="submit"], button.login-button').first().click();
		await page.waitForTimeout(2500);
		await page.goto(`${BASE}${APP}/`, { waitUntil: 'domcontentloaded', timeout: 45000 });
		if (!page.url().includes('/login')) {
			return;
		}
	}
	throw new Error(`login failed for ${creds.username} — bounced back to /login`);
}

/** Session-bound JSON call into the app's own API. */
async function nwApi(page, method, route, body) {
	return page.evaluate(async ({ method: m, route: r, payload }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || '';
		const res = await fetch(`/index.php/apps/nachweischeck/api/v1${r}`, {
			method: m,
			credentials: 'same-origin',
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			body: payload === undefined ? undefined : JSON.stringify(payload),
		});
		let json = null;
		try { json = await res.json(); } catch { /* non-JSON body */ }
		return { status: res.status, json };
	}, { method, route, payload: body });
}

/**
 * First project id of the capture user; seeds a minimal ANBest-P project
 * through the real API when the instance has none, so "populated" cells are
 * honest renders rather than empty-state stand-ins.
 * @param {import('@playwright/test').Page} page
 */
async function resolveProjectId(page) {
	const list = await nwApi(page, 'GET', '/projects');
	const existing = (list.json && list.json.data) || [];
	if (existing.length > 0) {
		return existing[0].id;
	}
	const created = await nwApi(page, 'POST', '/projects', {
		name: 'DS-Matrix Demo',
		funder_name: 'BMBF',
		funder_ref: 'DS-MATRIX-1',
		bew_start: '2026-01-01',
		bew_end: '2027-06-30',
		template_key: 'anbest-p',
		financing_mode: 'ausgaben',
	});
	const pid = created.json && created.json.data && created.json.data.id;
	if (!pid) {
		throw new Error(`seed project failed: ${JSON.stringify(created.json)}`);
	}
	const vers = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions`);
	const vid = vers.json && vers.json.data && vers.json.data[0] && vers.json.data[0].id;
	if (!vid) {
		throw new Error('seed: no finplan version');
	}
	await nwApi(page, 'POST', `/projects/${pid}/finplan/versions/${vid}/activate`, { base_version: 0 });
	const ap = await nwApi(page, 'POST', `/projects/${pid}/workpackages`, {
		code: 'AP1', title: 'Konzept', start_date: '2026-01-01', end_date: '2026-06-30',
	});
	const apId = ap.json && ap.json.data && ap.json.data.id;
	const pos = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions/${vid}/positions`);
	const posId = pos.json && pos.json.data && pos.json.data[0] && pos.json.data[0].id;
	if (apId && posId) {
		await nwApi(page, 'PUT', `/projects/${pid}/workpackages/${apId}/positions`, { position_ids: [posId] });
		await nwApi(page, 'POST', `/projects/${pid}/activate`, {});
	}
	return pid;
}

async function shot(page, name, meta) {
	fs.mkdirSync(OUT, { recursive: true });
	const file = path.join(OUT, name);
	await page.screenshot({ path: file, fullPage: false });
	const size = fs.statSync(file).size;
	expect(size, `${name} implausibly small (${size}B) — blank/error frame`).toBeGreaterThan(4096);
	capturedFiles.push({ file: name, ...meta });
}

test.describe('NachweisCheck screenshot matrix (ATLAS §VISUAL)', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	/** @type {import('@playwright/test').BrowserContext} */
	let ctx;
	/** @type {import('@playwright/test').Page} */
	let adminPage;
	/** @type {number} */
	let projectId;

	test.beforeAll(async ({ browser }) => {
		ctx = await browser.newContext({ ...devices['Desktop Chrome'] });
		adminPage = await ctx.newPage();
		await loginAs(adminPage, 'admin');
		projectId = await resolveProjectId(adminPage);
	});

	test.afterAll(async () => {
		try {
			if (adminPage && !adminPage.isClosed()) {
				await resetUserTheme(adminPage).catch(() => {});
			}
		} finally {
			fs.mkdirSync(OUT, { recursive: true });
			fs.writeFileSync(
				path.join(OUT, '_screenshot-matrix-manifest.json'),
				JSON.stringify(
					{ lane: 'ds_chrome', stage: 'craft', spec: 'capture-screenshot-matrix', shots: capturedFiles },
					null,
					2,
				),
			);
			if (ctx) {
				await ctx.close().catch(() => {});
			}
		}
	});

	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states || ['current']) {
			test(`${surface.id} ${state} — viewport sweep`, async () => {
				const denied = surface.user === 'denied';
				let page = adminPage;
				if (denied) {
					const dctx = await ctx.browser().newContext({ ...devices['Desktop Chrome'] });
					page = await dctx.newPage();
					await loginAs(page, 'denied');
				}
				try {
					const url = `${APP}${surface.path}`.replace('{PID}', String(projectId));
					const ready = (READY[surface.id] || {})[state] || '#nw-main';
					for (const vp of MANIFEST.viewports) {
						await page.setViewportSize({ width: vp.width, height: vp.height });
						const resp = await page.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' });
						if (denied) {
							// Denied surfaces must fail closed at HTTP level, not 200-theatre.
							expect(resp && resp.status(), 'denied surface must answer 403').toBe(403);
						}
						// The dev instance serves concurrent farm lanes — a slow
						// API fetch gets one reload grace before the cell fails.
						try {
							await page.waitForSelector(ready, { timeout: 45_000 });
						} catch {
							await page.reload({ waitUntil: 'domcontentloaded' });
							await page.waitForSelector(ready, { timeout: 45_000 });
						}
						await shot(
							page,
							`${MANIFEST.prefix}-${surface.id}-${state}-${vp.name}.png`,
							{ surface: surface.id, state, viewport: vp.name },
						);
					}
				} finally {
					if (denied) {
						await page.context().close().catch(() => {});
					}
				}
			});
		}
	}

	test('dark cells — desktop', async () => {
		await adminPage.setViewportSize({ width: 1280, height: 800 });
		await setUserTheme(adminPage, 'dark');
		try {
			for (const surface of MANIFEST.surfaces.filter((s) => s.dark)) {
				const firstState = (surface.states || ['current'])[0];
				const url = `${APP}${surface.path}`.replace('{PID}', String(projectId));
				const ready = (READY[surface.id] || {})[firstState] || '#nw-main';
				await adminPage.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' });
				await adminPage.waitForSelector(ready, { timeout: 30_000 });
				await shot(
					adminPage,
					`${MANIFEST.prefix}-${surface.id}-${firstState}-desktop-dark.png`,
					{ surface: surface.id, state: firstState, viewport: 'desktop', theme: 'dark' },
				);
			}
		} finally {
			await resetUserTheme(adminPage);
		}
	});
});
