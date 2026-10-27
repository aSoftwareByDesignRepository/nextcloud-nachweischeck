<?php

declare(strict_types=1);

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

// Nextcloud base.php is loaded ONLY for the integration suite (needs a live
// NC instance + DB). Host-side unit runs use the nextcloud/ocp stub package.
if (getenv('NW_INTEGRATION') === '1') {
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

if (class_exists(\DG\BypassFinals::class)) {
	\DG\BypassFinals::enable();
}

if (!class_exists(\Test\TestCase::class)) {
	$shim = __DIR__ . '/shim/TestCase.php';
	if (is_file($shim)) {
		require_once $shim;
	}
}
