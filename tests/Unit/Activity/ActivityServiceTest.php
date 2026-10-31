<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Activity;

use OCA\NachweisCheck\Activity\ActivityService;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\ProjectMapper;
use OCA\NachweisCheck\Db\ProjectMember;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ActivityServiceTest extends TestCase
{
	private function member(string $uid, string $role): ProjectMember
	{
		$m = new ProjectMember();
		$m->setUid($uid);
		$m->setRole($role);
		return $m;
	}

	/** @return array{0:ActivityService,1:IManager&MockObject,2:ProjectMemberMapper&MockObject} */
	private function svc(array $members = [], string $projectName = 'Förderprojekt'): array
	{
		$activity = $this->createMock(IManager::class);
		$membersMapper = $this->createMock(ProjectMemberMapper::class);
		$membersMapper->method('findByProject')->willReturn($members);
		$projects = $this->createMock(ProjectMapper::class);
		$p = new Project();
		$p->setName($projectName);
		$projects->method('findById')->willReturn($p);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud/apps/nachweischeck/');
		$logger = $this->createMock(LoggerInterface::class);
		return [new ActivityService($activity, $membersMapper, $projects, $url, $logger), $activity, $membersMapper];
	}

	public function testWhitelistedEventReachesEveryMember(): void
	{
		$members = [$this->member('admin', 'admin'), $this->member('fin', 'finance'), $this->member('m1', 'member')];
		[$svc, $activity] = $this->svc($members);
		$event = $this->createMock(IEvent::class);
		$event->method('setApp')->willReturnSelf();
		$event->method('setType')->willReturnSelf();
		$event->method('setAffectedUser')->willReturnSelf();
		$event->method('setAuthor')->willReturnSelf();
		$event->method('setSubject')->willReturnSelf();
		$event->method('setObject')->willReturnSelf();
		$event->method('setLink')->willReturnSelf();
		$activity->expects($this->exactly(3))->method('generateEvent')->willReturn($event);
		$activity->expects($this->exactly(3))->method('publish')->with($event);
		$svc->onAuditEvent(7, 'nachweis', 42, 'generate', 'admin', ['kind' => 'final', 'version' => 2]);
	}

	public function testNonWhitelistedActionEmitsNothing(): void
	{
		[$svc, $activity] = $this->svc([$this->member('a', 'admin')]);
		$activity->expects($this->never())->method('generateEvent');
		$svc->onAuditEvent(7, 'receipt', 5, 'update', 'a', null);
		$svc->onAuditEvent(7, 'time_entry', 5, 'create', 'a', null);
		$svc->onAuditEvent(7, 'rate_history', 5, 'create', 'a', null);
	}

	public function testNullProjectEmitsNothing(): void
	{
		[$svc, $activity] = $this->svc();
		$activity->expects($this->never())->method('generateEvent');
		$svc->onAuditEvent(null, 'access_door', 0, 'save', 'a', null);
	}

	public function testFinanceOnlyEventsSkipMembers(): void
	{
		$members = [$this->member('admin', 'admin'), $this->member('m1', 'member')];
		[$svc, $activity] = $this->svc($members);
		$event = $this->createMock(IEvent::class);
		$event->method('setApp')->willReturnSelf();
		$event->method('setType')->willReturnSelf();
		$event->method('setAffectedUser')->willReturnSelf();
		$event->method('setAuthor')->willReturnSelf();
		$event->method('setSubject')->willReturnSelf();
		$event->method('setObject')->willReturnSelf();
		$event->method('setLink')->willReturnSelf();
		// Only the admin member gets the event — the plain member must never
		// be notified of a deviation (would leak finance-only facts).
		$activity->expects($this->once())->method('generateEvent')->willReturn($event);
		$activity->expects($this->once())->method('publish');
		$svc->onAuditEvent(7, 'deviation', 9, 'create', 'fin', ['scope' => 'position', 'kind' => 'cost', 'reason_text' => 'SECRET']);
	}

	public function testPublishFailureIsSwallowedAndLogged(): void
	{
		[$svc, $activity] = $this->svc([$this->member('a', 'admin')]);
		$activity->method('generateEvent')->willThrowException(new \RuntimeException('activity down'));
		$svc->onAuditEvent(7, 'project', 7, 'activate', 'a', ['state' => 'active']);
		$this->addToAssertionCount(1); // no exception propagated
	}

	public function testReasonTextNeverReachesSubjectParams(): void
	{
		[$svc, $activity] = $this->svc([$this->member('a', 'admin')]);
		$captured = null;
		$event = $this->createMock(IEvent::class);
		foreach (['setApp','setType','setAffectedUser','setAuthor','setObject','setLink'] as $m) {
			$event->method($m)->willReturnSelf();
		}
		$event->method('setSubject')->willReturnCallback(function ($s, $p) use (&$captured, $event) {
			$captured = [$s, $p];
			return $event;
		});
		$activity->method('generateEvent')->willReturn($event);
		$svc->onAuditEvent(7, 'deviation', 9, 'create', 'fin', ['scope' => 'position', 'kind' => 'cost', 'reason_text' => 'SECRET-SALARY-HINT']);
		$this->assertSame('deviation_created', $captured[0]);
		$this->assertStringNotContainsString('SECRET', json_encode($captured[1]));
	}
}
