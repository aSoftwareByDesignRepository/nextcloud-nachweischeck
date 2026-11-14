<?php

declare(strict_types=1);

/**
 * NachweisCheck — license host wrapper.
 *
 * The license UX is the canonical shared Check-family license page
 * (templates/common/license-page.php, synced from _shared/license-page).
 * This wrapper only adapts NachweisCheck's service shape to the shared
 * contract — do not put markup here.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @license AGPL-3.0-or-later
 */

$l = $l ?? (isset($_['l']) ? $_['l'] : null);
if ($l === null) {
	try {
		$l = \OCP\Util::getL10N('nachweischeck');
	} catch (\Throwable) {
		$l = new class { public function t($s, $p = []) { return $s; } };
	}
}

$licenseStatus = is_array($_['licenseStatus'] ?? null) ? $_['licenseStatus'] : null;
$licenseState = is_array($licenseStatus['state'] ?? null) ? $licenseStatus['state'] : null;
$licenseSeatsCounts = is_array($licenseStatus['seats'] ?? null) ? $licenseStatus['seats'] : ['assigned' => 0, 'limit' => 0];

$seatRowsRaw = is_array($_['licenseSeatsList']['data'] ?? null) ? $_['licenseSeatsList']['data'] : [];
$seatRows = [];
foreach ($seatRowsRaw as $row) {
	if (!is_array($row)) {
		continue;
	}
	$uid = (string)($row['uid'] ?? $row['userId'] ?? $row['id'] ?? '');
	if ($uid === '') {
		continue;
	}
	$seatRows[] = [
		'userId' => $uid,
		'displayName' => (string)($row['displayName'] ?? $uid),
		'assignedAt' => (string)($row['assigned_at'] ?? $row['assignedAt'] ?? ''),
	];
}

$license = $licenseState === null ? null : [
	'active' => !empty($licenseState['valid']),
	'cryptographicallyValid' => true,
	'dateValid' => (string)($licenseState['validUntil'] ?? '') !== '',
	'validUntil' => (string)($licenseState['validUntil'] ?? ''),
	'customerId' => (string)($licenseState['customerId'] ?? ''),
];

$mobileLimit = (int)($licenseSeatsCounts['limit'] ?? ($licenseState['mobileSeats'] ?? 0));
$urlGenerator = $_['urlGenerator'] ?? null;
if ($urlGenerator === null) {
	try {
		$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
	} catch (\Throwable) {
		$urlGenerator = null;
	}
}

$_['license'] = $license;
$_['productCode'] = 'NWC2';
$_['mobileSeatsUsed'] = (int)($licenseSeatsCounts['assigned'] ?? 0);
$_['mobileSeatsLimit'] = $mobileLimit;
$_['mobileSeats'] = $seatRows;
$_['showMobileSeats'] = true;
$_['apiLicenseUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('nachweischeck.licenseApi.apply') : '';
$_['apiClearLicenseUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('nachweischeck.licenseApi.remove') : '';
$_['apiSeatsUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('nachweischeck.adminApi.seats') : '';
$_['apiSeatsBatchUrl'] = '';
$_['apiRemoveSeatUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('nachweischeck.adminApi.freeSeatPost') : '';
$_['apiSearchUsersUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('nachweischeck.adminApi.directoryUsers') : '';
$_['licenseRenewMailto'] = 'mailto:info@software-by-design.de?subject=' . rawurlencode('NachweisCheck Pro license');
$_['productsUrl'] = 'https://nextcloud.software-by-design.de/';
try {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? \OCP\Util::callRegister());
} catch (\Throwable) {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? '');
}

// Keep the legacy deep-link anchor (#nw-license) working.
echo '<div id="nw-license">';
include __DIR__ . '/../../common/license-page.php';
echo '</div>';
