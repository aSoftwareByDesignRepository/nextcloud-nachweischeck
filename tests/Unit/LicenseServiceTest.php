<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Exception\LicenseException;
use OCA\NachweisCheck\Service\LicenseService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Test\TestCase;

class LicenseServiceTest extends TestCase
{
	/**
	 * @param array<string, string> $values appconfig rows keyed "app|key"
	 */
	private function svc(array $values = [], bool $opsInstalled = false): LicenseService
	{
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = '') use ($values): string {
				return $values[$app . '|' . $key] ?? $default;
			});
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			fn(string $appId): bool => $appId === 'sbdlicenseops' && $opsInstalled
		);
		return new LicenseService($appConfig, $appManager);
	}

	public function testProModeOverrideWins(): void
	{
		$svc = $this->svc(['nachweischeck|nw_license_mode' => 'pro']);
		$this->assertTrue($svc->isPro());
	}

	public function testFreeModeOverrideShortCircuitsOpsProbe(): void
	{
		// even with a valid ops license present, explicit 'free' wins
		$svc = $this->svc([
			'nachweischeck|nw_license_mode' => 'free',
			'sbdlicenseops|license_nachweischeck_state' => 'active',
		], true);
		$this->assertFalse($svc->isPro());
	}

	public function testOpsAppMissingMeansFree(): void
	{
		$svc = $this->svc([], false);
		$this->assertFalse($svc->isPro());
	}

	public function testOpsActiveAndGraceStatesArePro(): void
	{
		foreach (['active', 'grace'] as $state) {
			$svc = $this->svc(['sbdlicenseops|license_nachweischeck_state' => $state], true);
			$this->assertTrue($svc->isPro(), "state {$state} should be pro");
		}
	}

	public function testOpsOtherStatesAreFree(): void
	{
		foreach (['expired', 'invalid', ''] as $state) {
			$svc = $this->svc(['sbdlicenseops|license_nachweischeck_state' => $state], true);
			$this->assertFalse($svc->isPro(), "state '{$state}' should be free");
		}
	}

	public function testProFeatureMatrixAllOpen(): void
	{
		$svc = $this->svc(['nachweischeck|nw_license_mode' => 'pro']);
		foreach ([
			LicenseService::FEATURE_ERECHNUNG,
			LicenseService::FEATURE_TEMPLATES,
			LicenseService::FEATURE_MULTI_PROJECT,
			LicenseService::FEATURE_TIMESHEET_SIGNOFF,
			LicenseService::FEATURE_AUDITOR_SEAT,
			LicenseService::FEATURE_MOBILE_SEATS,
		] as $f) {
			$this->assertTrue($svc->hasFeature($f), "pro must open {$f}");
		}
	}

	public function testFreeFeatureMatrix(): void
	{
		$svc = $this->svc();
		foreach ([
			LicenseService::FEATURE_ERECHNUNG,
			LicenseService::FEATURE_TEMPLATES,
			LicenseService::FEATURE_MULTI_PROJECT,
			LicenseService::FEATURE_TIMESHEET_SIGNOFF,
			LicenseService::FEATURE_AUDITOR_SEAT,
			LicenseService::FEATURE_MOBILE_SEATS,
		] as $f) {
			$this->assertFalse($svc->hasFeature($f), "free must close {$f}");
		}
		// unlisted features default open on the free tier
		$this->assertTrue($svc->hasFeature('csv_import'));
	}

	public function testRequireFeatureThrowsLicenseException(): void
	{
		$svc = $this->svc();
		$this->expectException(LicenseException::class);
		try {
			$svc->requireFeature(LicenseService::FEATURE_AUDITOR_SEAT);
		} catch (LicenseException $e) {
			$this->assertSame('LICENSE_REQUIRED', $e->getErrorCode());
			$this->assertSame(LicenseService::FEATURE_AUDITOR_SEAT, $e->getFields()['feature'] ?? null);
			throw $e;
		}
	}

	public function testActiveProjectLimitFreeTier(): void
	{
		$svc = $this->svc();
		$svc->checkActiveProjectLimit(0); // first active project is allowed
		$this->expectException(LicenseException::class);
		$svc->checkActiveProjectLimit(1); // second one is not
	}

	public function testActiveProjectLimitUnlimitedOnPro(): void
	{
		$svc = $this->svc(['nachweischeck|nw_license_mode' => 'pro']);
		$svc->checkActiveProjectLimit(42);
		$this->assertTrue(true); // reached without exception
	}
}
