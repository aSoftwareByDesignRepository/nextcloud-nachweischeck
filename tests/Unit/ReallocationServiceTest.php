<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\Reallocation;
use OCA\NachweisCheck\Db\ReallocationMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\ReallocationService;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class ReallocationServiceTest extends TestCase
{
	private function svc(array $over = []): array
	{
		$m = [
			'mapper' => $this->createMock(ReallocationMapper::class),
			'finplan' => $this->createMock(FinplanService::class),
			'pos' => $this->createMock(PositionMapper::class),
			'audit' => $this->createMock(AuditService::class),
	
			'guard' => new ConcurrencyGuard($this->createMock(ILockingProvider::class)),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		return ['svc' => new ReallocationService($m['mapper'], $m['finplan'], $m['pos'], $m['audit'], $m['guard'])] + $m;
	}

	private function plan(int $id = 7): FinplanVersion
	{
		$v = new FinplanVersion();
		$v->setId($id);
		$v->setProjectId(1);
		$v->setVersion(3);
		$v->setState('active');
		return $v;
	}

	private function pos(int $id, int $planVerId, string $code, int $budget = 10000): Position
	{
		$p = new Position();
		$p->setId($id);
		$p->setPlanVerId($planVerId);
		$p->setCode($code);
		$p->setTitle('T-' . $code);
		$p->setKind('sach');
		$p->setBudgetMinor($budget);
		$p->setSort(0);
		return $p;
	}

	private function pending(int $id = 4): Reallocation
	{
		$r = new Reallocation();
		$r->setId($id);
		$r->setProjectId(1);
		$r->setPlanVerId(7);
		$r->setFromPositionId(10);
		$r->setToPositionId(11);
		$r->setAmountMinor(2000);
		$r->setReasonText('why');
		$r->setState('pending');
		$r->setUid('u1');
		return $r;
	}

	private function mapperReturning(Reallocation $r): ReallocationMapper
	{
		$mapper = $this->createMock(ReallocationMapper::class);
		$mapper->method('findById')->willReturn($r);
		return $mapper;
	}

	// ---- request --------------------------------------------------------

	public function testRequestRequiresActivePlan(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn(null);
		$m = $this->svc(['finplan' => $finplan]);
		$this->expectException(ValidationException::class);
		$m['svc']->request(1, 10, 11, 100, 'reason', 'u1');
	}

	public function testRequestRequiresReason(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($this->plan());
		$mapper = $this->createMock(ReallocationMapper::class);
		$mapper->expects($this->never())->method('insert');
		$m = $this->svc(['finplan' => $finplan, 'mapper' => $mapper]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->request(1, 10, 11, 100, '   ', 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('reason_text', $e->getFields());
			throw $e;
		}
	}

	public function testRequestRejectsSameSourceAndTarget(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($this->plan());
		$m = $this->svc(['finplan' => $finplan]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->request(1, 10, 10, 100, 'reason', 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('to_position_id', $e->getFields());
			throw $e;
		}
	}

	public function testRequestRejectsNonPositiveAmount(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($this->plan());
		$m = $this->svc(['finplan' => $finplan]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->request(1, 10, 11, 0, 'reason', 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('amount_minor', $e->getFields());
			throw $e;
		}
	}

	public function testRequestRejectsPositionOutsidePlan(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($this->plan());
		$finplan->method('positions')->willReturn([$this->pos(10, 7, 'PK')]);
		$m = $this->svc(['finplan' => $finplan]);
		$this->expectException(NotFoundException::class);
		$m['svc']->request(1, 10, 99, 100, 'reason', 'u1');
	}

	public function testRequestPersistsPending(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($this->plan());
		$finplan->method('positions')->willReturn([
			$this->pos(10, 7, 'PK'), $this->pos(11, 7, 'SK'),
		]);
		$mapper = $this->createMock(ReallocationMapper::class);
		$mapper->method('insert')->willReturnCallback(function ($r) {
			$r->setId(4);
			return $r;
		});
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('record')
			->with(1, 'reallocation', 4, 'request', 'u1');
		$m = $this->svc(['finplan' => $finplan, 'mapper' => $mapper, 'audit' => $audit]);
		$r = $m['svc']->request(1, 10, 11, 2000, '  Bedarf verschoben ', 'u1');
		$this->assertSame('pending', $r->getState());
		$this->assertSame(7, $r->getPlanVerId());
		$this->assertSame('Bedarf verschoben', $r->getReasonText()); // trimmed
		$this->assertSame('u1', $r->getUid());
	}

	// ---- approve --------------------------------------------------------

	public function testApproveRefusesDecided(): void
	{
		$r = $this->pending();
		$r->setState('applied');
		$m = $this->svc(['mapper' => $this->mapperReturning($r)]);
		$this->expectException(ConflictException::class);
		$m['svc']->approve($r, 'admin');
	}

	public function testApproveRefusesStalePositions(): void
	{
		// from-position id 10 no longer belongs to plan version 7
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findById')->willReturnCallback(function (int $id) {
			return $id === 10 ? $this->pos(10, 99, 'PK') : $this->pos(11, 7, 'SK');
		});
		$m = $this->svc(['pos' => $posMapper, 'mapper' => $this->mapperReturning($this->pending())]);
		$this->expectException(ConflictException::class);
		try {
			$m['svc']->approve($this->pending(), 'admin');
		} catch (ConflictException $e) {
			$this->assertSame('STALE_POSITIONS', $e->getErrorCode());
			throw $e;
		}
	}

	public function testApproveMintsNewPlanVersionWithMovedBudget(): void
	{
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findById')->willReturnCallback(function (int $id) {
			return $id === 10 ? $this->pos(10, 7, 'PK') : $this->pos(11, 7, 'SK');
		});
		$draft = new FinplanVersion();
		$draft->setId(8);
		$draft->setProjectId(1);
		$draft->setVersion(4);
		$draft->setState('draft');
		$draft->setBaseVersion(3);
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('newDraft')->with(1, 'admin', 'Umschichtung #4')->willReturn($draft);
		$finplan->method('positions')->with($draft)->willReturn([
			$this->pos(20, 8, 'PK', 10000),
			$this->pos(21, 8, 'SK', 5000),
			$this->pos(22, 8, 'RK', 700),
		]);
		$finplan->expects($this->once())->method('replacePositions')
			->with($draft, $this->callback(function (array $positions): bool {
				$byCode = array_column($positions, 'budget_minor', 'code');
				return ($byCode['PK'] ?? null) === 8000      // 10000 - 2000
					&& ($byCode['SK'] ?? null) === 7000      // 5000 + 2000
					&& ($byCode['RK'] ?? null) === 700;      // untouched
			}), 'admin');
		$finplan->expects($this->once())->method('activate')->with($draft, 3, 'admin');
		$mapper = $this->mapperReturning($this->pending());
		$mapper->method('update')->willReturnCallback(fn($r) => $r);
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('record')
			->with(1, 'reallocation', 4, 'approve', 'admin', null, ['new_plan_version' => 4]);
		$m = $this->svc(['finplan' => $finplan, 'pos' => $posMapper, 'mapper' => $mapper, 'audit' => $audit]);
		$r = $m['svc']->approve($this->pending(), 'admin');
		$this->assertSame('applied', $r->getState());
		$this->assertSame('admin', $r->getApprovedBy());
		$this->assertNotNull($r->getDecidedAt());
	}

	public function testApproveRefusesOverdraw(): void
	{
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findById')->willReturnCallback(function (int $id) {
			return $id === 10 ? $this->pos(10, 7, 'PK') : $this->pos(11, 7, 'SK');
		});
		$draft = new FinplanVersion();
		$draft->setId(8);
		$draft->setProjectId(1);
		$draft->setState('draft');
		$draft->setBaseVersion(3);
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('newDraft')->willReturn($draft);
		// source position only holds 1500 — moving 2000 would go negative
		$finplan->method('positions')->willReturn([$this->pos(20, 8, 'PK', 1500)]);
		$finplan->expects($this->never())->method('activate');
		$m = $this->svc(['finplan' => $finplan, 'pos' => $posMapper, 'mapper' => $this->mapperReturning($this->pending())]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->approve($this->pending(), 'admin');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('amount_minor', $e->getFields());
			throw $e;
		}
	}

	// ---- reject / getOrFail ---------------------------------------------

	public function testRejectMarksDecidedWithoutPlanChange(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->expects($this->never())->method('newDraft');
		$mapper = $this->mapperReturning($this->pending());
		$mapper->method('update')->willReturnCallback(fn($r) => $r);
		$m = $this->svc(['finplan' => $finplan, 'mapper' => $mapper]);
		$r = $m['svc']->reject($this->pending(), 'admin');
		$this->assertSame('rejected', $r->getState());
		$this->assertSame('admin', $r->getApprovedBy());
	}

	public function testRejectRefusesDecided(): void
	{
		$r = $this->pending();
		$r->setState('rejected');
		$m = $this->svc(['mapper' => $this->mapperReturning($r)]);
		$this->expectException(ConflictException::class);
		$m['svc']->reject($r, 'admin');
	}

	public function testGetOrFailScopeGuard(): void
	{
		$mapper = $this->createMock(ReallocationMapper::class);
		$r = new Reallocation();
		$r->setProjectId(9);
		$mapper->method('findById')->willReturn($r);
		$m = $this->svc(['mapper' => $mapper]);
		$this->expectException(NotFoundException::class);
		$m['svc']->getOrFail(1, 4);
	}
}
