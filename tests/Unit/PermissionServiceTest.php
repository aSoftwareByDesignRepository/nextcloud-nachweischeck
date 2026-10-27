<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\AuditorGrantMapper;
use OCA\NachweisCheck\Db\ProjectMember;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\AuditorReadLogger;
use OCA\NachweisCheck\Service\PermissionService;
use Test\TestCase;

class PermissionServiceTest extends TestCase
{
	private function svc(?string $role, $grant = null): array
	{
		$members = $this->createMock(ProjectMemberMapper::class);
		$m = null;
		if ($role !== null) {
			$m = new ProjectMember();
			$m->setRole($role);
		}
		$members->method('findByProjectAndUid')->willReturn($m);
		$grants = $this->createMock(AuditorGrantMapper::class);
		$grants->method('activeFor')->willReturn($grant);
		$door = $this->createMock(AccessDoorService::class);
		$reads = $this->createMock(AuditorReadLogger::class);
		return [new PermissionService($members, $grants, $door, $reads), $reads];
	}

	public function testMemberCannotSeeRates(): void
	{
		[$svc] = $this->svc('member');
		$this->assertFalse($svc->canSeeRates(1, 'u'));
	}

	public function testFinanceSeesRates(): void
	{
		[$svc] = $this->svc('finance');
		$this->assertTrue($svc->canSeeRates(1, 'u'));
	}

	public function testOutOfScopeCollapsesTo404(): void
	{
		[$svc] = $this->svc(null);
		$this->expectException(NotFoundException::class);
		$svc->requireView(1, 'stranger');
	}

	public function testMemberBlockedFromFinance(): void
	{
		[$svc] = $this->svc('member');
		$this->expectException(ForbiddenException::class);
		$svc->requireFinance(1, 'u');
	}

	public function testNonAdminBlockedFromAdmin(): void
	{
		[$svc] = $this->svc('finance');
		$this->expectException(ForbiddenException::class);
		$svc->requireAdmin(1, 'u');
	}

	public function testAuditorCanViewAndReadLogged(): void
	{
		$grant = new \OCA\NachweisCheck\Db\AuditorGrant();
		$grant->setId(7);
		$grant->setNcUid('aud1');
		[$svc, $reads] = $this->svc(null, $grant);
		$reads->expects($this->once())->method('log')->with($grant);
		$svc->requireView(1, 'aud1');
	}

	public function testAuditorCannotBook(): void
	{
		$grant = new \OCA\NachweisCheck\Db\AuditorGrant();
		[$svc] = $this->svc(null, $grant);
		$this->expectException(NotFoundException::class);
		$svc->requireBook(1, 'aud1');
	}
}
