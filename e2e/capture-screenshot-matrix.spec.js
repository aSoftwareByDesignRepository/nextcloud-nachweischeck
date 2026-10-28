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
const { BASE, APP, loginAs, resolveProjectId } = require('./helpers/auth-guard');

const MANIFEST = JSON.parse(fs.readFileSync(path.join(__dirname, 'screenshot-matrix.json'), 'utf8'));
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/nachweischeck', MANIFEST.outDir);

/** Selector that proves the intended state actually rendered. */
const READY = {
	'home': { populated: '.nw-card-grid, #nw-main .nw-empty-state' },
	'dashboard': { populated: '.nw-stat-strip, #nw-main .nw-card' },
	'finplan': { populated: '#nw-main .nw-card, #nw-main .nw-filter-bar' },
	'workpackages': { populated: '#nw-main .nw-card' },
	'time': { populated: '#nw-main .nw-filter-panel, #nw-main .nw-card' },
	'receipts': { populated: '#nw-main .nw-card' },
	'reports': { populated: '#nw-main .nw-card' },
	'deviations': { populated: '#nw-main .nw-card' },
	'nachweis': { populated: '#nw-main .nw-card, .nw-readiness-item' },
	'deadlines': { populated: '#nw-main .nw-card' },
	'finance': { populated: '#nw-main .nw-card, #nw-main .nw-dl' },
	'team': { populated: '#nw-main .nw-card' },
	'import': { populated: '#nw-main .nw-card, #nw-main .nw-callout' },
	'settings-access': { current: '#nw-settings .nw-card' },
	'settings-templates': { current: '#nw-settings .nw-card' },
	'settings-notifications': { current: '#nw-settings .nw-card' },
	'denied': { current: '.nw-denied .nw-card' },
};

const capturedFiles = [];

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
						// mislabeled_craft guard: cap-gated/unknown views fall back
						// to dashboard client-side — a PNG named "finance" that
						// actually shows the dashboard is worse than no PNG.
						if (surface.view) {
							const renderedView = await page.evaluate(
								() => (window.NW && window.NW.boot && window.NW.boot.view) || null,
							);
							expect(
								renderedView,
								`${surface.id}: NW.boot.view must be '${surface.view}' (cap missing → dashboard fallback, mislabeled craft)`,
							).toBe(surface.view);
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
				if (surface.view) {
					const renderedView = await adminPage.evaluate(
						() => (window.NW && window.NW.boot && window.NW.boot.view) || null,
					);
					expect(renderedView).toBe(surface.view);
				}
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
