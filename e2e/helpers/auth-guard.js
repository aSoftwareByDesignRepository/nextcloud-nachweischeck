// @ts-check
/**
 * Shared e2e auth + API helpers for NachweisCheck Playwright specs.
 *
 * Fixture users (provisioned on the shared dev instance):
 *   nw_ds_admin  — app admin (default open door; also the DS capture user)
 *   nw_ds_denied — non-app-admin, hits admin-only surfaces for deny proofs
 *
 * Every spec logs in through the real form — the same path the
 * screenshot-matrix capture spec uses. Keep E2E_* env overrides aligned
 * with capture-screenshot-matrix.spec.js.
 */
const BASE = (process.env.NACHWEISCHECK_BASE_URL || process.env.E2E_BASE || 'http://localhost:8081').replace(/\/$/, '');
const APP = '/index.php/apps/nachweischeck';

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
 * First project id of the logged-in user; seeds a minimal ANBest-P project
 * through the real API when the instance has none (same honest seed as the
 * screenshot-matrix spec — no stubs).
 * @param {import('@playwright/test').Page} page
 */
async function resolveProjectId(page) {
	const list = await nwApi(page, 'GET', '/projects');
	const existing = (list.json && list.json.data) || [];
	if (existing.length > 0) {
		return existing[0].id;
	}
	const created = await nwApi(page, 'POST', '/projects', {
		name: 'Contract Demo',
		funder_name: 'BMBF',
		funder_ref: 'CONTRACT-1',
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

module.exports = { BASE, APP, USERS, loginAs, nwApi, resolveProjectId };
