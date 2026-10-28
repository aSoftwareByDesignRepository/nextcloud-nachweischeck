// @ts-check
/**
 * ATLAS_MOBILE_NAV_CONTRACT — in-page Menu toggle must open the nav drawer at
 * phone width (NachweisCheck DS choice: in-page #nw-nav-toggle owns the
 * drawer; core #app-navigation-toggle is aria-hidden'd by js/common/mobile-nav.js).
 */
const { test, devices } = require('@playwright/test');
const { assertAtlasMobileNav } = require('../../_shared/e2e/atlas-mobile-nav-contract');
const { BASE, APP, loginAs } = require('./helpers/auth-guard');

test.describe('ATLAS_MOBILE_NAV_CONTRACT', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(120_000);

	test('in-page Menu toggle opens nav drawer at phone width', async ({ browser }) => {
		const ctx = await browser.newContext({ ...devices['Desktop Chrome'] });
		const page = await ctx.newPage();
		try {
			await loginAs(page, 'admin');
			await page.setViewportSize({ width: 375, height: 812 });
			await page.goto(`${BASE}${APP}/`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#app-navigation', { timeout: 30_000 });
			await page.waitForSelector('#nw-nav-toggle, [data-nw-nav-toggle]', { timeout: 30_000 });
			const toggle = page.locator('#nw-nav-toggle, [data-nw-nav-toggle]').first();
			await assertAtlasMobileNav(page, {
				toggle,
				nav: page.locator('#app-navigation'),
				openClass: /nw-nav--open/,
			});
		} finally {
			await ctx.close().catch(() => {});
		}
	});
});
