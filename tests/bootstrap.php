<?php

declare(strict_types=1);

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

// Nextcloud base.php is loaded ONLY for the integration suite (needs a live
// NC instance + DB). Host-side unit runs use the nextcloud/ocp stub package.
// Auto-detect the Docker app tree (/var/www/html/custom_apps/<app>) the same
// way sibling Check apps do, so `docker/run-app-phpunit.sh` runs the
// integration suite without a manual env flag; NW_INTEGRATION=1 stays as the
// host-side override.
$inDockerTree = str_starts_with(__DIR__, '/var/www/html/');
if ($inDockerTree || getenv('NW_INTEGRATION') === '1') {
	$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '';
	foreach ([
		$nextcloudRoot !== '' ? rtrim($nextcloudRoot, '/\\') . '/lib/base.php' : '',
		__DIR__ . '/../../lib/base.php',
		__DIR__ . '/../../../lib/base.php',
	] as $candidate) {
		if ($candidate !== '' && is_file($candidate)) {
			require_once $candidate;
			break;
		}
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

// NOTE: DG\BypassFinals deliberately NOT enabled — its custom `file` stream
// wrapper makes PHP 8.5 is_writable()/access() checks fall back to statbuf
// mask simulation, which breaks core (OC\Snowflake\FileSequence) on
// foreign-owned dirs. No test in this suite mocks a final class;
// re-enable per-test if ever needed.

if (!class_exists(\Test\TestCase::class)) {
	$shim = __DIR__ . '/shim/TestCase.php';
	if (is_file($shim)) {
		require_once $shim;
	}
}
