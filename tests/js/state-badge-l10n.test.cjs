#!/usr/bin/env node
/**
 * Contract: every lifecycle enum the server can persist via ->setState(…)
 * or ->setKind(…) must reach the page translated, never as a raw enum —
 * the 'filed' receipt state (76ab3dd) and the drawdown/claim/finplan
 * enums (nw-crit6) shipped without l10n msgids and rendered raw in every
 * locale incl. de ('requested' instead of 'beantragt').
 *
 * Emission collector covers the four arg shapes the regex of the first
 * pin could not see:
 *   ->setState('lit')                    literal
 *   ->setState(Drawdown::STATE_FOO)      const ref — resolved against the class file
 *   ->setState($target)                  variable — bounded by same-file
 *                                        in_array($var, […]) guards and
 *                                        $allowed transition maps
 *   ->setKind($w['kind']) / expressions  dynamic — must be registered in
 *                                        KNOWN_DYNAMIC_SITES with a reason;
 *                                        an unlisted site fails the pin.
 *
 * Producer literals ('kind' => 'x' written into entities by calculators/
 * templates/report sections) are collected the same way — a new enum
 * value reaching a kind field cannot ship unexamined.
 *
 * Pins for every emitted STATE:
 *   1. state ∈ STATE_BADGE   (explicit variant, not the 'neutral' fallback)
 *   2. state ∈ every l10n/*.json catalog msgid set + shipped .js catalogs
 * For every emitted KIND:
 *   kind ∈ catalogs  OR  kind covered by a workspace.js label map
 *   (DEVIATION_KINDS / NACHWEIS_KINDS / POSITION_KIND_LABELS /
 *    DEADLINE_KINDS)  OR  kind ∈ KIND_INLINE_LABELS (inline-ternary labels,
 *    whose msgid is then itself asserted against the catalogs).
 * Companion (mobile/nachweischeck, when the sibling checkout exists):
 *   every state emitted inside ProjectService.php must have a
 *   projects.state.<key> entry, TimesheetService.php likewise for
 *   timesheets.state.<key> — in BOTH en and de.
 *
 * Run: node tests/js/state-badge-l10n.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const MOBILE = path.join(ROOT, '..', '..', '..', 'mobile', 'nachweischeck');
const js = fs.readFileSync(path.join(ROOT, 'js', 'pages', 'workspace.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

// ---------- lib/ source model ----------

const libDir = path.join(ROOT, 'lib');
const phpFiles = [];
(function walk(dir) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const p = path.join(dir, entry.name);
		if (entry.isDirectory()) walk(p);
		else if (entry.name.endsWith('.php')) phpFiles.push(p);
	}
})(libDir);
const srcOf = new Map(phpFiles.map((p) => [p, fs.readFileSync(p, 'utf8')]));
const classFile = new Map(); // Claim => lib/Db/Claim.php
for (const p of phpFiles) classFile.set(path.basename(p, '.php'), p);

// public const NAME = 'lit' | ['lit', …]  — file → { NAME → [values] }
const constsOf = new Map();
function consts(file) {
	if (constsOf.has(file)) return constsOf.get(file);
	const table = new Map();
	const src = srcOf.get(file) || '';
	for (const m of src.matchAll(/public const ([A-Z_]+)\s*=\s*'([a-z_]+)'/g)) {
		table.set(m[1], [m[2]]);
	}
	for (const m of src.matchAll(/public const ([A-Z_]+)\s*=\s*\[([\s\S]*?)\];/g)) {
		table.set(m[1], [...m[2].matchAll(/'([a-z_]+)'/g)].map((v) => v[1]));
	}
	constsOf.set(file, table);
	return table;
}
function resolveConst(file, cls, name) {
	const f = cls === 'self' || cls === 'static' ? file : classFile.get(cls);
	if (!f) return null;
	return consts(f).get(name) || null;
}

// Dynamic emission sites whose value domain lives outside the call-site
// arg. Every entry must state where the emitted values DO come from; a
// site not listed here fails the pin, so a new unbounded emission can
// never slip through unexamined.
const KNOWN_DYNAMIC_SITES = new Map([
	["lib/Service/DeadlineService.php ->setKind($w['kind'])",
		'deadline kinds — producers are DeadlineCalculator literals, covered via DEADLINE_KINDS'],
	["lib/Service/ProjectService.php ->setKind((string)$posDef['kind'])",
		'template skeleton position kinds — producers are TemplateService literals, covered via POSITION_KIND_LABELS'],
	['lib/Service/FinplanService.php ->setKind($pos->getKind())',
		'clone copies a persisted position kind — domain bounded by FinplanService::KINDS, covered via POSITION_KIND_LABELS'],
	["lib/Service/FinplanService.php ->setKind((string)$p['kind'])",
		'request position kind — validated in_array($kind, self::KINDS) before write, covered via POSITION_KIND_LABELS'],
	['lib/Service/CsvImportService.php ->setKind($kind)',
		'import batch kind (times|receipts) — internal bookkeeping, never rendered'],
]);
const KNOWN_DYNAMIC_SEEN = new Set();

// Kind emissions whose visible label is produced by an inline ternary /
// comparison instead of a dict map — e.g. stateBadge(e.kind,
// t(e.kind === 'mitteluebertrag' ? 'Carryover' : 'Grant payment')).
// The label msgid is asserted against the catalogs, so the enum itself
// never renders raw.
const KIND_INLINE_LABELS = new Map([
	['zuwendung', 'Grant payment'],
	['mitteluebertrag', 'Carryover'],
]);

// ---------- emission collection ----------

const states = new Set();
const kinds = new Set();
const emissionsByFile = new Map(); // file → {states:[], kinds:[]}

for (const [file, src] of srcOf) {
	for (const m of src.matchAll(/->set(State|Kind)\s*\((.*?)\)\s*;/g)) {
		const which = m[1];
		const arg = m[2].trim();
		const bucket = which === 'State' ? states : kinds;
		const rel = path.relative(ROOT, file).replace(/\\/g, '/');
		const add = (v) => {
			bucket.add(v);
			const e = emissionsByFile.get(rel) || { states: [], kinds: [] };
			e[which === 'State' ? 'states' : 'kinds'].push(v);
			emissionsByFile.set(rel, e);
		};
		const unresolved = (reason) => {
			const key = `${rel} ->set${which}(${arg})`;
			if (!KNOWN_DYNAMIC_SITES.has(key)) {
				assert(false, `${key}: unresolvable emission arg — register in KNOWN_DYNAMIC_SITES with a value-domain reason (${reason})`);
			} else {
				KNOWN_DYNAMIC_SEEN.add(key);
			}
		};

		let mm;
		if ((mm = arg.match(/^'([a-z_]+)'$/))) {
			add(mm[1]);
		} else if ((mm = arg.match(/^(self|static|[A-Z][A-Za-z0-9_]*)::([A-Z_]+)$/))) {
			const vals = resolveConst(file, mm[1], mm[2]);
			if (!vals || !vals.length) {
				assert(false, `${rel}: ->set${which}(${arg}) — const ${arg} not resolvable to a lowercase literal`);
			} else vals.forEach(add);
		} else if ((mm = arg.match(/^\$([A-Za-z_][A-Za-z0-9_]*)$/))) {
			const varName = mm[1];
			let bounded = false;
			// in_array($var, [ 'lit', X::C, self::C ]) guards in the same file
			const inArray = new RegExp(
				'in_array\\(\\s*\\$' + varName + '\\s*,\\s*(\\[[\\s\\S]*?\\]|self::[A-Z_]+|[A-Z][A-Za-z0-9_]*::[A-Z_]+)',
				'g'
			);
			for (const g of src.matchAll(inArray)) {
				bounded = true;
				for (const lit of g[1].matchAll(/'([a-z_]+)'/g)) add(lit[1]);
				for (const cr of g[1].matchAll(/(self|static|[A-Z][A-Za-z0-9_]*)::([A-Z_]+)/g)) {
					const vals = resolveConst(file, cr[1], cr[2]);
					if (vals) vals.forEach(add);
				}
			}
			// $allowed transition maps — every quoted token is a reachable state
			for (const g of src.matchAll(/\$allowed\s*=\s*\[([\s\S]*?)\];/g)) {
				bounded = true;
				for (const lit of g[1].matchAll(/'([a-z_]+)'/g)) add(lit[1]);
			}
			if (!bounded) unresolved(`no in_array($${varName}, …) guard or $allowed map in this file`);
		} else {
			// expression arg — quoted literals inside it are still emitted values
			// (e.g. in_array($k, ['receipt','salary'], true) ? $k : 'receipt'),
			// but $var['key'] subscripts are array indexes, never emitted values.
			const stripped = arg.replace(/\$\w+\s*\[[^\]]*\]/g, '');
			const lits = [...stripped.matchAll(/'([a-z_]+)'/g)].map((v) => v[1]);
			const refs = [...stripped.matchAll(/(self|static|[A-Z][A-Za-z0-9_]*)::([A-Z_]+)/g)];
			if (lits.length || refs.length) {
				lits.forEach(add);
				for (const cr of refs) {
					const vals = resolveConst(file, cr[1], cr[2]);
					if (vals) vals.forEach(add);
				}
			} else {
				unresolved('expression arg carries no literal or const-ref values');
			}
		}
	}
}

// Producer literals: 'kind' => 'x' written into entities/report payloads.
// Collected for the three producers whose values flow through dynamic
// setKind sites or render directly — deadline kinds, template position
// kinds, nachweis income-section kinds.
for (const producer of ['lib/Support/DeadlineCalculator.php', 'lib/Service/TemplateService.php', 'lib/Service/NachweisService.php']) {
	const p = path.join(ROOT, producer);
	if (!srcOf.has(p)) continue;
	for (const m of srcOf.get(p).matchAll(/'kind'\s*=>\s*'([a-z_]+)'/g)) {
		kinds.add(m[1]);
		const rel = producer;
		const e = emissionsByFile.get(rel) || { states: [], kinds: [] };
		e.kinds.push(m[1]);
		emissionsByFile.set(rel, e);
	}
}

// every KNOWN_DYNAMIC_SITES entry must correspond to a real site —
// a stale entry means the table drifted from the code it documents.
for (const key of KNOWN_DYNAMIC_SITES.keys()) {
	assert(KNOWN_DYNAMIC_SEEN.has(key), `KNOWN_DYNAMIC_SITES entry is stale — no such emission site: ${key}`);
}

assert(states.size >= 10, `expected the server state enum, got ${JSON.stringify([...states])}`);
assert(kinds.size >= 5, `expected the server kind enum, got ${JSON.stringify([...kinds])}`);

// ---------- workspace.js maps ----------

function mapKeys(name) {
	const block = js.match(new RegExp(name + '\\s*=\\s*\\{([\\s\\S]*?)\\};'));
	assert(block, `${name} map not found in workspace.js`);
	return block ? new Set([...block[1].matchAll(/([a-z_]+)\s*:/g)].map((m) => m[1])) : new Set();
}
const badgeKeys = mapKeys('STATE_BADGE');
const kindMapKeys = new Set([
	...mapKeys('DEVIATION_KINDS'),
	...mapKeys('NACHWEIS_KINDS'),
	...mapKeys('POSITION_KIND_LABELS'),
	...mapKeys('DEADLINE_KINDS'),
]);

for (const s of states) {
	assert(badgeKeys.has(s), `${s}: server state missing from STATE_BADGE (falls back to 'neutral' silently)`);
}

// ---------- catalogs ----------

const locales = fs.readdirSync(path.join(ROOT, 'l10n'))
	.filter((f) => f.endsWith('.json'))
	.map((f) => f.replace(/\.json$/, ''));
assert(locales.length >= 4, `expected >=4 l10n catalogs, got ${JSON.stringify(locales)}`);
const catalogs = new Map();
for (const loc of locales) {
	catalogs.set(loc, JSON.parse(
		fs.readFileSync(path.join(ROOT, 'l10n', `${loc}.json`), 'utf8')
	).translations);
}
for (const loc of locales) {
	const tr = catalogs.get(loc);
	for (const s of states) {
		assert(s in tr, `${s}: state msgid missing from l10n/${loc}.json — badge renders raw "${s}"`);
	}
	for (const label of KIND_INLINE_LABELS.values()) {
		assert(label in tr, `${label}: inline kind label msgid missing from l10n/${loc}.json`);
	}
}

// A kind is covered when it is itself a catalog msgid (t(kind) renders
// translated) or a workspace.js label map translates it — or it is an
// inline-labelled value whose label msgid was asserted above.
for (const k of kinds) {
	const covered = kindMapKeys.has(k) || KIND_INLINE_LABELS.has(k)
		|| locales.every((loc) => k in catalogs.get(loc));
	assert(covered, `${k}: server kind has no translation path — no catalog msgid, no workspace.js label-map entry`);
}

// Shipped .js catalogs — what the browser actually resolves t() against.
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

// The German catalogs must not echo the raw enum — identical rendering
// to the English source was the reported defect, twice.
for (const loc of ['de', 'de_DE']) {
	const tr = catalogs.get(loc);
	for (const s of ['filed', 'requested', 'received', 'settled', 'interest', 'recovery']) {
		assert(tr[s] && tr[s] !== s,
			`${loc} catalog must translate '${s}' (got ${JSON.stringify(tr[s])})`);
	}
}

// ---------- companion coverage ----------

if (fs.existsSync(path.join(MOBILE, 'src', 'i18n', 'strings.ts'))) {
	const ts = fs.readFileSync(path.join(MOBILE, 'src', 'i18n', 'strings.ts'), 'utf8');
	// en: { … } de: { … } — split on the top-level locale keys.
	const deIdx = ts.search(/\n {2}de: \{/);
	assert(deIdx > 0, 'companion strings.ts: de locale block not found');
	const localeSrc = { en: ts.slice(0, deIdx), de: ts.slice(deIdx) };
	const stateKeys = (src, section) => {
		const m = src.match(new RegExp(section + ':\\s*\\{[\\s\\S]*?state:\\s*\\{([\\s\\S]*?)\\}'));
		return m ? new Set([...m[1].matchAll(/([a-z_]+)\s*:/g)].map((k) => k[1])) : new Set();
	};
	const entityMap = { 'lib/Service/ProjectService.php': 'projects', 'lib/Service/TimesheetService.php': 'timesheets' };
	for (const [file, section] of Object.entries(entityMap)) {
		const emitted = (emissionsByFile.get(file) || { states: [] }).states;
		for (const loc of ['en', 'de']) {
			const keys = stateKeys(localeSrc[loc], section);
			for (const s of emitted) {
				assert(keys.has(s), `${s}: companion ${section}.state.${s} missing in strings.ts ${loc} — badge renders raw "${s}"`);
			}
		}
	}
} else {
	process.stdout.write('note: mobile/nachweischeck not checked out — companion map legs skipped\n');
}

if (failures === 0) {
	process.stdout.write(
		`state-badge-l10n: ${states.size} states + ${kinds.size} kinds translated in ${locales.length} catalogs ` +
		`(${KNOWN_DYNAMIC_SEEN.size} dynamic sites documented)\n`
	);
} else {
	process.stderr.write(`${failures} failure(s)\n`);
}
process.exit(failures ? 1 : 0);
