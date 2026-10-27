<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\CofunderMapper;
use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\FinplanVersionMapper;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCA\NachweisCheck\Service\FinplanService;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class FinplanServiceTest extends TestCase
{
	private function svc(array $over = []): array
	{
		$m = [
			'ver' => $this->createMock(FinplanVersionMapper::class),
			'pos' => $this->createMock(PositionMapper::class),
			'cof' => $this->createMock(CofunderMapper::class),
			'audit' => $this->createMock(AuditService::class),
			'db' => $this->createMock(IDBConnection::class),
			'guard' => new ConcurrencyGuard($this->createMock(ILockingProvider::class)),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		return ['svc' => new FinplanService($m['ver'], $m['pos'], $m['cof'], $m['audit'], $m['db'], $m['guard'])] + $m;
	}

	private function ver(int $id, int $version, string $state, ?int $base = null): FinplanVersion
	{
		$v = new FinplanVersion();
		$v->setId($id);
		$v->setProjectId(1);
		$v->setVersion($version);
		$v->setState($state);
		$v->setBaseVersion($base);
		return $v;
	}

	private function pos(int $id, int $planVerId, string $code, int $budget = 1000): Position
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

	// ---- activate: CAS -------------------------------------------------

	public function testActivateRefusesMissingBaseToken(): void
	{
		$m = $this->svc();
		$this->expectException(ConflictException::class);
		try {
			$m['svc']->activate($this->ver(2, 2, 'draft'), null, 'u1');
		} catch (ConflictException $e) {
			$this->assertSame('STALE_BASE', $e->getErrorCode());
			throw $e;
		}
	}

	public function testActivateRefusesStaleBase(): void
	{
		$verMapper = $this->createMock(FinplanVersionMapper::class);
		$verMapper->method('activeFor')->willReturn($this->ver(1, 3, 'active'));
		$verMapper->expects($this->never())->method('update');
		$m = $this->svc(['ver' => $verMapper]);
		$this->expectException(ConflictException::class);
		try {
			$m['svc']->activate($this->ver(4, 4, 'draft', 2), 2, 'u1');
		} catch (ConflictException $e) {
			$this->assertSame('STALE_BASE', $e->getErrorCode());
			throw $e;
		}
	}

	public function testActivateRefusesNonDraft(): void
	{
		$m = $this->svc();
		$this->expectException(ConflictException::class);
		try {
			$m['svc']->activate($this->ver(1, 1, 'active'), 1, 'u1');
		} catch (ConflictException $e) {
			$this->assertSame('INVALID_STATE', $e->getErrorCode());
			throw $e;
		}
	}

	public function testActivateRefusesEmptyPlan(): void
	{
		$verMapper = $this->createMock(FinplanVersionMapper::class);
		$verMapper->method('activeFor')->willReturn($this->ver(1, 1, 'active'));
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findByPlanVer')->willReturn([]);
		$m = $this->svc(['ver' => $verMapper, 'pos' => $posMapper]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->activate($this->ver(2, 2, 'draft', 1), 1, 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('positions', $e->getFields());
			throw $e;
		}
	}

	public function testActivateSupersedesCurrentAndCommits(): void
	{
		$current = $this->ver(1, 1, 'active');
		$verMapper = $this->createMock(FinplanVersionMapper::class);
		$verMapper->method('activeFor')->willReturn($current);
		$verMapper->expects($this->exactly(2))->method('update')
			->willReturnCallback(fn($v) => $v);
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findByPlanVer')->willReturn([$this->pos(10, 2, 'PK')]);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$db->expects($this->never())->method('rollBack');
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('record')
			->with(1, 'finplan_version', 2, 'activate', 'u1');
		$m = $this->svc(['ver' => $verMapper, 'pos' => $posMapper, 'db' => $db, 'audit' => $audit]);
		$v = $m['svc']->activate($this->ver(2, 2, 'draft', 1), 1, 'u1');
		$this->assertSame('active', $v->getState());
		$this->assertSame('superseded', $current->getState());
		$this->assertNotNull($v->getActivatedAt());
	}

	public function testFirstActivationExpectsBaseZero(): void
	{
		$verMapper = $this->createMock(FinplanVersionMapper::class);
		$verMapper->method('activeFor')->willReturn(null); // no active version yet
		$verMapper->method('update')->willReturnCallback(fn($v) => $v);
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findByPlanVer')->willReturn([$this->pos(10, 1, 'PK')]);
		$m = $this->svc(['ver' => $verMapper, 'pos' => $posMapper]);
		// base 1 while nothing is active → CAS mismatch (expected 0)
		$this->expectException(ConflictException::class);
		$m['svc']->activate($this->ver(1, 1, 'draft'), 1, 'u1');
	}

	// ---- newDraft ------------------------------------------------------

	public function testNewDraftReturnsExistingDraft(): void
	{
		$draft = $this->ver(5, 2, 'draft');
		$verMapper = $this->createMock(FinplanVersionMapper::class);
		$verMapper->method('findByProject')->willReturn([$draft]);
		$verMapper->expects($this->never())->method('insert');
		$m = $this->svc(['ver' => $verMapper]);
		$this->assertSame($draft, $m['svc']->newDraft(1, 'u1'));
	}

	public function testNewDraftClonesActivePositions(): void
	{
		$active = $this->ver(1, 1, 'active');
		$verMapper = $this->createMock(FinplanVersionMapper::class);
		$verMapper->method('findByProject')->willReturn([$active]);
		$verMapper->method('activeFor')->willReturn($active);
		$verMapper->method('maxVersion')->willReturn(1);
		$verMapper->method('insert')->willReturnCallback(function ($v) {
			$v->setId(9);
			return $v;
		});
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findByPlanVer')->with(1)->willReturn([
			$this->pos(10, 1, 'PK', 5000),
			$this->pos(11, 1, 'SK', 3000),
		]);
		$cloned = [];
		$posMapper->expects($this->exactly(2))->method('insert')
			->willReturnCallback(function ($p) use (&$cloned) {
				$cloned[] = $p;
				return $p;
			});
		$m = $this->svc(['ver' => $verMapper, 'pos' => $posMapper]);
		$draft = $m['svc']->newDraft(1, 'u1', 'rework');
		$this->assertSame(2, $draft->getVersion());
		$this->assertSame('draft', $draft->getState());
		$this->assertSame(1, $draft->getBaseVersion());
		$this->assertSame('rework', $draft->getNote());
		$this->assertSame('PK', $cloned[0]->getCode());
		$this->assertSame(9, $cloned[0]->getPlanVerId());
		$this->assertSame(5000, $cloned[0]->getBudgetMinor());
		$this->assertSame('SK', $cloned[1]->getCode());
	}

	public function testNewDraftWithoutAnyVersionStartsEmpty(): void
	{
		$verMapper = $this->createMock(FinplanVersionMapper::class);
		$verMapper->method('findByProject')->willReturn([]);
		$verMapper->method('activeFor')->willReturn(null);
		$verMapper->method('maxVersion')->willReturn(0);
		$verMapper->method('insert')->willReturnCallback(function ($v) {
			$v->setId(1);
			return $v;
		});
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->expects($this->never())->method('insert');
		$m = $this->svc(['ver' => $verMapper, 'pos' => $posMapper]);
		$draft = $m['svc']->newDraft(1, 'u1');
		$this->assertSame(1, $draft->getVersion());
		$this->assertNull($draft->getBaseVersion());
	}

	// ---- replacePositions ----------------------------------------------

	public function testReplacePositionsRefusesNonDraft(): void
	{
		$m = $this->svc();
		$this->expectException(ConflictException::class);
		try {
			$m['svc']->replacePositions($this->ver(1, 1, 'active'), [
				['code' => 'PK', 'title' => 'P', 'kind' => 'sach', 'budget_minor' => 1],
			], 'u1');
		} catch (ConflictException $e) {
			$this->assertSame('IMMUTABLE_PLAN', $e->getErrorCode());
			throw $e;
		}
	}

	public function testReplacePositionsRejectsEmptySet(): void
	{
		$m = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->replacePositions($this->ver(2, 2, 'draft'), [], 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('positions', $e->getFields());
			throw $e;
		}
	}

	public function testReplacePositionsRejectsDuplicateCodes(): void
	{
		$m = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->replacePositions($this->ver(2, 2, 'draft'), [
				['code' => 'PK', 'title' => 'A', 'kind' => 'sach', 'budget_minor' => 1],
				['code' => 'PK', 'title' => 'B', 'kind' => 'sach', 'budget_minor' => 1],
			], 'u1');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('positions.1.code', $e->getFields());
			throw $e;
		}
	}

	public function testReplacePositionsRejectsInvalidKindAndBudget(): void
	{
		$m = $this->svc();
		try {
			$m['svc']->replacePositions($this->ver(2, 2, 'draft'), [
				['code' => 'PK', 'title' => 'A', 'kind' => 'bogus', 'budget_minor' => 1],
			], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('positions.0.kind', $e->getFields());
		}
		try {
			$m['svc']->replacePositions($this->ver(2, 2, 'draft'), [
				['code' => 'PK', 'title' => 'A', 'kind' => 'sach', 'budget_minor' => -5],
			], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('positions.0.budget_minor', $e->getFields());
		}
	}

	public function testReplacePositionsSwapsAtomicallyWithSortOrder(): void
	{
		$posMapper = $this->createMock(PositionMapper::class);
		$posMapper->method('findByPlanVer')->willReturn([$this->pos(10, 2, 'OLD')]);
		$posMapper->expects($this->once())->method('delete');
		$inserted = [];
		$posMapper->expects($this->exactly(2))->method('insert')
			->willReturnCallback(function ($p) use (&$inserted) {
				$inserted[] = $p;
				return $p;
			});
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$m = $this->svc(['pos' => $posMapper, 'db' => $db]);
		$m['svc']->replacePositions($this->ver(2, 2, 'draft'), [
			['code' => ' SK ', 'title' => 'Sach', 'kind' => 'sach', 'budget_minor' => 200],
			['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal', 'budget_minor' => 800],
		], 'u1');
		$this->assertSame('SK', $inserted[0]->getCode()); // trimmed
		$this->assertSame(0, $inserted[0]->getSort());
		$this->assertSame(1, $inserted[1]->getSort());
		$this->assertSame(2, $inserted[1]->getPlanVerId());
	}

	// ---- getVersionOrFail ----------------------------------------------

	public function testGetVersionOrFailCrossProjectCollapsesTo404(): void
	{
		$verMapper = $this->createMock(FinplanVersionMapper::class);
		$v = $this->ver(7, 1, 'draft');
		$v->setProjectId(99); // other project
		$verMapper->method('findById')->willReturn($v);
		$m = $this->svc(['ver' => $verMapper]);
		$this->expectException(NotFoundException::class);
		$m['svc']->getVersionOrFail(1, 7);
	}
}
