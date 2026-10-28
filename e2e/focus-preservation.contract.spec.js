// @ts-check
/**
 * FOCUS_PRESERVATION_CONTRACT — NachweisCheck permanent harness.
 *
 * Standing guard for the shared focus-rescue observer
 * (apps/_shared/focus-preservation, synced to js/common/focus-preservation.js
 * and installed by app.js with prefix 'nw'). Probes use DOM surgery
 * (clone -> wipe -> re-add) so they do not depend on seeded rows: the
 * observer reacts to mutation records, so an in-page rebuild exercises the
 * exact machinery a real re-render would.
 *
 * Class refs: focus_loss_on_rebuild, focus_steal_after_leave,
 * interim_decoy_ends_rescue, landmark_uniqueness (COMPANION-DESIGN-SYSTEM §8).
 */
const { test, devices } = require('@playwright/test');
const {
	assertRebuildPreservesFocus,
	assertLeaveThenWipeDoesNotSteal,
	assertInterimDecoyDoesNotEndRescue,
	assertUniqueLandmarks,
} = require('../../_shared/e2e/focus-preservation-contract.js');
const { BASE, APP, loginAs, resolveProjectId } = require('./helpers/auth-guard');

// Every selector-list arm needs the scope prefix — `#nw-main a, input` would
// match `input` document-wide (body-level skip links / hidden state inputs
// were picked in sibling apps). a[href] not bare [href] (<link rel=icon>);
// input[type=hidden] excluded (state carriers are not focus targets).
const FOCUSABLE = ['button', 'a[href]', 'input:not([type="hidden"])', 'select', 'textarea', 'summary', '[tabindex]:not([tabindex="-1"])']
	.map((s) => `#nw-main ${s}`).join(', ');
const TARGET = FOCUSABLE;

// Inner list/group container holding the focused element — never the app
// root itself (detaching #app-content exercises the stale-arm path, which
// is a different class).
const SURGERY_CONTAINER = `a.closest('ul, ol, tbody, [role="list"], [role="group"], .nw-card, .nw-page-stack, form') || a.parentElement`;

const REBUILD = `(() => {
	const a = document.activeElement;
	const container = ${SURGERY_CONTAINER};
	const clone = container.cloneNode(true);
	container.replaceWith(clone);
	return true;
})()`;

test.describe('FOCUS_PRESERVATION_CONTRACT', () => {
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

	test.beforeEach(async () => {
		await adminPage.setViewportSize({ width: 1280, height: 900 });
		await adminPage.goto(`${BASE}${APP}/?project=${projectId}&view=dashboard`, { waitUntil: 'domcontentloaded' });
		await adminPage.waitForSelector('#nw-main .nw-card, #nw-main .nw-empty-state', { timeout: 45_000 });
		await adminPage.waitForTimeout(1200);
	});

	test('rebuild under focus rescues to rebuilt equivalent (never body)', async () => {
		await assertRebuildPreservesFocus(adminPage, {
			target: TARGET,
			rebuild: () => adminPage.evaluate(REBUILD),
		});
	});

	test('genuine leave then wipe does not steal focus back', async () => {
		await assertLeaveThenWipeDoesNotSteal(adminPage, {
			target: TARGET,
			leave: async () => {
				// Trusted gesture on neutral in-app chrome: the page-title h1
				// is never interactive and never navigates. A bare coordinate
				// click can land on NC header chrome — the navigation destroys
				// the eval context mid-leg.
				await adminPage.locator('#nw-page-title').click();
				await adminPage.waitForTimeout(150);
			},
			rebuild: () => adminPage.evaluate(REBUILD),
		});
	});

	test('interim decoy inside wiped hood does not end the rescue', async () => {
		const sig = await adminPage.evaluate(`(() => {
			const el = document.querySelector(${JSON.stringify(TARGET)});
			if (!el) return null;
			el.focus();
			return el.getAttribute('aria-label') || (el.textContent || '').trim().slice(0, 80);
		})()`);
		test.skip(!sig, 'no focusable target in main content');

		await assertInterimDecoyDoesNotEndRescue(adminPage, {
			target: TARGET,
			wipe: () => adminPage.evaluate(`(() => {
				const a = document.activeElement;
				const container = ${SURGERY_CONTAINER};
				window.__probeClone = container.cloneNode(true);
				window.__probeContainer = container;
				container.textContent = '';
				return true;
			})()`),
			paintInterim: () => adminPage.evaluate(`(() => {
				const decoy = document.createElement('button');
				decoy.textContent = 'Loading…';
				decoy.setAttribute('aria-label', 'probe decoy');
				window.__probeContainer.appendChild(decoy);
				return true;
			})()`),
			rebuild: () => adminPage.evaluate(`(() => {
				window.__probeContainer.replaceChildren(...window.__probeClone.childNodes);
				return true;
			})()`),
			expectSignature: sig,
		});
	});

	test('landmark uniqueness: no in-app banner, no top-level <footer>', async () => {
		await assertUniqueLandmarks(adminPage);
	});
});
