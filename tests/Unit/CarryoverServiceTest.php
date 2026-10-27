<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Carryover;
use OCA\NachweisCheck\Db\CarryoverMapper;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\CarryoverService;
use Test\TestCase;

class CarryoverServiceTest extends TestCase
{
	private function svc(?CarryoverMapper $m = null, ?AuditService $audit = null): array
	{
		$m = $m ?? $this->createMock(CarryoverMapper::class);
		$m->method('findByProject')->willReturn([]);
		$audit = $audit ?? $this->createMock(AuditService::class);
		return [new CarryoverService($m, $audit), $m, $audit];
	}

	public function testToYearMustBeDirectSuccessor(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, 2025, 2027, 5000, 'B-1', 'funder', 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('to_year', $e->getFields());
			throw $e;
		}
	}

	public function testAmountMustBePositive(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, 2025, 2026, 0, 'B-1', 'funder', 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('amount_minor', $e->getFields());
			throw $e;
		}
	}

	public function testDuplicateFromYearRefused(): void
	{
		$existing = new Carryover();
		$existing->setFromYear(2025);
		$existing->setToYear(2026);
		$m = $this->createMock(CarryoverMapper::class);
		$m->method('findByProject')->willReturn([$existing]);
		$m->expects($this->never())->method('insert');
		$svc = new CarryoverService($m, $this->createMock(AuditService::class));
		$this->expectException(ValidationException::class);
		try {
			$svc->create(1, 2025, 2026, 5000, 'B-2', 'funder', 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('from_year', $e->getFields());
			throw $e;
		}
	}

	public function testValidCarryoverPersistedAndAudited(): void
	{
		$m = $this->createMock(CarryoverMapper::class);
		$m->method('findByProject')->willReturn([]);
		$m->method('insert')->willReturnCallback(function ($c) {
			$c->setId(2);
			return $c;
		});
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('record')
			->with(1, 'carryover', 2, 'create', 'u1');
		$svc = new CarryoverService($m, $audit);
		$c = $svc->create(1, 2025, 2026, 5000, 'Bescheid 44/25', 'funder', 'u1');
		$this->assertSame(2025, $c->getFromYear());
		$this->assertSame(2026, $c->getToYear());
		$this->assertSame(5000, $c->getAmountMinor());
		$this->assertSame('Bescheid 44/25', $c->getDocRef());
	}
}
