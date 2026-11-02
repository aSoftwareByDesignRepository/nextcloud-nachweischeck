#!/usr/bin/env node
/**
 * Regression pin for the 2026-11-02 shared-hosting 404: appBase() used to
 * return OC.getRootPath() + '/apps/nachweischeck', which drops the
 * /index.php front-controller prefix whenever the instance reports
 * modRewriteWorking=false (typical shared hosting). Every NW.api() call
 * and every appBase()-built link 404'd while the page shell still
 * rendered via index.php.
 *
 * Contract:
 *   1. OC.generateUrl is the only URL authority — appBase() must defer
 *      to it so pretty-URL and index.php installs both resolve.
 *   2. Without generateUrl the fallback is webroot + '/index.php/apps/…'
 *      (routable on every install), never the bare '/apps/…' form.
 *   3. Subdirectory webroots keep their prefix in both paths.
 *
 * Run: node tests/js/app-base.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'app.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

function loadKernel(oc) {
	const sandbox = {
		window: { OC: oc },
		OC: oc,
	};
	vm.createContext(sandbox);
	vm.runInContext(SRC, sandbox, { filename: 'js/app.js' });
	return sandbox.window.NW;
}

// Realistic OC.generateUrl stand-in mirroring @nextcloud/router:
// modRewriteWorking=false → webroot + '/index.php' + url.
function makeOC(webroot, modRewriteWorking) {
	const oc = {
		getRootPath() { return webroot; },
		get modRewriteWorking() { return modRewriteWorking; },
	};
	if (modRewriteWorking !== undefined) {
		oc.generateUrl = function (url) {
			return webroot + (modRewriteWorking ? '' : '/index.php') + url;
		};
	}
	return oc;
}

// 1. Pretty URLs on: generateUrl is authoritative.
{
	const NW = loadKernel(makeOC('', true));
	assert(NW.appBase() === '/apps/nachweischeck',
		'modRewriteWorking=true → /apps/nachweischeck, got ' + NW.appBase());
}

// 2. Pretty URLs off (shared hosting): index.php prefix mandatory —
//    this is the regression being pinned.
{
	const NW = loadKernel(makeOC('', false));
	assert(NW.appBase() === '/index.php/apps/nachweischeck',
		'modRewriteWorking=false → /index.php/apps/nachweischeck, got ' + NW.appBase());
}

// 3. Subdirectory install + no rewrites: webroot preserved.
{
	const NW = loadKernel(makeOC('/cloud', false));
	assert(NW.appBase() === '/cloud/index.php/apps/nachweischeck',
		'subdir + no rewrite → /cloud/index.php/apps/nachweischeck, got ' + NW.appBase());
}

// 4. No generateUrl at all: fallback must still emit the index.php form.
{
	const NW = loadKernel(makeOC('', undefined));
	assert(NW.appBase() === '/index.php/apps/nachweischeck',
		'no generateUrl → /index.php/apps/nachweischeck, got ' + NW.appBase());
}

// 5. No generateUrl + subdir: webroot still applies.
{
	const NW = loadKernel(makeOC('/cloud', undefined));
	assert(NW.appBase() === '/cloud/index.php/apps/nachweischeck',
		'no generateUrl + subdir → /cloud/index.php/apps/nachweischeck, got ' + NW.appBase());
}

// 6. appBase() result must never be the bare-webroot '/apps/…' shape that
//    404s under index.php routing: the '/index.php' segment is present
//    whenever generateUrl is absent.
{
	const NW = loadKernel(makeOC('/deep/root', undefined));
	assert(NW.appBase().indexOf('/index.php/apps/') !== -1,
		'fallback must contain /index.php/apps/, got ' + NW.appBase());
}

if (failures) {
	process.stderr.write(failures + ' failure(s)\n');
	process.exit(1);
}
process.stdout.write('app-base: all assertions passed\n');
