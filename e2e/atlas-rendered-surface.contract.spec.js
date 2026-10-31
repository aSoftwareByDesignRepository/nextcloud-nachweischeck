// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — NachweisCheck page surfaces.
 *
 * Asserts the *rendered* truth of each app page: content lists keep markers,
 * selects vertically centre their value, icons render non-zero, form controls
 * are not centered or pinned at the core 130px width. DOM-only specs pass on
 * visually broken pages (marker resets, sunken selects, 0×0 icons) — this
 * catches them.
 *
 * Every NachweisCheck page surface is listed in SURFACES (all twelve client
 * views + the three settings underpages + home + denied shell). Marker-less
 * list designs (chip rows, picker results, card lists, nav lists) are
 * NachweisCheck design language and opt out via listAllow — never silently
 * skipped.
 *
 * Cap-gated views (finance/team/import) verify NW.boot.view before asserting
 * — a missing capability falls back to dashboard and must fail loudly
 * (fixture is expected to be project admin → all caps).
 */
const { test, expect, devices } = require('@playwright/test');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract');
const { BASE, APP, loginAs, resolveProjectId } = require('./helpers/auth-guard');

const CONTRACT_OPTS = {
	content: '#nw-main, #nw-settings',
	navExclude:
		'#app-navigation, nav, .nw-nav, .nw-settings-nav, .nw-breadcrumb, .nw-nav-footer',
	// Marker-less by design (tile/card/chip/picker design language — the app's
	// CSS sets list-style:none intentionally on these components).
	listAllow:
		'.nw-downloads, .nw-nav-footer__menu, .nw-list-cards, .nw-deadline-list, ' +
		'.nw-readiness-list, ' +
		'.nw-picker__results, .nw-picker__chips, .user-picker__list, ' +
		'.nw-breadcrumb__list, .nw-nav__list, .nw-nav__sublist, .nw-chip-list',
};

const WORKSPACE_VIEWS = [
	'dashboard', 'finplan', 'workpackages', 'time', 'receipts', 'reports',
	'deviations', 'nachweis', 'deadlines', 'finance', 'team', 'import',
];

test.describe('ATLAS_RENDERED_SURFACE_CONTRACT', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(120_000);

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
		if (ctx) {
			await ctx.close().catch(() => {});
		}
	});

	test('home project picker', async () => {
		await adminPage.goto(`${BASE}${APP}/`, { waitUntil: 'domcontentloaded' });
		await adminPage.waitForSelector('.nw-card-grid, #nw-main .nw-empty-state, #nw-main .nw-card', { timeout: 45_000 });
		await assertAtlasRenderedSurface(adminPage, CONTRACT_OPTS);
	});

	for (const view of WORKSPACE_VIEWS) {
		test(`workspace view ${view}`, async () => {
			await adminPage.goto(`${BASE}${APP}/?project=${projectId}&view=${view}`, { waitUntil: 'domcontentloaded' });
			await adminPage.waitForSelector('#nw-main .nw-card, #nw-main .nw-filter-panel, #nw-main table, #nw-main .nw-empty-state', { timeout: 45_000 });
			const renderedView = await adminPage.evaluate(
				() => (window.NW && window.NW.boot && window.NW.boot.view) || null,
			);
			expect(renderedView, `view ${view}: NW.boot.view must equal requested view (cap missing → dashboard fallback)`).toBe(view);
			await assertAtlasRenderedSurface(adminPage, CONTRACT_OPTS);
		});
	}

	for (const section of ['access', 'templates', 'notifications', 'license']) {
		test(`settings ${section}`, async () => {
			await adminPage.goto(`${BASE}${APP}/settings/${section}`, { waitUntil: 'domcontentloaded' });
			await adminPage.waitForSelector('#nw-settings .nw-card, #nw-settings .nw-empty-state', { timeout: 45_000 });
			await assertAtlasRenderedSurface(adminPage, CONTRACT_OPTS);
		});
	}

	test('denied shell (403 door)', async ({ browser }) => {
		const dctx = await browser.newContext({ ...devices['Desktop Chrome'] });
		const page = await dctx.newPage();
		try {
			await loginAs(page, 'denied');
			const resp = await page.goto(`${BASE}${APP}/settings/access`, { waitUntil: 'domcontentloaded' });
			expect(resp && resp.status(), 'denied surface must answer 403').toBe(403);
			await page.waitForSelector('.nw-denied .nw-card', { timeout: 30_000 });
			// The denied shell short-circuits in main.php before #nw-main —
			// the minimal door card sits directly under #app-content.
			await assertAtlasRenderedSurface(page, { ...CONTRACT_OPTS, content: '#app-content.nw-app' });
		} finally {
			await dctx.close().catch(() => {});
		}
	});

	// button_variant_specificity_collapse regression probe (family class):
	// NC core `button:not(.button-vue,[class^=vs__])` (0-1-1) repaints every
	// <button> pale + borderless — single-class variant rules lose. Anchors
	// are the oracle: core never repaints them, so a <button> must compute the
	// same fill/ink/border as an <a> with identical classes. Synthetic probe
	// DOM = no fixture dependence; also covers .nw-dialog (JS-built dialogs
	// append to document.body).
	test('button variants paint like anchors', async () => {
		await adminPage.goto(`${BASE}${APP}/?project=${projectId}&view=dashboard`, { waitUntil: 'domcontentloaded' });
		await adminPage.waitForSelector('#nw-main', { timeout: 45_000 });

		const report = await adminPage.evaluate(() => {
			const host = document.querySelector('#app-content-wrapper') || document.getElementById('app-content');
			if (!host) {
				return { error: 'no app host', rows: [], paleBg: '' };
			}
			const VARIANTS = ['', 'nw-btn--primary', 'nw-btn--secondary', 'nw-btn--ghost', 'nw-btn--danger'];
			const read = (el) => {
				const cs = getComputedStyle(el);
				return {
					bg: cs.backgroundColor,
					ink: cs.color,
					border: `${cs.borderTopWidth} ${cs.borderTopStyle} ${cs.borderTopColor}`,
				};
			};
			const mount = document.createElement('div');
			mount.id = 'atlas-btn-variant-probe';
			mount.style.display = 'none';
			host.appendChild(mount);
			const rows = [];
			for (const variant of VARIANTS) {
				const cls = ('nw-btn ' + variant).trim();
				const a = document.createElement('a');
				a.className = cls;
				a.href = '#';
				const b = document.createElement('button');
				b.type = 'button';
				b.className = cls;
				mount.append(a, b);
				rows.push({ scope: 'app', variant: variant || '(base)', anchor: read(a), button: read(b) });
			}
			const aDis = document.createElement('a');
			aDis.className = 'nw-btn';
			aDis.href = '#';
			aDis.setAttribute('aria-disabled', 'true');
			const bDis = document.createElement('button');
			bDis.type = 'button';
			bDis.className = 'nw-btn';
			bDis.disabled = true;
			mount.append(aDis, bDis);
			rows.push({ scope: 'app', variant: '(disabled)', anchor: read(aDis), button: read(bDis) });
			// Collapse signature: the fill core repaints <button> with.
			const pale = document.createElement('button');
			pale.style.backgroundColor = 'var(--color-primary-element-light)';
			mount.appendChild(pale);
			const paleBg = getComputedStyle(pale).backgroundColor;
			// Dialog scope — JS-built dialogs append to document.body.
			const dlg = document.createElement('dialog');
			dlg.className = 'nw-dialog';
			document.body.appendChild(dlg);
			for (const variant of ['nw-btn--primary', 'nw-btn--secondary', 'nw-btn--danger']) {
				const a = document.createElement('a');
				a.className = `nw-btn ${variant}`;
				a.href = '#';
				const b = document.createElement('button');
				b.type = 'button';
				b.className = `nw-btn ${variant}`;
				dlg.append(a, b);
				rows.push({ scope: 'dialog', variant, anchor: read(a), button: read(b) });
			}
			const out = { rows, paleBg };
			mount.remove();
			dlg.remove();
			return out;
		});

		expect(report.error).toBeUndefined();
		for (const row of report.rows) {
			const label = `${row.scope} ${row.variant}`;
			expect(row.button.bg, `${label}: <button> bg must equal <a> (got ${row.button.bg}, anchor ${row.anchor.bg})`).toBe(row.anchor.bg);
			expect(row.button.ink, `${label}: <button> ink must equal <a>`).toBe(row.anchor.ink);
			expect(row.button.border, `${label}: <button> border must equal <a>`).toBe(row.anchor.border);
		}
		for (const row of report.rows.filter((r) => r.variant !== '(base)' && r.variant !== 'nw-btn--ghost')) {
			expect(row.button.bg, `${row.scope} ${row.variant}: still paints core --color-primary-element-light`).not.toBe(report.paleBg);
		}
	});
});
