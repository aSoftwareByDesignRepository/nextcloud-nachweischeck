<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\WorkPackage;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\RateService;
use OCA\NachweisCheck\Service\TimeEntryService;
use OCA\NachweisCheck\Service\WorkPackageService;
use Test\TestCase;

class TimeEntryServiceTest extends TestCase
{
	private function svc(array $mocks = []): array
	{
		$entryMapper = $mocks['entryMapper'] ?? $this->createMock(TimeEntryMapper::class);
		$apService = $mocks['apService'] ?? $this->createMock(WorkPackageService::class);
		$rates = $mocks['rates'] ?? $this->createMock(RateService::class);
		$audit = $mocks['audit'] ?? $this->createMock(AuditService::class);
		return [new TimeEntryService($entryMapper, $apService, $rates, $audit),
			$entryMapper, $apService, $rates, $audit];
	}

	private function ap(int $id, string $state = 'active'): WorkPackage
	{
		$ap = new WorkPackage();
		$ap->setId($id);
		$ap->setProjectId(1);
		$ap->setState($state);
		return $ap;
	}

	public function testCreateCapturesRateAtInsert(): void
	{
		[$svc, $entryMapper, $apService, $rates] = $this->svc();
		$apService->method('getOrFail')->willReturn($this->ap(5));
		$rates->method('resolve')->willReturn(6000);
		$entryMapper->method('insert')->willReturnCallback(function ($e) { $e->setId(1); return $e; });
		$e = $svc->create(1, 5, 'u1', '2026-03-10', 90, 'work', null, 'u1');
		$this->assertSame(6000, $e->getRateMinor());
		$this->assertSame(9000, $e->getAmountMinor()); // 90min * 6000/60
	}

	public function testCreateWithoutRateStaysUnpriced(): void
	{
		[$svc, $entryMapper, $apService, $rates] = $this->svc();
		$apService->method('getOrFail')->willReturn($this->ap(5));
		$rates->method('resolve')->willReturn(null);
		$entryMapper->method('insert')->willReturnCallback(function ($e) { $e->setId(1); return $e; });
		$e = $svc->create(1, 5, 'u1', '2026-03-10', 90, 'work', null, 'u1');
		$this->assertNull($e->getRateMinor());
		$this->assertNull($e->getAmountMinor());
	}

	public function testUpdateRepriceUsesStoredRateNotCurrent(): void
	{
		[$svc, $entryMapper, , $rates] = $this->svc();
		$rates->expects($this->never())->method('resolve'); // retro changes never re-resolve
		$entryMapper->method('update')->willReturnCallback(function ($e) { $e->setId(1); return $e; });
		$e = new \OCA\NachweisCheck\Db\TimeEntry();
		$e->setId(7);
		$e->setProjectId(1);
		$e->setApId(5);
		$e->setUid('u1');
		$e->setRateMinor(6000);
		$e->setAmountMinor(6000);
		$e->setEntryDate(new \DateTime('2026-03-10'));
		$e = $svc->update($e, '2026-03-10', 120, 'more', 'u1');
		$this->assertSame(6000, $e->getRateMinor());
		$this->assertSame(12000, $e->getAmountMinor());
	}

	public function testDuplicateExternalRefRefused(): void
	{
		[$svc, $entryMapper, $apService] = $this->svc();
		$apService->method('getOrFail')->willReturn($this->ap(5));
		$existing = new \OCA\NachweisCheck\Db\TimeEntry();
		$entryMapper->method('findByExternalRef')->with(1, 'imp:42')->willReturn($existing);
		$entryMapper->expects($this->never())->method('insert');
		try {
			$svc->create(1, 5, 'u1', '2026-03-10', 60, '', 'imp:42', 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('external_ref', $e->getFields());
		}
	}

	public function testEmptyExternalRefNormalizesToNull(): void
	{
		[$svc, $entryMapper, $apService] = $this->svc();
		$apService->method('getOrFail')->willReturn($this->ap(5));
		// '' must never reach the unique index — it would collide globally.
		$entryMapper->expects($this->never())->method('findByExternalRef');
		$entryMapper->method('insert')->willReturnCallback(function ($e) { $e->setId(1); return $e; });
		$e = $svc->create(1, 5, 'u1', '2026-03-10', 60, '', '   ', 'u1');
		$this->assertNull($e->getExternalRef());
	}

	public function testClosedApRefused(): void
	{
		[$svc, , $apService] = $this->svc();
		$ap = $this->ap(5, 'closed');
		$apService->method('getOrFail')->willReturn($ap);
		$apService->method('requireBookable')->willThrowException(new ConflictException('AP_CLOSED'));
		$this->expectException(ConflictException::class);
		$svc->create(1, 5, 'u1', '2026-03-10', 60, '', null, 'u1');
	}

	public function testInvalidMinutesRejected(): void
	{
		[$svc, , $apService] = $this->svc();
		$apService->method('getOrFail')->willReturn($this->ap(5));
		$this->expectException(ValidationException::class);
		$svc->create(1, 5, 'u1', '2026-03-10', 0, '', null, 'u1');
	}

	public function testGetOrFailScopeGuard(): void
	{
		$entryMapper = $this->createMock(TimeEntryMapper::class);
		$e = new \OCA\NachweisCheck\Db\TimeEntry();
		$e->setId(7);
		$e->setProjectId(2); // belongs to another project
		$entryMapper->method('findById')->willReturn($e);
		[$svc] = $this->svc(['entryMapper' => $entryMapper]);
		$this->expectException(NotFoundException::class);
		$svc->getOrFail(1, 7);
	}
}
