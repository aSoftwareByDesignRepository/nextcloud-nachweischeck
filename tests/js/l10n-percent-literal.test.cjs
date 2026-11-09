#!/usr/bin/env node
/**
 * Contract: '%%' percent-escapes in l10n msgstrs are only legal for
 * msgids that render through PHP ->t() (L10NString::__toString runs
 * vsprintf() unconditionally — '%%' collapses to '%' there).
 *
 * JS-bound msgids resolve through OC.L10N.translate + the app's own
 * %s/{var} substitution (js/app.js t()) — '%%' is NEVER collapsed, so a
 * catalog value like 'Bütçe kalemi başına %%20 tolerans' renders a
 * doubled percent to the user (tr intake 20261109: three JS-bound
 * percent strings over-escaped on the sprintf convention that only
 * applies to PHP-bound strings — e.g. '20 % tolerance per budget
 * position', which is a badge/hint label painted by workspace.js /
 * settings.js).
 *
 * Pin: for every shipped catalog (.json AND .js, all locales incl. en —
 * the en msgstr doubles as the t() fallback), any msgstr containing
 * '%%' must belong to a msgid bound in lib/ or templates/ PHP source.
 * A msgid used from js/ must carry a single '%' (or spell the percent
 * out, e.g. tr 'yüzde 20') in every locale.
 *
 * Run: node tests/js/l10n-percent-literal.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const L10N = path.join(ROOT, 'l10n');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

// ---------- binding sets ----------

const phpBlob = [];
(function walk(dir) {
	if (!fs.existsSync(dir)) return;
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const p = path.join(dir, entry.name);
		if (entry.isDirectory()) walk(p);
		else if (/\.(php)$/.test(entry.name)) phpBlob.push(fs.readFileSync(p, 'utf8'));
	}
})(path.join(ROOT, 'lib'));
(function walk(dir) {
	if (!fs.existsSync(dir)) return;
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const p = path.join(dir, entry.name);
		if (entry.isDirectory()) walk(p);
		else if (/\.(php)$/.test(entry.name)) phpBlob.push(fs.readFileSync(p, 'utf8'));
	}
})(path.join(ROOT, 'templates'));
const php = phpBlob.join('\n');

const jsBlob = [];
(function walk(dir) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const p = path.join(dir, entry.name);
		if (entry.isDirectory()) walk(p);
		else if (entry.name.endsWith('.js')) jsBlob.push(fs.readFileSync(p, 'utf8'));
	}
})(path.join(ROOT, 'js'));
const js = jsBlob.join('\n');

// ---------- catalogs ----------

const locales = fs.readdirSync(L10N).filter((f) => f.endsWith('.json')).map((f) => f.slice(0, -5));
assert(locales.length >= 4, `expected >=4 l10n catalogs, got ${locales.length}`);

for (const loc of locales) {
	const cat = JSON.parse(fs.readFileSync(path.join(L10N, `${loc}.json`), 'utf8')).translations || {};
	for (const [msgid, raw] of Object.entries(cat)) {
		const vals = Array.isArray(raw) ? raw : [raw];
		const hasDouble = vals.some((v) => String(v).includes('%%'));
		if (!hasDouble) continue;
		const phpBound = php.includes(msgid);
		const jsBound = js.includes(msgid);
		assert(
			phpBound && !jsBound,
			`l10n/${loc}.json: '%%' escape in JS-rendered msgstr ${JSON.stringify(msgid.slice(0, 60))} ` +
			`(php_bound=${phpBound}, js_bound=${jsBound}) — OC.L10N.translate never collapses '%%', ` +
			'write a single % or spell the percent out'
		);
	}
}

// Shipped .js catalogs — same rule on what the browser actually registers.
for (const loc of locales) {
	const jsPath = path.join(L10N, `${loc}.js`);
	assert(fs.existsSync(jsPath), `l10n/${loc}.js missing`);
	const txt = fs.readFileSync(jsPath, 'utf8');
	const m = txt.match(/OC\.L10N\.register\(\s*"nachweischeck"\s*,\s*(\{[\s\S]*\})\s*\)\s*;?\s*$/);
	assert(m, `l10n/${loc}.js: could not extract translations map`);
	if (!m) continue;
	const cat = JSON.parse(m[1]);
	for (const [msgid, raw] of Object.entries(cat)) {
		const vals = Array.isArray(raw) ? raw : [raw];
		if (!vals.some((v) => String(v).includes('%%'))) continue;
		const phpBound = php.includes(msgid);
		const jsBound = js.includes(msgid);
		assert(
			phpBound && !jsBound,
			`l10n/${loc}.js: '%%' escape in JS-rendered msgstr ${JSON.stringify(msgid.slice(0, 60))}`
		);
	}
}

// Concrete regression — the three tr percent strings must render a
// single percent (spelled-out 'yüzde' form); never a '%%' escape.
const tr = JSON.parse(fs.readFileSync(path.join(L10N, 'tr.json'), 'utf8')).translations;
for (const k of [
	'20 % tolerance per budget position',
	'Over 120 % — funder approval required',
	'Over plan — inside the 20 % rule',
]) {
	assert(k in tr, `tr.json: ${JSON.stringify(k)} missing`);
	assert(!String(tr[k]).includes('%%'), `tr.json: ${JSON.stringify(k)} still carries '%%' (${JSON.stringify(tr[k])})`);
}

if (failures) {
	process.stderr.write(`l10n-percent-literal: ${failures} failure(s)\n`);
	process.exit(1);
}
process.stdout.write(`l10n-percent-literal: ${locales.length} catalogs — every '%%' escape is PHP-bound; JS-bound percent strings carry a single %\n`);
