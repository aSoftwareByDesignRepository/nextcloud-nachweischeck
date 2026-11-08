<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\ProjectMember;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\Timesheet;
use OCA\NachweisCheck\Db\TimesheetMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCA\NachweisCheck\Service\TimesheetService;
use OCA\NachweisCheck\Service\WorkPackageService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class TimesheetServiceTest extends TestCase
{
	private function svc(?TimesheetMapper $m = null, ?TimeEntryMapper $em = null, ?WorkPackageService $aps = null): array
	{
		$m = $m ?? $this->createMock(TimesheetMapper::class);
		$em = $em ?? $this->createMock(TimeEntryMapper::class);
		$aps = $aps ?? $this->createMock(WorkPackageService::class);
		$audit = $this->createMock(AuditService::class);
		$guard = new ConcurrencyGuard($this->createMock(ILockingProvider::class));
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn($user);
		$mm = $this->createMock(ProjectMemberMapper::class);
		$mm->method('findByProjectAndUid')->willReturn(new ProjectMember());
		return [new TimesheetService($m, $em, $aps, $audit, $guard, $um, $mm), $m, $em];
	}

	private function ts(string $state, string $uid = 'u1'): Timesheet
	{
		$t = new Timesheet();
		$t->setId(1);
		$t->setProjectId(1);
		$t->setUid($uid);
		$t->setState($state);
		$t->setPeriod('2026-03');
		return $t;
	}

	public function testGenerateAggregatesMinutes(): void
	{
		[$svc, $m, $em] = $this->svc();
		$m->method('findFor')->willReturn(null);
		$m->method('insert')->willReturnCallback(function ($t) { $t->setId(1); return $t; });
		$e1 = new \OCA\NachweisCheck\Db\TimeEntry();
		$e1->setMinutes(90);
		$e2 = new \OCA\NachweisCheck\Db\TimeEntry();
		$e2->setMinutes(30);
		$em->method('findByUidPeriod')->willReturn([$e1, $e2]);
		$ts = $svc->generate(1, 'u1', '2026-03', null, 'u1');
		$this->assertSame(120, $ts->getMinutesTotal());
		$this->assertSame('draft', $ts->getState());
	}

	public function testSignOffRequiresDifferentSigner(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ForbiddenException::class);
		$svc->sign($this->ts('submitted', 'u1'), 'u1'); // self-sign forbidden
	}

	public function testSignRequiresSubmitted(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ConflictException::class);
		$svc->sign($this->ts('draft', 'u1'), 'lead');
	}

	public function testRejectNeedsReason(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ValidationException::class);
		$svc->reject($this->ts('submitted', 'u1'), 'lead', ' ');
	}

	public function testSignedTimesheetNotRegenerable(): void
	{
		$signed = $this->ts('signed', 'u1');
		$m = $this->createMock(TimesheetMapper::class);
		$m->method('findFor')->willReturn($signed);
		[$svc] = $this->svc($m);
		$this->expectException(ConflictException::class);
		$svc->generate(1, 'u1', '2026-03', null, 'u1');
	}

	public function testSubmitOnlyByOwner(): void
	{
		[$svc] = $this->svc();
		$this->expectException(ForbiddenException::class);
		$svc->submit($this->ts('draft', 'u1'), 'someone-else');
	}

	public function testGenerateRejectsUnknownUid(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn(null); // ghost uid
		$mm = $this->createMock(ProjectMemberMapper::class);
		$m = $this->createMock(TimesheetMapper::class);
		$em = $this->createMock(TimeEntryMapper::class);
		$audit = $this->createMock(AuditService::class);
		$guard = new ConcurrencyGuard($this->createMock(ILockingProvider::class));
		$svc = new TimesheetService($m, $em, $this->createMock(WorkPackageService::class), $audit, $guard, $um, $mm);
		$m->expects($this->never())->method('insert');
		try {
			$svc->generate(1, 'ghost', '2026-03', null, 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('Unknown user.', $e->getFields()['uid'] ?? null);
		}
	}
}
