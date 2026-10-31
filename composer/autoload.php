<?php

declare(strict_types=1);

/**
 * Nextcloud only registers Composer deps from <app>/composer/autoload.php
 * (see OC_App::registerAutoloading). horstoeko/zugferd lives in vendor/, so
 * bridge it here — otherwise E-Rechnung parsing fatals on store installs.
 *
 * CRITICAL: Composer registers itself *prepended* by default. If vendor/ was
 * installed with require-dev (nextcloud/ocp stubs), a prepended loader shadows
 * the server's real OCP interfaces and can break the whole instance. Always
 * re-register appended so Nextcloud's autoloader wins for OCP and OC namespaces.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$nachweischeckVendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($nachweischeckVendorAutoload)) {
	return;
}

$nachweischeckLoader = require $nachweischeckVendorAutoload;
if ($nachweischeckLoader instanceof \Composer\Autoload\ClassLoader) {
	$nachweischeckLoader->unregister();
	$nachweischeckLoader->register(false);
}
