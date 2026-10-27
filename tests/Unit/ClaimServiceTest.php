<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Claim;
use OCA\NachweisCheck\Db\ClaimMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\ClaimService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class ClaimServiceTest extends TestCase
{
	private function svc(?ClaimMapper $m = null, ?AuditService $audit = null): array
	{
		$m = $m ?? $this->createMock(ClaimMapper::class);
		$audit = $audit ?? $this->createMock(AuditService::class);
		$guard = new ConcurrencyGuard($this->createMock(ILockingProvider::class));
		return [new ClaimService($m, $audit, $guard), $m, $audit];
	}

	public function testInterestComputedServerSide(): void
	{
		$m = $this->createMock(ClaimMapper::class);
		$m->method('insert')->willReturnCallback(function ($c) {
			$c->setId(1);
			return $c;
		});
		[$svc] = $this->svc($m);
		$c = $svc->create(1, [
			'kind' => 'interest', 'title' => 'Zinsen Restmittel 2026',
			'principal_minor' => 100000, 'rate_bp' => 500,
			'period_from' => '2026-01-01', 'period_to' => '2027-01-01',
			'occurred_date' => '2026-12-31',
			// a forged amount_minor must be ignored — the server computes.
			'amount_minor' => 999999,
		], 'u1');
		$this->assertSame(5000, $c->getAmountMinor());
		$this->assertSame(Claim::STATE_OPEN, $c->getState());
	}

	public function testInterestRequiresValidPeriod(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, [
				'kind' => 'interest', 'title' => 'x',
				'principal_minor' => 100000, 'rate_bp' => 500,
				'period_from' => '2027-01-01', 'period_to' => '2026-01-01',
				'occurred_date' => '2026-12-31',
			], 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('period', $e->getFields());
			throw $e;
		}
	}

	public function testInterestRejectsExtremeRate(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		$svc->create(1, [
			'kind' => 'interest', 'title' => 'x',
			'principal_minor' => 100000, 'rate_bp' => 200000,
			'period_from' => '2026-01-01', 'period_to' => '2027-01-01',
			'occurred_date' => '2026-12-31',
		], 'u1');
	}

	public function testRecoveryUsesSuppliedAmount(): void
	{
		$m = $this->createMock(ClaimMapper::class);
		$m->method('insert')->willReturnCallback(function ($c) {
			$c->setId(2);
			return $c;
		});
		[$svc] = $this->svc($m);
		$c = $svc->create(1, [
			'kind' => 'recovery', 'title' => 'Rueckforderung Q3',
			'amount_minor' => 12345, 'occurred_date' => '2026-10-01',
		], 'u1');
		$this->assertSame(12345, $c->getAmountMinor());
		$this->assertSame(Claim::KIND_RECOVERY, $c->getKind());
	}

	public function testRecoveryRequiresPositiveAmount(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, [
				'kind' => 'recovery', 'title' => 'x', 'amount_minor' => 0,
				'occurred_date' => '2026-10-01',
			], 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('amount_minor', $e->getFields());
			throw $e;
		}
	}

	public function testInvalidKindRefused(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		$svc->create(1, ['kind' => 'fine', 'title' => 'x', 'occurred_date' => '2026-10-01'], 'u1');
	}

	public function testSettleTransitionsOnce(): void
	{
		$c = new Claim();
		$c->setId(5);
		$c->setProjectId(1);
		$c->setState(Claim::STATE_OPEN);
		$m = $this->createMock(ClaimMapper::class);
		$m->method('findById')->willReturn($c);
		$m->expects($this->once())->method('update');
		[$svc] = $this->svc($m);
		$this->assertSame(Claim::STATE_SETTLED, $svc->settle($c, 'u1')->getState());
		$this->expectException(ConflictException::class);
		$svc->settle($c, 'u1');
	}

	public function testGetOrFailScopeGuard(): void
	{
		$c = new Claim();
		$c->setProjectId(42);
		$m = $this->createMock(ClaimMapper::class);
		$m->method('findById')->willReturn($c);
		[$svc] = $this->svc($m);
		$this->expectException(NotFoundException::class);
		$svc->getOrFail(1, 5);
	}
}
