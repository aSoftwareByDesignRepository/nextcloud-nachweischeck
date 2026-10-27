<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\AuditorGrant;
use OCA\NachweisCheck\Db\AuditorGrantMapper;
use OCA\NachweisCheck\Db\AuditorRead;
use OCA\NachweisCheck\Db\AuditorReadMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCA\NachweisCheck\Service\AuditorReadLogger;
use OCA\NachweisCheck\Service\AuditorService;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class AuditorServiceTest extends TestCase
{
	private function svc(array $over = []): array
	{
		$m = [
			'mapper' => $this->createMock(AuditorGrantMapper::class),
			'reads' => $this->createMock(AuditorReadLogger::class),
			'audit' => $this->createMock(AuditService::class),
	
			'guard' => new ConcurrencyGuard($this->createMock(ILockingProvider::class)),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		return ['svc' => new AuditorService($m['mapper'], $m['reads'], $m['audit'], $m['guard'])] + $m;
	}

	// ---- grant validation ------------------------------------------------

	public function testGrantRequiresAccount(): void
	{
		$m = $this->svc();
		$m['mapper']->expects($this->never())->method('insert');
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->grant(1, '', 'W. Prüfer', 'Kanzlei', '2026-01-01', '2026-12-31', null, 'admin');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('nc_uid', $e->getFields());
			throw $e;
		}
	}

	public function testGrantValidatesDateShapes(): void
	{
		$m = $this->svc();
		try {
			$m['svc']->grant(1, 'aud1', 'n', 'f', '2026-13-01', '2026-12-31', null, 'admin');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('valid_from', $e->getFields());
		}
		try {
			$m['svc']->grant(1, 'aud1', 'n', 'f', '2026-01-01', 'not-a-date', null, 'admin');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('valid_to', $e->getFields());
		}
	}

	public function testGrantRejectsExpiryBeforeStart(): void
	{
		$m = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->grant(1, 'aud1', 'n', 'f', '2026-06-01', '2026-01-01', null, 'admin');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('valid_to', $e->getFields());
			throw $e;
		}
	}

	public function testGrantPersistsScopeAndWindow(): void
	{
		$mapper = $this->createMock(AuditorGrantMapper::class);
		$mapper->method('insert')->willReturnCallback(function ($g) {
			$g->setId(9);
			return $g;
		});
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('record')
			->with(1, 'auditor_grant', 9, 'grant', 'admin');
		$m = $this->svc(['mapper' => $mapper, 'audit' => $audit]);
		$g = $m['svc']->grant(1, 'aud1', 'W. Prüfer', 'Kanzlei', '2026-01-01', '2026-12-31',
			['receipts' => true, 'timesheets' => false], 'admin');
		$this->assertSame('aud1', $g->getNcUid());
		$this->assertSame('2026-01-01', $g->getValidFrom()->format('Y-m-d'));
		$this->assertSame('2026-12-31', $g->getValidTo()->format('Y-m-d'));
		$this->assertSame(['receipts' => true, 'timesheets' => false], json_decode((string)$g->getScopeJson(), true));
		$this->assertNull($g->getRevokedAt());
	}

	public function testGrantNullScopeStaysNull(): void
	{
		$mapper = $this->createMock(AuditorGrantMapper::class);
		$mapper->method('insert')->willReturnCallback(function ($g) {
			$g->setId(1);
			return $g;
		});
		$m = $this->svc(['mapper' => $mapper]);
		$g = $m['svc']->grant(1, 'aud1', 'n', 'f', '2026-01-01', '2026-01-01', null, 'admin');
		$this->assertNull($g->getScopeJson());
	}

	public function testGrantRefusesSecondLiveSeat(): void
	{
		$existing = new AuditorGrant();
		$mapper = $this->createMock(AuditorGrantMapper::class);
		$mapper->method('activeFor')->willReturn($existing);
		$mapper->expects($this->never())->method('insert');
		$m = $this->svc(['mapper' => $mapper]);
		$this->expectException(\OCA\NachweisCheck\Exception\ConflictException::class);
		try {
			$m['svc']->grant(1, 'aud1', 'n', 'f', '2026-01-01', '2026-12-31', null, 'admin');
		} catch (\OCA\NachweisCheck\Exception\ConflictException $e) {
			$this->assertSame('GRANT_EXISTS', $e->getErrorCode());
			throw $e;
		}
	}

	public function testGrantAllowsSeatAfterRevoke(): void
	{
		// activeFor returns null (revoked/expired grants don't block)
		$mapper = $this->createMock(AuditorGrantMapper::class);
		$mapper->method('activeFor')->willReturn(null);
		$mapper->method('insert')->willReturnCallback(function ($g) {
			$g->setId(5);
			return $g;
		});
		$m = $this->svc(['mapper' => $mapper]);
		$g = $m['svc']->grant(1, 'aud1', 'n', 'f', '2026-01-01', '2026-12-31', null, 'admin');
		$this->assertSame(5, $g->getId());
	}

	// ---- revoke ----------------------------------------------------------

	public function testRevokeStampsAndAuditsOnce(): void
	{
		$mapper = $this->createMock(AuditorGrantMapper::class);
		$mapper->expects($this->once())->method('update')->willReturnCallback(fn($g) => $g);
		$audit = $this->createMock(AuditService::class);
		$audit->expects($this->once())->method('record')
			->with(1, 'auditor_grant', 9, 'revoke', 'admin');
		$m = $this->svc(['mapper' => $mapper, 'audit' => $audit]);
		$g = new AuditorGrant();
		$g->setId(9);
		$g->setProjectId(1);
		$g = $m['svc']->revoke($g, 'admin');
		$this->assertNotNull($g->getRevokedAt());
		// second revoke is a no-op — no second update, no second audit row
		$m['svc']->revoke($g, 'admin');
	}

	// ---- activeGrant / getOrFail ----------------------------------------

	public function testActiveGrantDelegatesToMapper(): void
	{
		$grant = new AuditorGrant();
		$mapper = $this->createMock(AuditorGrantMapper::class);
		$mapper->method('activeFor')->with(1, 'aud1')->willReturn($grant);
		$m = $this->svc(['mapper' => $mapper]);
		$this->assertSame($grant, $m['svc']->activeGrant(1, 'aud1'));
	}

	public function testActiveGrantExpiredOrRevokedYieldsNull(): void
	{
		// expiry/revoke filtering lives in AuditorGrantMapper::activeFor —
		// the service must surface null so callers raise the uniform 404.
		$mapper = $this->createMock(AuditorGrantMapper::class);
		$mapper->method('activeFor')->willReturn(null);
		$m = $this->svc(['mapper' => $mapper]);
		$this->assertNull($m['svc']->activeGrant(1, 'aud1'));
	}

	public function testGetOrFailScopeGuard(): void
	{
		$mapper = $this->createMock(AuditorGrantMapper::class);
		$g = new AuditorGrant();
		$g->setProjectId(9);
		$mapper->method('findById')->willReturn($g);
		$m = $this->svc(['mapper' => $mapper]);
		$this->expectException(NotFoundException::class);
		$m['svc']->getOrFail(1, 9);
	}

	// ---- AuditorReadLogger ------------------------------------------------

	public function testReadLoggerWritesSequencedRow(): void
	{
		$readMapper = $this->createMock(AuditorReadMapper::class);
		$readMapper->expects($this->once())->method('insert')
			->with($this->callback(function (AuditorRead $r): bool {
				return $r->getGrantId() === 9
					&& $r->getActor() === 'aud1'
					&& $r->getRoute() === 'receipts.index'
					&& $r->getEntity() === 'receipt'
					&& $r->getEntityId() === 42
					&& $r->getTs() instanceof \DateTime;
			}));
		$logger = new AuditorReadLogger($readMapper);
		$g = new AuditorGrant();
		$g->setId(9);
		$g->setNcUid('aud1');
		$logger->log($g, 'receipts.index', 'receipt', 42);
	}
}
