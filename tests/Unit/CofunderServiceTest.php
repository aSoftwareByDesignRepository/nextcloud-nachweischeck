<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\Cofunder;
use OCA\NachweisCheck\Db\CofunderMapper;
use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\FinplanVersionMapper;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\CofunderService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class CofunderServiceTest extends TestCase
{
	private function svc(?CofunderMapper $m = null, ?PositionMapper $pm = null, ?FinplanVersionMapper $vm = null, ?AuditService $audit = null): array
	{
		$m = $m ?? $this->createMock(CofunderMapper::class);
		$pm = $pm ?? $this->createMock(PositionMapper::class);
		$vm = $vm ?? $this->createMock(FinplanVersionMapper::class);
		$audit = $audit ?? $this->createMock(AuditService::class);
		$guard = new ConcurrencyGuard($this->createMock(ILockingProvider::class));
		return [new CofunderService($m, $pm, $vm, $audit, $guard), $m, $pm, $vm, $audit];
	}

	public function testEmptyNameRefused(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		$svc->create(1, '   ', null, null, null, 'u1');
	}

	public function testDuplicateNameRefusedCaseInsensitive(): void
	{
		$existing = new Cofunder();
		$existing->setName('Land Berlin');
		$m = $this->createMock(CofunderMapper::class);
		$m->method('findByProject')->willReturn([$existing]);
		[$svc] = $this->svc($m);
		$this->expectException(ConflictException::class);
		$svc->create(1, 'land berlin', null, null, null, 'u1');
	}

	public function testCreateNormalizesOptionalFields(): void
	{
		$m = $this->createMock(CofunderMapper::class);
		$m->method('findByProject')->willReturn([]);
		$m->method('insert')->willReturnCallback(function ($c) {
			$c->setId(4);
			return $c;
		});
		[$svc] = $this->svc($m);
		$c = $svc->create(1, 'Stiftung X', '  ', null, '  note  ', 'u1');
		$this->assertSame('Stiftung X', $c->getName());
		$this->assertNull($c->getRef());       // whitespace-only → null
		$this->assertSame('note', $c->getNote());
	}

	public function testDeleteRefusesWhenPositionReferences(): void
	{
		$c = new Cofunder();
		$c->setId(9);
		$c->setProjectId(1);
		$c->setName('EU');
		$m = $this->createMock(CofunderMapper::class);
		$m->method('findById')->willReturn($c);

		$ver = new FinplanVersion();
		$ver->setId(2);
		$vm = $this->createMock(FinplanVersionMapper::class);
		$vm->method('findByProject')->willReturn([$ver]);

		$pos = new Position();
		$pos->setCofunderId(9);
		$pm = $this->createMock(PositionMapper::class);
		$pm->method('findByPlanVer')->willReturn([$pos]);

		[$svc] = $this->svc($m, $pm, $vm);
		$this->expectException(ConflictException::class);
		try {
			$svc->delete(1, 9, 'u1');
		} catch (ConflictException $e) {
			$this->assertSame('COFUNDER_IN_USE', $e->getErrorCode());
			throw $e;
		}
	}

	public function testDeleteSucceedsWhenUnreferenced(): void
	{
		$c = new Cofunder();
		$c->setId(9);
		$c->setProjectId(1);
		$m = $this->createMock(CofunderMapper::class);
		$m->method('findById')->willReturn($c);
		$m->expects($this->once())->method('delete');
		$vm = $this->createMock(FinplanVersionMapper::class);
		$vm->method('findByProject')->willReturn([]);
		[$svc] = $this->svc($m, null, $vm);
		$svc->delete(1, 9, 'u1');
	}

	public function testGetOrFailScopeGuard(): void
	{
		$c = new Cofunder();
		$c->setProjectId(77);
		$m = $this->createMock(CofunderMapper::class);
		$m->method('findById')->willReturn($c);
		[$svc] = $this->svc($m);
		$this->expectException(NotFoundException::class);
		$svc->getOrFail(1, 9);
	}

	public function testLabelMapIncludesLead(): void
	{
		$c = new Cofunder();
		$c->setId(3);
		$c->setName('Land');
		$m = $this->createMock(CofunderMapper::class);
		$m->method('findByProject')->willReturn([$c]);
		[$svc] = $this->svc($m);
		$map = $svc->labelMap(1, 'BMBF');
		$this->assertSame('BMBF', $map[0]);
		$this->assertSame('Land', $map[3]);
	}
}
