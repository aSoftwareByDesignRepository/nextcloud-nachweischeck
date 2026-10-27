<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\FinplanVersionMapper;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\ProjectMapper;
use OCA\NachweisCheck\Db\ProjectMember;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\LicenseException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\ConcurrencyGuard;
use OCA\NachweisCheck\Service\DeadlineService;
use OCA\NachweisCheck\Service\LicenseService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\ProjectService;
use OCA\NachweisCheck\Service\TemplateService;
use OCA\NachweisCheck\Service\WorkPackageService;
use OCP\Lock\ILockingProvider;
use Test\TestCase;

class ProjectServiceTest extends TestCase
{
	private function svc(array $over = []): array
	{
		$m = [
			'projects' => $this->createMock(ProjectMapper::class),
			'members' => $this->createMock(ProjectMemberMapper::class),
			'planVers' => $this->createMock(FinplanVersionMapper::class),
			'positions' => $this->createMock(PositionMapper::class),
			'templates' => $this->createMock(TemplateService::class),
			'license' => $this->createMock(LicenseService::class),
			'audit' => $this->createMock(AuditService::class),
			'deadlines' => $this->createMock(DeadlineService::class),
			'workPackages' => $this->createMock(WorkPackageService::class),
			'guard' => new ConcurrencyGuard($this->createMock(ILockingProvider::class)),
		];
		foreach ($over as $k => $v) {
			$m[$k] = $v;
		}
		return ['svc' => new ProjectService($m['projects'], $m['members'], $m['planVers'],
			$m['positions'], $m['templates'], $m['license'], $m['audit'], $m['deadlines'],
			$m['workPackages'], $m['guard'])] + $m;
	}

	private function project(string $state = 'draft'): Project
	{
		$p = new Project();
		$p->setId(1);
		$p->setName('P');
		$p->setTemplateKey('anbest-p');
		$p->setFinancingMode('ausgaben');
		$p->setState($state);
		return $p;
	}

	// ---- create -----------------------------------------------------------

	public function testCreateRequiresName(): void
	{
		$m = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->create('u1', '   ', 'F', 'R', null, null, 'anbest-p', 'ausgaben', 'U One');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('name', $e->getFields());
			throw $e;
		}
	}

	public function testCreateRejectsEndBeforeStart(): void
	{
		$m = $this->svc();
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->create('u1', 'P', 'F', 'R', '2026-12-31', '2026-01-01', 'anbest-p', 'ausgaben', 'U One');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('bew_end', $e->getFields());
			throw $e;
		}
	}

	public function testCreateUnknownTemplateIsValidationNot404(): void
	{
		$templates = $this->createMock(TemplateService::class);
		$templates->method('definition')->willThrowException(new NotFoundException());
		$m = $this->svc(['templates' => $templates]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->create('u1', 'P', 'F', 'R', null, null, 'nope', 'ausgaben', 'U One');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('template_key', $e->getFields());
			throw $e;
		}
	}

	public function testCreateNonBuiltinTemplateRequiresLicense(): void
	{
		$templates = $this->createMock(TemplateService::class);
		$templates->method('definition')->willReturn([
			'key' => 'land-nebenbest', 'name' => 'Land', 'source' => 'pack', 'skeleton' => [],
		]);
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->once())->method('requireFeature')
			->with(LicenseService::FEATURE_TEMPLATES)
			->willThrowException(new LicenseException(LicenseService::FEATURE_TEMPLATES));
		$projects = $this->createMock(ProjectMapper::class);
		$projects->expects($this->never())->method('insert');
		$m = $this->svc(['templates' => $templates, 'license' => $license, 'projects' => $projects]);
		$this->expectException(LicenseException::class);
		$m['svc']->create('u1', 'P', 'F', 'R', null, null, 'land-nebenbest', 'ausgaben', 'U One');
	}

	public function testCreateSeedsMemberDraftPlanAndSkeleton(): void
	{
		$templates = $this->createMock(TemplateService::class);
		$templates->method('definition')->willReturn(TemplateService::BUILTIN[TemplateService::KEY_ANBEST_P]
			+ ['key' => 'anbest-p', 'source' => 'builtin']);
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('requireFeature'); // builtin key skips the gate
		$projects = $this->createMock(ProjectMapper::class);
		$projects->method('insert')->willReturnCallback(function ($p) {
			$p->setId(7);
			return $p;
		});
		$members = $this->createMock(ProjectMemberMapper::class);
		$members->expects($this->once())->method('insert')
			->with($this->callback(fn(ProjectMember $m): bool => $m->getProjectId() === 7
				&& $m->getUid() === 'u1'
				&& $m->getRole() === PermissionService::ROLE_ADMIN));
		$planVers = $this->createMock(FinplanVersionMapper::class);
		$planVers->expects($this->once())->method('insert')
			->with($this->callback(fn(FinplanVersion $v): bool => $v->getVersion() === 1
				&& $v->getState() === 'draft'))
			->willReturnCallback(function ($v) {
				$v->setId(3);
				return $v;
			});
		$positions = $this->createMock(PositionMapper::class);
		$positions->expects($this->exactly(6))->method('insert'); // anbest-p skeleton size
		$m = $this->svc(['templates' => $templates, 'license' => $license, 'projects' => $projects,
			'members' => $members, 'planVers' => $planVers, 'positions' => $positions]);
		$p = $m['svc']->create('u1', '  Projekt X  ', 'Funder', 'AZ-1', '2026-01-01', '2026-12-31',
			'anbest-p', 'ausgaben', 'U One');
		$this->assertSame('draft', $p->getState());
		$this->assertSame('Projekt X', $p->getName()); // trimmed
		$this->assertSame(10, $p->getRetentionYears());
	}

	// ---- activate ---------------------------------------------------------

	public function testActivateRefusesNonDraft(): void
	{
		$m = $this->svc();
		$this->expectException(ConflictException::class);
		$m['svc']->activate($this->project('active'), 'admin');
	}

	public function testActivateRequiresActivePlan(): void
	{
		$planVers = $this->createMock(FinplanVersionMapper::class);
		$planVers->method('activeFor')->willReturn(null);
		$m = $this->svc(['planVers' => $planVers]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->activate($this->project(), 'admin');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('finplan', $e->getFields());
			throw $e;
		}
	}

	public function testActivateBlockedByUnmappedWorkPackages(): void
	{
		$planVers = $this->createMock(FinplanVersionMapper::class);
		$v = new FinplanVersion();
		$v->setId(3);
		$planVers->method('activeFor')->willReturn($v);
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->never())->method('checkActiveProjectLimit');
		$workPackages = $this->createMock(WorkPackageService::class);
		$workPackages->method('unmappedFor')->willReturn([
			['id' => 5, 'code' => 'AP1'], ['id' => 6, 'code' => 'AP2'],
		]);
		$m = $this->svc(['planVers' => $planVers, 'license' => $license, 'workPackages' => $workPackages]);
		$this->expectException(ValidationException::class);
		try {
			$m['svc']->activate($this->project(), 'admin');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('work_packages', $e->getFields());
			$this->assertStringContainsString('AP1', $e->getFields()['work_packages']);
			throw $e;
		}
	}

	public function testActivateEnforcesProjectLimitThenRecomputes(): void
	{
		$planVers = $this->createMock(FinplanVersionMapper::class);
		$v = new FinplanVersion();
		$v->setId(3);
		$planVers->method('activeFor')->willReturn($v);
		$license = $this->createMock(LicenseService::class);
		$license->expects($this->once())->method('checkActiveProjectLimit')->with(2);
		$projects = $this->createMock(ProjectMapper::class);
		$projects->method('update')->willReturnCallback(fn($p) => $p);
		// The service counts the actor's OTHER active projects itself, inside
		// the activation lock (TOCTOU fix): two already-active ones here.
		$other1 = new Project();
		$other1->setId(91);
		$other1->setState('active');
		$other2 = new Project();
		$other2->setId(92);
		$other2->setState('active');
		$draftSelf = new Project();
		$draftSelf->setId(1);
		$draftSelf->setState('draft');
		$projects->method('findForUid')->with('admin')->willReturn([$other1, $other2, $draftSelf]);
		$deadlines = $this->createMock(DeadlineService::class);
		$deadlines->expects($this->once())->method('recompute');
		$m = $this->svc(['planVers' => $planVers, 'license' => $license,
			'projects' => $projects, 'deadlines' => $deadlines]);
		$p = $m['svc']->activate($this->project(), 'admin');
		$this->assertSame('active', $p->getState());
	}

	// ---- transition -------------------------------------------------------

	public function testTransitionFollowsStateMachine(): void
	{
		$projects = $this->createMock(ProjectMapper::class);
		$projects->method('update')->willReturnCallback(fn($p) => $p);
		$m = $this->svc(['projects' => $projects]);
		$p = $this->project('active');
		$p = $m['svc']->transition($p, 'closing', 'admin');
		$this->assertSame('closing', $p->getState());
		$p = $m['svc']->transition($p, 'closed', 'admin');
		$this->assertSame('closed', $p->getState());
	}

	public function testTransitionRejectsIllegalMoves(): void
	{
		$m = $this->svc();
		foreach ([['draft', 'closing'], ['draft', 'closed'], ['active', 'closed'], ['closed', 'active'], ['closing', 'active']] as [$from, $to]) {
			try {
				$m['svc']->transition($this->project($from), $to, 'admin');
				$this->fail("{$from} → {$to} must be refused");
			} catch (ConflictException $e) {
				$this->assertSame('INVALID_STATE', $e->getErrorCode());
			}
		}
	}
}
