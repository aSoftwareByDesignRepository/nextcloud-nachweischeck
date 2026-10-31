#!/usr/bin/env node
/**
 * Contract: NW date helpers keep civil-day truth across timezones
 * (tz_monoculture_dates class — dutycheck customer-report precedent).
 *
 *   - fmtDate('YYYY-MM-DD') parses as LOCAL midnight (iso + 'T00:00:00',
 *     no Z) so the rendered day never shifts — west of UTC a UTC-midnight
 *     parse would render the previous day.
 *   - fmtDateTime(isoInstant) renders the zoned instant (days DO shift —
 *     that is correct instant semantics).
 *   - todayYmd() builds the day key from local components — never the UTC
 *     slice (ReceiptsScreen companion bug class, fixed via utils/dates.ts).
 *
 * The helpers are extracted verbatim from js/app.js and executed in a vm
 * sandbox whose Date pins "now" to a fixed boundary instant, so the
 * assertions are deterministic under any host clock.
 *
 * Run: TZ=America/New_York node tests/js/nw-dates.test.cjs
 *      (tz-matrix proof runs it under America/New_York + Pacific/Kiritimati
 *      + Europe/Berlin — assertions are zone-relative)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(ROOT, 'js', 'app.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

// Extract the three function definitions verbatim (they are tab-indented
// inside the app IIFE; each body ends at its own indent level).
function grab(name) {
	const m = src.match(new RegExp('function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n\\t\\}'));
	assert(m, name + ' not found in js/app.js — extraction contract broken');
	return m ? m[0] : '';
}
const code = ['fmtDate', 'fmtDateTime', 'todayYmd'].map(grab).join('\n');

/** Sandbox whose `new Date()` (no args) is pinned to instantIso. */
function sandboxAt(instantIso) {
	const Fixed = class extends Date {
		constructor(...args) {
			super(...(args.length ? args : [instantIso]));
		}
		static now() {
			return new Date(instantIso).getTime();
		}
	};
	const sb = { Date: Fixed, console };
	vm.runInNewContext(
		code + '\n;this.fmtDate=fmtDate;this.fmtDateTime=fmtDateTime;this.todayYmd=todayYmd;',
		sb
	);
	return sb;
}

// 02:30 UTC — west of UTC it is still the previous local day; at/ahead of
// UTC it is the same day (max +14 → 16:30, still Jan 15).
const BOUNDARY = '2026-01-15T02:30:00Z';
const behindUtc = new Date(BOUNDARY).getTimezoneOffset() > 0;
const expectedLocalDay = behindUtc ? '2026-01-14' : '2026-01-15';

// 1. todayYmd() is the LOCAL civil day — a toISOString().slice(0,10) impl
//    returns '2026-01-15' everywhere and fails under negative offsets.
const sbToday = sandboxAt(BOUNDARY);
assert(
	sbToday.todayYmd() === expectedLocalDay,
	`todayYmd() under ${process.env.TZ || 'host zone'}: got ${sbToday.todayYmd()}, ` +
		`expected local day ${expectedLocalDay} (UTC-slice bug class)`
);
assert(/^\d{4}-\d{2}-\d{2}$/.test(sbToday.todayYmd()), 'todayYmd() must emit YYYY-MM-DD');

// 2. fmtDate keeps the civil day — date-only input must never shift.
const sbFmt = sandboxAt('2026-06-01T00:00:00Z');
const rendered = sbFmt.fmtDate('2026-01-15');
assert(/\b15\b/.test(rendered) && !/\b14\b/.test(rendered),
	`fmtDate('2026-01-15') rendered "${rendered}" — civil day shifted (UTC-midnight parse)`);

// 3. fmtDateTime renders the zoned INSTANT — the day may legitimately
//    differ from the UTC day; assert it renders local, not UTC-frozen.
const zoned = sbFmt.fmtDateTime('2026-01-15T23:30:00Z');
const localDay = String(new Date('2026-01-15T23:30:00Z').getDate());
assert(new RegExp(`\\b${localDay}\\b`).test(zoned),
	`fmtDateTime(23:30Z) rendered "${zoned}" — expected local day ${localDay}`);

// 4. Sanity: helpers render something for every input.
assert(typeof sbFmt.fmtDate('2026-12-31') === 'string' && sbFmt.fmtDate('2026-12-31').length > 0,
	'fmtDate returned empty');

if (failures) {
	process.stderr.write(`nw-dates: ${failures} failure(s)\n`);
	process.exit(1);
}
process.stdout.write(`nw-dates: OK under TZ=${process.env.TZ || 'host'} ` +
	`(boundary local day ${expectedLocalDay})\n`);
