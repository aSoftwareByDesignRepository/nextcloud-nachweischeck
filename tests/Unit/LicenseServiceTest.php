<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\LicenseState;
use OCA\NachweisCheck\Db\LicenseStateMapper;
use OCA\NachweisCheck\Db\MobileSeatMapper;
use OCA\NachweisCheck\Exception\LicenseException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class LicenseServiceTest extends TestCase
{
	private const TODAY = '2026-10-31';

	private function state(?string $validUntil, int $mobileSeats = 5): ?LicenseState
	{
		if ($validUntil === null) {
			return null;
		}
		$s = new LicenseState();
		$s->setCustomerId('acme-corp');
		$s->setIssuedAt('2026-01-01');
		$s->setValidUntil($validUntil);
		$s->setMobileSeats($mobileSeats);
		$s->setPayloadB64('payload');
		$s->setSignatureB64('sig');
		return $s;
	}

	private function svc(array $appValues = [], ?LicenseState $state = null, int $seatCount = 0): LicenseService
	{
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn(string $app, string $key, string $default = ''): string => $appValues[$app . '|' . $key] ?? $default);
		$stateMapper = $this->createMock(LicenseStateMapper::class);
		$stateMapper->method('findSingleton')->willReturn($state);
		$seatMapper = $this->createMock(MobileSeatMapper::class);
		$seatMapper->method('countAll')->willReturn($seatCount);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime(self::TODAY . ' 12:00:00'));
		$time->method('getTime')->willReturn(strtotime(self::TODAY));
		$locking = $this->createMock(ILockingProvider::class);
		$audit = $this->createMock(AuditService::class);
		return new LicenseService(
			$this->createMock(IDBConnection::class),
			$stateMapper, $seatMapper, $time, $appConfig, $locking, $audit,
		);
	}

	// ---- gate matrix (spec contract test: Free/Pro × Feature) -------------

	public function testProModeOverrideWins(): void
	{
		$svc = $this->svc(['nachweischeck|nw_license_mode' => 'pro']);
		$this->assertTrue($svc->isPro());
	}

	public function testFreeOverrideShortCircuitsValidLicense(): void
	{
		$svc = $this->svc(
			['nachweischeck|nw_license_mode' => 'free'],
			$this->state('2099-01-01'),
		);
		$this->assertFalse($svc->isPro());
	}

	public function testValidStoredLicenseIsPro(): void
	{
		// validUntil == today is still valid (inclusive, day precision)
		foreach (['2026-10-31', '2099-01-01'] as $until) {
			$svc = $this->svc([], $this->state($until));
			$this->assertTrue($svc->isPro(), "validUntil {$until} must be pro");
		}
	}

	public function testExpiredLicenseIsFreeButDataReadable(): void
	{
		$svc = $this->svc([], $this->state('2026-10-30'));
		$this->assertFalse($svc->isPro());
		// grace semantics: expired state blocks pro mutations, never reads
		$st = $svc->status();
		$this->assertSame('free', $st['tier']);
		$this->assertFalse($st['state']['valid']);
		$this->assertSame('acme-corp', $st['state']['customerId']);
	}

	public function testNoStateMeansFree(): void
	{
		$this->assertFalse($this->svc()->isPro());
	}

	public function testProFeatureMatrixAllOpen(): void
	{
		$svc = $this->svc(['nachweischeck|nw_license_mode' => 'pro']);
		foreach ([
			LicenseService::FEATURE_ERECHNUNG, LicenseService::FEATURE_TEMPLATES,
			LicenseService::FEATURE_MULTI_PROJECT, LicenseService::FEATURE_TIMESHEET_SIGNOFF,
			LicenseService::FEATURE_AUDITOR_SEAT, LicenseService::FEATURE_MOBILE_SEATS,
		] as $f) {
			$this->assertTrue($svc->hasFeature($f), "pro must open {$f}");
		}
	}

	public function testFreeFeatureMatrix(): void
	{
		$svc = $this->svc();
		foreach ([
			LicenseService::FEATURE_ERECHNUNG, LicenseService::FEATURE_TEMPLATES,
			LicenseService::FEATURE_MULTI_PROJECT, LicenseService::FEATURE_TIMESHEET_SIGNOFF,
			LicenseService::FEATURE_AUDITOR_SEAT, LicenseService::FEATURE_MOBILE_SEATS,
		] as $f) {
			$this->assertFalse($svc->hasFeature($f), "free must close {$f}");
		}
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
		$svc->checkActiveProjectLimit(0);
		$this->expectException(LicenseException::class);
		$svc->checkActiveProjectLimit(1);
	}

	public function testActiveProjectLimitUnlimitedOnPro(): void
	{
		$svc = $this->svc(['nachweischeck|nw_license_mode' => 'pro']);
		$svc->checkActiveProjectLimit(42);
		$this->assertTrue(true);
	}

	// ---- seat entitlement + status envelope ------------------------------

	public function testSeatLimitFollowsLicense(): void
	{
		$this->assertSame(7, $this->svc([], $this->state('2099-01-01', 7))->seatLimit());
		$this->assertSame(0, $this->svc([], $this->state('2020-01-01', 7))->seatLimit());
		$this->assertSame(0, $this->svc()->seatLimit());
		// override 'pro' without stored key → no seat entitlement
		$this->assertSame(0, $this->svc(['nachweischeck|nw_license_mode' => 'pro'])->seatLimit());
	}

	public function testStatusShape(): void
	{
		$st = $this->svc([], $this->state('2026-11-15', 5), 2)->status();
		$this->assertSame('pro', $st['tier']);
		$this->assertTrue($st['state']['valid']);
		$this->assertTrue($st['state']['expiresSoon']);
		$this->assertSame(15, $st['state']['daysRemaining']);
		$this->assertSame(['assigned' => 2, 'limit' => 5], $st['seats']);
		$this->assertSame(LicenseService::PURCHASE_URL, $st['purchaseUrl']);
	}

	public function testBuildEnvelopeOnlyWhileValid(): void
	{
		$env = $this->svc([], $this->state('2099-01-01'))->buildEnvelope();
		$this->assertSame('NWC2', $env['format']);
		$this->assertSame('payload', $env['payloadB64']);
		$this->assertNull($this->svc([], $this->state('2020-01-01'))->buildEnvelope());
		$this->assertNull($this->svc()->buildEnvelope());
	}

	public function testApplyRejectsMalformedKey(): void
	{
		$svc = $this->svc();
		try {
			$svc->apply('admin', 'not-a-key');
			$this->fail('expected rejection');
		} catch (\OCA\NachweisCheck\Exception\NwException $e) {
			$this->assertSame('license_invalid', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
		}
	}
}
