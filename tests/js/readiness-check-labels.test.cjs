#!/usr/bin/env node
/**
 * Contract: audit-readiness check ids render translated labels, never raw
 * snake_case enum ids (nw-vis-readiness-raw-enum-labels — maintenancecheck
 * exception-reason precedent).
 *
 * READINESS_CHECK_LABELS in js/pages/workspace.js is pinned against the
 * PHP enum in lib/Service/ReadinessService.php so a new server-side check
 * id cannot ship unmapped.
 *
 * Run: node tests/js/readiness-check-labels.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const php = fs.readFileSync(path.join(ROOT, 'lib', 'Service', 'ReadinessService.php'), 'utf8');
const js = fs.readFileSync(path.join(ROOT, 'js', 'pages', 'workspace.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

// 1. Every check id the PHP enum emits must exist in the JS label map.
const phpIds = [...new Set(
	[...php.matchAll(/'id'\s*=>\s*'([a-z_]+)'/g)].map((m) => m[1])
)];
assert(phpIds.length >= 5, `expected the readiness check enum, got ${JSON.stringify(phpIds)}`);

const mapBlock = js.match(/READINESS_CHECK_LABELS\s*=\s*\{([\s\S]*?)\};/);
assert(mapBlock, 'READINESS_CHECK_LABELS map not found in workspace.js');
const mapKeys = mapBlock
	? [...mapBlock[1].matchAll(/^\s*([a-z_]+):\s*'/gm)].map((m) => m[1])
	: [];
for (const id of phpIds) {
	assert(mapKeys.includes(id), `${id}: server check id missing from READINESS_CHECK_LABELS`);
}
for (const key of mapKeys) {
	assert(phpIds.includes(key), `${key}: label map entry without a server check id (stale map)`);
}

// 2. Map values are English msgids — no snake_case leaks as the label.
const mapValues = mapBlock
	? [...mapBlock[1].matchAll(/([a-z_]+):\s*'([^']+)'/g)].map((m) => [m[1], m[2]])
	: [];
for (const [key, label] of mapValues) {
	assert(label !== key, `${key}: raw enum leaked as label`);
	assert(!/[a-z]_[a-z]/.test(label), `${key}: label still snake_case (${label})`);
}

// 3. Both render sites map c.id through readinessCheckLabel — never raw.
const renderSites = [...js.matchAll(/readinessCheckLabel\(c\.id\)/g)];
assert(renderSites.length >= 2,
	`expected readinessCheckLabel(c.id) at both render sites (dashboard callout + nachweis checklist), found ${renderSites.length}`);
assert(!/'\s*'\s*\+\s*c\.id\s*\+/.test(js),
	'raw c.id still rendered (dashboard callout or checklist)');

// 4. Unknown-id fallback humanizes instead of leaking snake_case.
assert(/replace\(\s*\/_\/g/.test(js),
	'readinessCheckLabel must humanize unknown ids via _ → space fallback');

if (failures === 0) {
	process.stdout.write(`readiness-check-labels: ${phpIds.length} check ids mapped, both render sites covered\n`);
} else {
	process.stderr.write(`${failures} failure(s)\n`);
}
process.exit(failures ? 1 : 0);
