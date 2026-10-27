<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pro gate (ADR-08, I-07). Detection order:
 *   1. sbdlicenseops app present AND reports a valid 'nachweischeck' license → pro
 *   2. appconfig nw_license_mode = 'pro' (dev/self-host override) → pro
 *   3. otherwise → free tier limits
 *
 * Free tier: 1 active project, ANBest-P template only, manual Beleg entry,
 * view-only timesheets, no auditor seats, no e-invoice parse, no mobile seats.
 * Grace on expiry: read-only (enforced by callers checking feature flags
 * before mutations).
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Exception\LicenseException;
use OCP\App\IAppManager;
use OCP\IAppConfig;

class LicenseService
{
	private const APP_ID = 'nachweischeck';

	public const FEATURE_ERECHNUNG = 'erechnung';
	public const FEATURE_TEMPLATES = 'templates_extra';
	public const FEATURE_MULTI_PROJECT = 'multi_project';
	public const FEATURE_TIMESHEET_SIGNOFF = 'timesheet_signoff';
	public const FEATURE_AUDITOR_SEAT = 'auditor_seat';
	public const FEATURE_MOBILE_SEATS = 'mobile_seats';

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
	) {
	}

	public function isPro(): bool
	{
		$mode = $this->appConfig->getValueString(self::APP_ID, 'nw_license_mode', '');
		if ($mode === 'pro') {
			return true;
		}
		if ($mode === 'free') {
			return false;
		}
		return $this->opsLicenseValid();
	}

	private function opsLicenseValid(): bool
	{
		if (!$this->appManager->isInstalled('sbdlicenseops')) {
			return false;
		}
		// sbdlicenseops stores license products under its own appconfig
		// namespace. Read-only probe: presence of an active product key.
		$raw = $this->appConfig->getValueString('sbdlicenseops', 'license_nachweischeck_state', '');
		return $raw === 'active' || $raw === 'grace';
	}

	public function hasFeature(string $feature): bool
	{
		if ($this->isPro()) {
			return true;
		}
		return match ($feature) {
			self::FEATURE_TEMPLATES, self::FEATURE_ERECHNUNG, self::FEATURE_TIMESHEET_SIGNOFF,
			self::FEATURE_AUDITOR_SEAT, self::FEATURE_MOBILE_SEATS, self::FEATURE_MULTI_PROJECT => false,
			default => true,
		};
	}

	public function requireFeature(string $feature): void
	{
		if (!$this->hasFeature($feature)) {
			throw new LicenseException($feature);
		}
	}

	/** Free tier: at most 1 active project. */
	public function checkActiveProjectLimit(int $activeCount): void
	{
		if (!$this->isPro() && $activeCount >= 1) {
			throw new LicenseException(self::FEATURE_MULTI_PROJECT);
		}
	}
}
