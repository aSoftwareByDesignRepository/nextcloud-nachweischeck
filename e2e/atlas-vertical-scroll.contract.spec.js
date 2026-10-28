// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall NachweisCheck surfaces must scroll to
 * the end. Guards CSS Overflow L3 unpaired overflow-x:clip/hidden truncating
 * tall pages (finplan position grids, settings cards).
 */
const { test, devices } = require('@playwright/test');
const { assertAtlasVerticalScrollReachable } = require('../../_shared/e2e/atlas-vertical-scroll-contract');
const { BASE, APP, loginAs, resolveProjectId } = require('./helpers/auth-guard');

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
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

	test('finplan workspace view scrolls to the last card', async () => {
		await adminPage.setViewportSize({ width: 1280, height: 640 });
		await adminPage.goto(`${BASE}${APP}/?project=${projectId}&view=finplan`, { waitUntil: 'domcontentloaded' });
		await adminPage.waitForSelector('#nw-main .nw-card', { timeout: 45_000 });

		await assertAtlasVerticalScrollReachable(adminPage, {
			scrollport: '#app-content',
			target: '#nw-main > :last-child',
			bottomSlopPx: 12,
		});
	});

	test('settings access page stays reachable at phone height', async () => {
		await adminPage.setViewportSize({ width: 390, height: 667 });
		await adminPage.goto(`${BASE}${APP}/settings/access`, { waitUntil: 'domcontentloaded' });
		await adminPage.waitForSelector('#nw-settings .nw-card, #nw-settings .nw-empty-state', { timeout: 45_000 });

		await assertAtlasVerticalScrollReachable(adminPage, {
			scrollport: '#app-content',
			target: '#nw-settings > :last-child',
			bottomSlopPx: 16,
		});
	});
});
