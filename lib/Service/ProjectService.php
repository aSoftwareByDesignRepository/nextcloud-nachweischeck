<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Project lifecycle (US-NW-01): draft → active → closing → closed.
 * Creation seeds a draft Finanzierungsplan from the template skeleton,
 * adds the creator as project admin, and enforces the free-tier
 * single-active-project limit on activation (not creation — drafts are free).
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\ProjectMapper;
use OCA\NachweisCheck\Db\ProjectMember;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Db\FinplanVersionMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\PeriodUtil;

class ProjectService
{
	public const STATES = ['draft', 'active', 'closing', 'closed'];

	public function __construct(
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectMemberMapper $memberMapper,
		private readonly FinplanVersionMapper $planVerMapper,
		private readonly PositionMapper $positionMapper,
		private readonly TemplateService $templates,
		private readonly LicenseService $license,
		private readonly AuditService $audit,
	private readonly DeadlineService $deadlines,
		private readonly WorkPackageService $workPackages,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	public function create(
		string $uid,
		string $name,
		string $funderName,
		string $funderRef,
		?string $bewStartYmd,
		?string $bewEndYmd,
		string $templateKey,
		string $financingMode,
		string $displayName,
	): Project {
		$fields = [];
		if (trim($name) === '') {
			$fields['name'] = 'Project name is required.';
		} elseif (mb_strlen($name) > 200) {
			// varchar(200) — validate before the INSERT so an over-length
			// payload gets a 400 field error, not a raw SQLSTATE 500.
			$fields['name'] = 'Project name is too long (max 200 characters).';
		}
		if (mb_strlen($funderName) > 200) {
			$fields['funder_name'] = 'Funder name is too long (max 200 characters).';
		}
		if (mb_strlen($funderRef) > 120) {
			$fields['funder_ref'] = 'Funder reference is too long (max 120 characters).';
		}
		if ($bewStartYmd !== null && !PeriodUtil::isValidYmd($bewStartYmd)) {
			$fields['bew_start'] = 'Invalid date.';
		}
		if ($bewEndYmd !== null && !PeriodUtil::isValidYmd($bewEndYmd)) {
			$fields['bew_end'] = 'Invalid date.';
		}
		if ($bewStartYmd !== null && $bewEndYmd !== null && $bewEndYmd < $bewStartYmd) {
			$fields['bew_end'] = 'End must not precede start.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		// template must resolve (404 for unknown keys → validation here)
		try {
			$def = $this->templates->definition($templateKey);
		} catch (NotFoundException) {
			throw new ValidationException(['template_key' => 'Unknown template.']);
		}
		if ($templateKey !== TemplateService::KEY_ANBEST_P) {
			$this->license->requireFeature(LicenseService::FEATURE_TEMPLATES);
		}

		$p = new Project();
		$p->setUid($uid);
		$p->setName(trim($name));
		$p->setFunderName(trim($funderName));
		$p->setFunderRef(trim($funderRef));
		$p->setBewStart($bewStartYmd !== null ? new \DateTime($bewStartYmd) : null);
		$p->setBewEnd($bewEndYmd !== null ? new \DateTime($bewEndYmd) : null);
		$p->setTemplateKey($templateKey);
		$p->setFinancingMode($financingMode === 'festbetrag' ? 'festbetrag' : 'ausgaben');
		$p->setRetentionYears(10);
		$p->setState('draft');
		$now = new \DateTime();
		$p->setCreatedAt($now);
		$p->setUpdatedAt($now);
		$p = $this->projectMapper->insert($p);

		$m = new ProjectMember();
		$m->setProjectId($p->getId());
		$m->setUid($uid);
		$m->setRole(PermissionService::ROLE_ADMIN);
		$m->setDisplayName($displayName);
		$m->setCreatedAt($now);
		$this->memberMapper->insert($m);

		// Seed draft plan version 1 from the template skeleton.
		$ver = new FinplanVersion();
		$ver->setProjectId($p->getId());
		$ver->setVersion(1);
		$ver->setState('draft');
		$ver->setNote('Initial plan from template ' . $templateKey);
		$ver->setCreatedBy($uid);
		$ver->setCreatedAt($now);
		$ver = $this->planVerMapper->insert($ver);
		$sort = 0;
		foreach ($def['skeleton'] as $posDef) {
			$pos = new Position();
			$pos->setPlanVerId($ver->getId());
			$pos->setCode((string)$posDef['code']);
			$pos->setTitle((string)$posDef['title']);
			$pos->setKind((string)$posDef['kind']);
			$pos->setBudgetMinor(0);
			$pos->setSort($sort++);
			$this->positionMapper->insert($pos);
		}

		$this->audit->record($p->getId(), 'project', $p->getId(), 'create', $uid, null, [
			'name' => $p->getName(), 'template' => $templateKey,
		]);
		return $p;
	}

	public function getOrFail(int $projectId): Project
	{
		$p = $this->projectMapper->findById($projectId);
		if ($p === null) {
			throw new NotFoundException();
		}
		return $p;
	}

	/**
	 * Activate: requires an activated plan version and every AP mapped to ≥1
	 * position (US-NW-01 AC-3). Enforces the free-tier per-user active-project
	 * limit. The limit check runs inside a global activation lock — a
	 * controller-side count would be a TOCTOU (two concurrent activations
	 * could both pass on the free tier). The project lock additionally
	 * serializes with deadline recompute and plan activation.
	 */
	public function activate(Project $p, string $actor): Project
	{
		return $this->guard->run(ConcurrencyGuard::activationKey(), function () use ($p, $actor): Project {
			return $this->guard->run(ConcurrencyGuard::projectKey($p->getId()), function () use ($p, $actor): Project {
				if ($p->getState() !== 'draft') {
					throw new ConflictException('INVALID_STATE', 'Only draft projects can be activated.');
				}
				if ($this->planVerMapper->activeFor($p->getId()) === null) {
					throw new ValidationException(['finplan' => 'Activate a Finanzierungsplan version first.']);
				}
				// Computed inside the lock: an AP mapped/unmapped between a
				// controller-side check and here would silently reopen the
				// activation guard.
				$unmappedAps = $this->workPackages->unmappedFor($p->getId());
				if ($unmappedAps !== []) {
					$codes = implode(', ', array_map(static fn(array $a): string => (string)$a['code'], $unmappedAps));
					throw new ValidationException(['work_packages' => 'Unmapped work packages: ' . $codes]);
				}
				$activeProjectCount = 0;
				foreach ($this->projectMapper->findForUid($actor) as $other) {
					if ($other->getState() === 'active' && $other->getId() !== $p->getId()) {
						$activeProjectCount++;
					}
				}
				$this->license->checkActiveProjectLimit($activeProjectCount);
				$before = ['state' => $p->getState()];
				$p->setState('active');
				$p->setUpdatedAt(new \DateTime());
				$p = $this->projectMapper->update($p);
				$this->audit->record($p->getId(), 'project', $p->getId(), 'activate', $actor, $before, ['state' => 'active']);
				$this->deadlines->recompute($p);
				return $p;
			});
		});
	}

	public function transition(Project $p, string $target, string $actor): Project
	{
		$allowed = [
			'active' => ['closing'],
			'closing' => ['closed'],
		];
		$from = $p->getState();
		if (!in_array($target, $allowed[$from] ?? [], true)) {
			throw new ConflictException('INVALID_STATE', "Cannot move {$from} → {$target}.");
		}
		$p->setState($target);
		$p->setUpdatedAt(new \DateTime());
		$p = $this->projectMapper->update($p);
		$this->audit->record($p->getId(), 'project', $p->getId(), 'state:' . $target, $actor, ['state' => $from], ['state' => $target]);
		return $p;
	}
}
