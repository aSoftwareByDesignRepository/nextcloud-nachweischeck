// @ts-check
/**
 * ATLAS post-seal delta craft — seed nachweischeck-intake-20261102T232350Z.
 *
 * Visual recapture for the five post-seal product commits on sealed 558d96b:
 *   e4a64e0 / 260e635 — dialog-scoped .nw-edit-grid/--splits + .nw-split-sum +
 *     .nw-fieldset re-scoped via :is(#app-content.nw-app, .nw-dialog) so the
 *     rules reach the <dialog> top layer (previously invisible/unstyled).
 *   7a8de62 — Projects-first sidebar order restored.
 *   1f70620 — filter-panel l10n coverage + hover-first row-action clicks.
 *   c90b044 — OC.generateUrl routing (functional, covered by web_api lanes).
 *
 * Every leg pairs an executable assertion with its PNG so the craft is a
 * proof, not a picture: computed-style checks prove the re-scoped rules
 * actually win inside dialog.nw-dialog, the sidebar leg asserts DOM order,
 * and the hover leg asserts row actions become visible on :hover.
 *
 * Output: .cursor/atlas-farm-v3/artifacts/nachweischeck/craft/web/nw-delta-*.
 * Locale-safe: structural selectors + fixture MARK text only; the de leg
 * compares rendered text against l10n/de.json on disk, never a hardcoded
 * translation string.
 */
const { test, expect, devices } = require('@playwright/test');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { BASE, APP, USERS, loginAs, nwApi } = require('./helpers/auth-guard');

const COMPOSE_ROOT = path.join(__dirname, '..', '..', '..');
const ARTIFACTS = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/nachweischeck');
const OUT = path.join(ARTIFACTS, 'craft', 'web');
const MARK = `nw-delta-${Date.now() % 1000000}-${Math.floor(Math.random() * 1000)}`;
const DIALOG = 'dialog.nw-dialog[open]';
const READY = '#nw-main .nw-card, #nw-main .nw-empty-state, #nw-main table, #nw-main form';

const capturedFiles = [];

function occUserSetting(uid, args) {
	return spawnSync(
		'docker',
		['compose', 'exec', '-u', 'www-data', '-T', 'nextcloud', 'php', 'occ',
			'user:setting', uid, ...args],
		{ cwd: COMPOSE_ROOT, encoding: 'utf8', timeout: 60_000 },
	);
}

async function shot(page, name, meta) {
	fs.mkdirSync(OUT, { recursive: true });
	const file = path.join(OUT, name);
	await page.screenshot({ path: file, fullPage: false });
	const size = fs.statSync(file).size;
	expect(size, `${name} implausibly small (${size}B) — blank/error frame`).toBeGreaterThan(4096);
	capturedFiles.push({ file: name, ...meta });
}

async function goto(page, route) {
	await page.goto(`${BASE}${APP}${route}`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector(READY, { timeout: 45_000 });
}

test.describe('ATLAS_DELTA_CRAFT', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	let ctx;
	/** @type {import('@playwright/test').Page} */
	let page;
	let pid, posId;

	test.beforeAll(async ({ browser }) => {
		ctx = await browser.newContext({ ...devices['Desktop Chrome'] });
		page = await ctx.newPage();
		await loginAs(page, 'admin');
		// Own MARK project + receipt: guarantees an active project with an
		// AP-bound position and a receipt row whose Splits/Edit actions exist.
		const created = await nwApi(page, 'POST', '/projects', {
			name: `${MARK} project`, funder_name: 'BMBF', funder_ref: MARK,
			bew_start: '2026-01-01', bew_end: '2027-06-30',
			template_key: 'anbest-p', financing_mode: 'ausgaben',
		});
		pid = created.json && created.json.data && created.json.data.id;
		expect(pid, `project seed failed: ${JSON.stringify(created.json)}`).toBeTruthy();
		const vers = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions`);
		const vid = vers.json.data[0].id;
		await nwApi(page, 'POST', `/projects/${pid}/finplan/versions/${vid}/activate`, { base_version: 0 });
		const ap = await nwApi(page, 'POST', `/projects/${pid}/workpackages`, {
			code: 'AP1', title: `${MARK} ap`, start_date: '2026-01-01', end_date: '2026-06-30',
		});
		const apId = ap.json.data.id;
		const pos = await nwApi(page, 'GET', `/projects/${pid}/finplan/versions/${vid}/positions`);
		posId = pos.json.data[0].id;
		await nwApi(page, 'PUT', `/projects/${pid}/workpackages/${apId}/positions`, { position_ids: [posId] });
		const act = await nwApi(page, 'POST', `/projects/${pid}/activate`, {});
		expect(act.status, `activate failed: ${JSON.stringify(act.json)}`).toBe(200);
		const rec = await nwApi(page, 'POST', `/projects/${pid}/receipts`, {
			vendor: `${MARK}-vendor`, invoice_no: 'D-1', invoice_date: '2026-02-01',
			currency: 'EUR', total_minor: 1000, tax_minor: 0, purpose: 'x',
			splits: [{ position_id: posId, amount_minor: 1000, note: `${MARK} split` }],
		});
		expect(rec.status, `receipt seed failed: ${JSON.stringify(rec.json)}`).toBe(201);
	});

	test.afterAll(async () => {
		try {
			occUserSetting(USERS.admin.username, ['core', 'lang', '--delete']);
			if (page && !page.isClosed()) { await page.close().catch(() => {}); }
		} finally {
			fs.mkdirSync(OUT, { recursive: true });
			fs.writeFileSync(
				path.join(OUT, '_delta-craft-manifest.json'),
				JSON.stringify(
					{ lane: 'ds_chrome', stage: 'craft', spec: 'atlas-delta-craft', seed: 'nachweischeck-intake-20261102T232350Z', shots: capturedFiles },
					null, 2,
				),
			);
			if (ctx) { await ctx.close().catch(() => {}); }
		}
	});

	test('sidebar: Projects group precedes view links', async () => {
		await goto(page, `/?project=${pid}&view=dashboard`);
		const order = await page.evaluate(() => {
			const links = [...document.querySelectorAll('#app-navigation .nw-nav__link[href]')]
				.map((a) => a.getAttribute('href') || '');
			const projIdx = links.findIndex((h) => /[?&]project=\d+&view=dashboard/.test(h) || /[?&]new=1/.test(h));
			const viewIdx = links.findIndex((h) => /[?&]project=\d+&view=(finplan|workpackages|time|receipts)/.test(h));
			return { projIdx, viewIdx, total: links.length };
		});
		expect(order.projIdx, 'no project nav item rendered').toBeGreaterThanOrEqual(0);
		expect(order.viewIdx, 'no view link rendered').toBeGreaterThan(order.projIdx);
		await shot(page, 'nw-delta-sidebar-projects-first-desktop.png', { surface: 'dashboard', delta: '7a8de62' });
	});

	test('time view: filter panel renders (en) + de l10n', async () => {
		await goto(page, `/?project=${pid}&view=time`);
		await expect(page.locator('.nw-filter-panel')).toHaveCount(1, { timeout: 15_000 });
		await expect(page.locator('.nw-filter-panel__head')).toHaveCount(1);
		await shot(page, 'nw-delta-time-filter-panel-desktop.png', { surface: 'time', delta: '1f70620' });

		// de leg: force the fixture user's NC language, assert the panel copy
		// resolves from the de catalog (read off disk — never hardcoded).
		const deCatalog = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'l10n', 'de.json'), 'utf8'));
		const expectedIntro = deCatalog.translations['Narrow the list to a period, then click Apply.'];
		const expectedHead = deCatalog.translations['Filter'];
		expect(expectedIntro, 'de catalog missing filter-panel intro msgid').toBeTruthy();
		const set = occUserSetting(USERS.admin.username, ['core', 'lang', 'de']);
		expect(set.status, `occ lang=de failed: ${set.stderr || set.stdout}`).toBe(0);
		try {
			await goto(page, `/?project=${pid}&view=time`);
			await expect(page.locator('.nw-filter-panel')).toHaveCount(1, { timeout: 15_000 });
			await expect(page.locator('#nw-time-filter-title')).toHaveText(expectedHead);
			await expect(page.locator('.nw-filter-panel__intro')).toHaveText(expectedIntro);
			const aria = await page.locator('.nw-filter-panel__form').getAttribute('aria-label');
			expect(aria, 'filter form aria-label untranslated').toBe(deCatalog.translations['Filter time entries']);
			await shot(page, 'nw-delta-time-filter-panel-de-desktop.png', { surface: 'time', locale: 'de', delta: '1f70620' });
		} finally {
			occUserSetting(USERS.admin.username, ['core', 'lang', '--delete']);
		}
	});

	test('receipts: flag pills never clip at the wrap edge (nw-vis-receipts-flags-pill-clipped)', async () => {
		await goto(page, `/?project=${pid}&view=receipts`);
		const row = page.locator('#nw-main table tbody tr', { hasText: `${MARK}-vendor` });
		await expect(row).toHaveCount(1, { timeout: 15_000 });
		// The seeded receipt carries ≥2 flag pills (state + "no attachment").
		await expect(row.locator('.nw-badge-row .nw-badge')).toHaveCount(2);
		// Every flag badge's right edge must land inside the table wrap —
		// pre-fix min-width:max-content pushed the last pill past the clip
		// boundary and it rendered mid-glyph at 1280px.
		const clip = await page.evaluate(() => {
			const wrap = document.querySelector('#nw-main .nw-table-wrap');
			const wr = wrap.getBoundingClientRect();
			const out = [];
			wrap.querySelectorAll('.nw-badge-row').forEach((badgeRow) => {
				badgeRow.querySelectorAll('.nw-badge').forEach((b) => {
					const r = b.getBoundingClientRect();
					if (r.right > wr.right - 1 || r.left < wr.left - 1) {
						out.push({ text: b.textContent, right: r.right, wrapRight: wr.right });
					}
				});
			});
			return { out, scrollLeak: wrap.scrollWidth - wrap.clientWidth };
		});
		expect(clip.out, `flag pills clipped at wrap edge: ${JSON.stringify(clip.out)}`).toEqual([]);
		expect(clip.scrollLeak, 'receipts table still overflows the wrap at 1280px').toBeLessThanOrEqual(1);
		await shot(page, 'nw-receipts-populated-desktop.png', { surface: 'receipts', delta: 'fix-flags-pill-clip' });

		const actions = row.locator('.nw-table-actions button');
		await expect(actions.first()).toBeAttached();
		await row.hover();
		await expect(actions.first()).toBeVisible({ timeout: 10_000 });
		await shot(page, 'nw-delta-row-actions-hover-desktop.png', { surface: 'receipts', delta: '1f70620' });
	});

	test('File receipt dialog: splits edit-grid + fieldset + sum reach top layer', async () => {
		await goto(page, `/?project=${pid}&view=receipts`);
		await page.locator('#nw-page-actions .nw-btn--primary').first().click();
		const dlg = page.locator(DIALOG);
		await expect(dlg).toHaveCount(1, { timeout: 10_000 });
		await expect(dlg.locator('.nw-edit-grid--splits')).toHaveCount(1);
		await expect(dlg.locator('.nw-fieldset')).toHaveCount(1);
		await expect(dlg.locator('.nw-split-sum')).toHaveCount(1);

		// The whole point of e4a64e0/260e635: inside the top-layer dialog the
		// re-scoped rules must actually apply — grid display, non-zero fieldset
		// border, flexed sum line. Pre-fix these computed values were the
		// unstyled defaults (block / 0px border).
		const styles = await dlg.evaluate((d) => {
			const grid = d.querySelector('.nw-edit-grid--splits');
			const fieldset = d.querySelector('.nw-fieldset');
			const legend = d.querySelector('.nw-fieldset__legend');
			const sum = d.querySelector('.nw-split-sum');
			const cs = (el) => el ? getComputedStyle(el) : null;
			return {
				gridDisplay: cs(grid) && cs(grid).display,
				gridCols: cs(grid) && cs(grid).gridTemplateColumns,
				fieldsetBorder: cs(fieldset) && cs(fieldset).borderTopWidth,
				legendAlign: cs(legend) && cs(legend).textAlign,
				sumDisplay: cs(sum) && cs(sum).display,
			};
		});
		expect(styles.gridDisplay, 'edit-grid unstyled inside dialog').toBe('grid');
		expect(styles.gridCols.split(' ').length, 'edit-grid lost its columns').toBeGreaterThanOrEqual(5);
		expect(styles.fieldsetBorder, 'fieldset border missing inside dialog').not.toBe('0px');
		expect(styles.legendAlign, 'legend not start-aligned').toMatch(/start|left/);
		expect(styles.sumDisplay, 'split-sum not flexed').toBe('flex');

		// Field-layer pin (nw-vis-dialog-scalar-fields-dead-app-css): the
		// scalar-field recipe must reach the top layer — .nw-field is a flex
		// column whose control fills it (pre-fix NC pinned dialog inputs at
		// ~130-180px and labels sat inline-left of them).
		const fieldPin = await dlg.evaluate((d) => {
			const field = d.querySelector('.nw-field');
			if (!field) { return null; }
			const input = field.querySelector('.nw-input, .nw-select, .nw-textarea');
			const label = field.querySelector('label');
			const cs = getComputedStyle(field);
			return {
				display: cs.display,
				dir: cs.flexDirection,
				inputW: input ? input.getBoundingClientRect().width : 0,
				fieldW: field.getBoundingClientRect().width,
				labelDisplay: label ? getComputedStyle(label).display : null,
			};
		});
		expect(fieldPin, 'no .nw-field inside file-receipt dialog').toBeTruthy();
		expect(fieldPin.display, '.nw-field not flex inside dialog').toBe('flex');
		expect(fieldPin.dir, '.nw-field not column inside dialog').toBe('column');
		expect(fieldPin.labelDisplay, 'dialog label not block').toBe('block');
		expect(fieldPin.inputW, `dialog input pinned at ${fieldPin.inputW}px vs field ${fieldPin.fieldW}px`).toBeGreaterThan(fieldPin.fieldW * 0.9);

		await shot(page, 'nw-delta-receipt-dialog-splits-desktop.png', { surface: 'receipts', dialog: 'file-receipt', delta: 'e4a64e0' });

		// ≤640.98px the grid collapses to a single column — capture that leg too.
		await page.setViewportSize({ width: 375, height: 800 });
		const mobCols = await dlg.locator('.nw-edit-grid--splits').evaluate((el) => getComputedStyle(el).gridTemplateColumns);
		expect(mobCols.split(' ').length, 'mobile split grid should collapse to one column').toBe(1);
		await shot(page, 'nw-delta-receipt-dialog-splits-mobile.png', { surface: 'receipts', dialog: 'file-receipt', viewport: '375', delta: 'e4a64e0' });
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.keyboard.press('Escape');
		await expect(page.locator(DIALOG)).toHaveCount(0, { timeout: 10_000 });
	});

	test('Edit splits dialog: grid + sum styled on an existing receipt', async () => {
		await goto(page, `/?project=${pid}&view=receipts`);
		const row = page.locator('#nw-main table tbody tr', { hasText: `${MARK}-vendor` });
		await expect(row).toHaveCount(1, { timeout: 15_000 });
		await row.hover();
		// Row actions for a non-duplicate receipt: Edit(0), Splits(1).
		await row.locator('.nw-table-actions button').nth(1).click();
		const dlg = page.locator(DIALOG);
		await expect(dlg).toHaveCount(1, { timeout: 10_000 });
		await expect(dlg.locator('.nw-edit-grid--splits .nw-edit-grid__row')).toHaveCount(1);
		const display = await dlg.locator('.nw-edit-grid--splits').evaluate((el) => getComputedStyle(el).display);
		expect(display, 'splits grid unstyled inside dialog').toBe('grid');
		await shot(page, 'nw-delta-receipt-splits-dialog-desktop.png', { surface: 'receipts', dialog: 'edit-splits', delta: 'e4a64e0' });
		await page.keyboard.press('Escape');
		await expect(page.locator(DIALOG)).toHaveCount(0, { timeout: 10_000 });
	});

	test('Grant auditor dialog: Readable-sections fieldset styled in top layer', async () => {
		await goto(page, `/?project=${pid}&view=team`);
		// Page actions: Add member (primary), Grant auditor access (secondary).
		const grant = page.locator('#nw-page-actions button').nth(1);
		await expect(grant).toBeAttached({ timeout: 15_000 });
		await grant.click();
		const dlg = page.locator(DIALOG);
		await expect(dlg).toHaveCount(1, { timeout: 10_000 });
		await expect(dlg.locator('.nw-fieldset')).toHaveCount(1);
		const border = await dlg.locator('.nw-fieldset').evaluate((el) => getComputedStyle(el).borderTopWidth);
		expect(border, 'grant fieldset border missing inside dialog').not.toBe('0px');

		// Checkbox-row pin (same must_fix): scope checkboxes inside dialogs
		// were unstyled — no flex row, no 44px label target, NC-default box.
		const rowPin = await dlg.evaluate((d) => {
			const rowEl = d.querySelector('.nw-checkbox-row');
			if (!rowEl) { return null; }
			const lbl = rowEl.querySelector('label');
			const cb = rowEl.querySelector('input[type="checkbox"]');
			return {
				rowDisplay: getComputedStyle(rowEl).display,
				labelH: lbl ? lbl.getBoundingClientRect().height : 0,
				cbW: cb ? cb.getBoundingClientRect().width : 0,
			};
		});
		expect(rowPin, 'no .nw-checkbox-row inside grant dialog').toBeTruthy();
		expect(rowPin.rowDisplay, '.nw-checkbox-row not flex inside dialog').toBe('flex');
		expect(rowPin.labelH, 'checkbox label lost its 44px touch target').toBeGreaterThanOrEqual(40);
		expect(rowPin.cbW, 'checkbox rendered at unstyled default size').toBeGreaterThanOrEqual(18);

		await shot(page, 'nw-delta-grant-dialog-fieldset-desktop.png', { surface: 'team', dialog: 'grant-auditor', delta: '260e635' });
		await page.keyboard.press('Escape');
		await expect(page.locator(DIALOG)).toHaveCount(0, { timeout: 10_000 });
	});
});
