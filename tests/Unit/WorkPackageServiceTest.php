<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\ApPosition;
use OCA\NachweisCheck\Db\ApPositionMapper;
use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\WorkPackage;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\WorkPackageService;
use OCP\IDBConnection;
use Test\TestCase;

class WorkPackageServiceTest extends TestCase
{
	private function svc(array $over = []): array
	{
		$m = [
			'mapper' => $this->createMock(WorkPackageMapper::class),
			'apPos' => $this->createMock(ApPositionMapper::class),
			'pos' => $this->createMock(PositionMapper::class),
			'finplan' => $this->createMock(FinplanService::class),
			'audit' => $this->createMock(AuditService::class),
			'db' => $this->createMock(IDBConnection::class),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		return ['svc' => new WorkPackageService($m['mapper'], $m['apPos'], $m['pos'],
			$m['finplan'], $m['audit'], $m['db'])] + $m;
	}

	private function ap(int $id, string $state = 'active'): WorkPackage
	{
		$ap = new WorkPackage();
		$ap->setId($id);
		$ap->setProjectId(1);
		$ap->setCode('AP' . $id);
		$ap->setState($state);
		return $ap;
	}

	private function pos(int $id): Position
	{
		$p = new Position();
		$p->setId($id);
		$p->setPlanVerId(7);
		$p->setCode('P' . $id);
		return $p;
	}

	private function activePlan(): FinplanVersion
	{
		$v = new FinplanVersion();
		$v->setId(7);
		$v->setProjectId(1);
		$v->setState('active');
		return $v;
	}

	// ---- create -----------------------------------------------------------

	public function testCreateRejectsBadCode(): void
	{
		$m = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->create(1, 'not ok!', 'Title', null, null, null, 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('code', $e->getFields());
			throw $e;
		}
	}

	public function testCreateRejectsDuplicateCode(): void
	{
		$mapper = $this->createMock(WorkPackageMapper::class);
		$mapper->method('findByProjectAndCode')->willReturn($this->ap(9));
		$mapper->expects($this->never())->method('insert');
		$m = $this->svc(['mapper' => $mapper]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->create(1, 'AP9', 'Title', null, null, null, 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('code', $e->getFields());
			throw $e;
		}
	}

	public function testCreateRejectsBlankTitleAndEndBeforeStart(): void
	{
		$m = $this->svc();
		try {
			$m['svc']->create(1, 'AP1', '   ', null, null, null, 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('title', $e->getFields());
		}
		try {
			$m['svc']->create(1, 'AP1', 'Title', null, '2026-12-01', '2026-01-01', 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('end_date', $e->getFields());
		}
	}

	public function testCreatePersistsActiveAp(): void
	{
		$mapper = $this->createMock(WorkPackageMapper::class);
		$mapper->method('findByProjectAndCode')->willReturn(null);
		$mapper->method('insert')->willReturnCallback(function ($ap) {
			$ap->setId(4);
			return $ap;
		});
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('record')
			->with(1, 'workpackage', 4, 'create', 'u1');
		$m = $this->svc(['mapper' => $mapper, 'audit' => $audit]);
		$ap = $m['svc']->create(1, 'AP1', '  Work  ', 'owner1', '2026-01-01', '2026-06-30', 'u1');
		$this->assertSame('active', $ap->getState());
		$this->assertSame('Work', $ap->getTitle());
		$this->assertSame('owner1', $ap->getOwnerUid());
	}

	// ---- booking guard ----------------------------------------------------

	public function testRequireBookableRefusesClosedAp(): void
	{
		$m = $this->svc();
		$this->expectException(ConflictException::class);
		try {
			$m['svc']->requireBookable($this->ap(5, 'closed'));
		} catch (ConflictException $e) {
			$this->assertSame('AP_CLOSED', $e->getErrorCode());
			throw $e;
		}
	}

	public function testRequireBookableAcceptsActive(): void
	{
		$m = $this->svc();
		$m['svc']->requireBookable($this->ap(5));
		$this->assertTrue(true);
	}

	public function testCloseIsIdempotent(): void
	{
		$mapper = $this->createMock(WorkPackageMapper::class);
		$mapper->expects($this->once())->method('update')->willReturnCallback(fn($ap) => $ap);
		$m = $this->svc(['mapper' => $mapper]);
		$ap = $m['svc']->close($this->ap(5), 'u1');
		$this->assertSame('closed', $ap->getState());
		$again = $m['svc']->close($ap, 'u1'); // already closed → early return
		$this->assertSame('closed', $again->getState());
	}

	// ---- positions ---------------------------------------------------------

	public function testSetPositionsRequiresActivePlan(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn(null);
		$m = $this->svc(['finplan' => $finplan]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->setPositions($this->ap(5), [10], 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('positions', $e->getFields());
			throw $e;
		}
	}

	public function testSetPositionsRejectsForeignPosition(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($this->activePlan());
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findByPlanVer')->willReturn([$this->pos(10)]);
		$m = $this->svc(['finplan' => $finplan, 'pos' => $posMapper]);
		$this->expectException(ValidationException::class);
		$m['svc']->setPositions($this->ap(5), [10, 999], 'u1');
	}

	public function testSetPositionsRewritesDeduped(): void
	{
		$finplan = $this->createMock(FinplanService::class);
		$finplan->method('activeFor')->willReturn($this->activePlan());
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findByPlanVer')->willReturn([$this->pos(10), $this->pos(11)]);
		$apPos = $this->createMock(ApPositionMapper::class);
		$apPos->expects($this->once())->method('deleteByAp')->with(5);
		$links = [];
		$apPos->expects($this->exactly(2))->method('insert')
			->willReturnCallback(function ($l) use (&$links) {
				$links[] = $l->getPositionId();
				return $l;
			});
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$m = $this->svc(['finplan' => $finplan, 'pos' => $posMapper, 'apPos' => $apPos, 'db' => $db]);
		$m['svc']->setPositions($this->ap(5), [10, 11, 10], 'u1'); // dup 10 deduped
		sort($links);
		$this->assertSame([10, 11], $links);
	}

	public function testUnmappedForListsApsWithoutPositions(): void
	{
		$mapper = $this->createMock(WorkPackageMapper::class);
		$mapper->method('findByProject')->willReturn([$this->ap(5), $this->ap(6)]);
		$apPos = $this->createMock(ApPositionMapper::class);
		$apPos->method('findByAp')->willReturnCallback(function (int $apId): array {
			if ($apId === 5) {
				$l = new ApPosition();
				$l->setPositionId(10);
				return [$l];
			}
			return [];
		});
		$m = $this->svc(['mapper' => $mapper, 'apPos' => $apPos]);
		$this->assertSame([['id' => 6, 'code' => 'AP6']], $m['svc']->unmappedFor(1));
	}

	public function testGetOrFailScopeGuard(): void
	{
		$mapper = $this->createMock(WorkPackageMapper::class);
		$ap = $this->ap(5);
		$ap->setProjectId(9);
		$mapper->method('findById')->willReturn($ap);
		$m = $this->svc(['mapper' => $mapper]);
		$this->expectException(NotFoundException::class);
		$m['svc']->getOrFail(1, 5);
	}
}
