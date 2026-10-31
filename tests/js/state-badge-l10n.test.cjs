#!/usr/bin/env node
/**
 * Contract: every lifecycle state the server writes via ->setState('…')
 * must render a translated badge, never the raw lowercase enum — the
 * 'filed' receipt state (76ab3dd) shipped without an l10n msgid and
 * rendered raw "filed" in every locale incl. de (open → 'offen').
 *
 * Pins both halves of stateBadge(state, t(state)) in workspace.js:
 *   1. state ∈ STATE_BADGE   (explicit variant, not the 'neutral' fallback)
 *   2. state ∈ every l10n/*.json catalog msgid set
 *
 * Run: node tests/js/state-badge-l10n.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const js = fs.readFileSync(path.join(ROOT, 'js', 'pages', 'workspace.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

// 1. Collect every state literal the server can emit.
const libDir = path.join(ROOT, 'lib');
const phpFiles = [];
(function walk(dir) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const p = path.join(dir, entry.name);
		if (entry.isDirectory()) walk(p);
		else if (entry.name.endsWith('.php')) phpFiles.push(p);
	}
})(libDir);

const states = new Set();
for (const file of phpFiles) {
	const src = fs.readFileSync(file, 'utf8');
	for (const m of src.matchAll(/->setState\(\s*'([a-z_]+)'\s*\)/g)) {
		states.add(m[1]);
	}
}
assert(states.size >= 5, `expected the server state enum, got ${JSON.stringify([...states])}`);

// 2. Every emitted state has an explicit STATE_BADGE variant.
const mapBlock = js.match(/STATE_BADGE\s*=\s*\{([\s\S]*?)\};/);
assert(mapBlock, 'STATE_BADGE map not found in workspace.js');
const mapKeys = mapBlock
	? new Set([...mapBlock[1].matchAll(/([a-z_]+)\s*:/g)].map((m) => m[1]))
	: new Set();
for (const s of states) {
	assert(mapKeys.has(s), `${s}: server state missing from STATE_BADGE (falls back to 'neutral' silently)`);
}

// 3. Every emitted state is a msgid in all four catalogs — t(state) must
//    never echo the raw enum.
const locales = fs.readdirSync(path.join(ROOT, 'l10n'))
	.filter((f) => f.endsWith('.json'))
	.map((f) => f.replace(/\.json$/, ''));
assert(locales.length >= 4, `expected >=4 l10n catalogs, got ${JSON.stringify(locales)}`);
for (const loc of locales) {
	const tr = JSON.parse(
		fs.readFileSync(path.join(ROOT, 'l10n', `${loc}.json`), 'utf8')
	).translations;
	for (const s of states) {
		assert(s in tr, `${s}: state msgid missing from l10n/${loc}.json — badge renders raw "${s}"`);
	}
}

// 4. Execute the shipped .js catalogs through the real OC.L10N.register
//    mechanism — this is what the browser resolves t(state) against.
for (const loc of locales) {
	const jsPath = path.join(ROOT, 'l10n', `${loc}.js`);
	if (!fs.existsSync(jsPath)) continue;
	const sandbox = { OC: { L10N: { register(app, tr) { sandbox.__tr = tr; } } } };
	vm.createContext(sandbox);
	vm.runInContext(fs.readFileSync(jsPath, 'utf8'), sandbox);
	const tr = sandbox.__tr || {};
	for (const s of states) {
		assert(s in tr, `${s}: state msgid missing from shipped l10n/${loc}.js`);
	}
}
// 'filed' specifically must not echo the raw enum in the German catalogs —
// identical rendering to the English source was the reported defect.
for (const loc of ['de', 'de_DE']) {
	const tr = JSON.parse(
		fs.readFileSync(path.join(ROOT, 'l10n', `${loc}.json`), 'utf8')
	).translations;
	assert(tr['filed'] && tr['filed'] !== 'filed',
		`de catalog must translate 'filed' (got ${JSON.stringify(tr['filed'])})`);
}

if (failures === 0) {
	process.stdout.write(
		`state-badge-l10n: ${states.size} server states mapped + translated in ${locales.length} catalogs\n`
	);
} else {
	process.stderr.write(`${failures} failure(s)\n`);
}
process.exit(failures ? 1 : 0);
