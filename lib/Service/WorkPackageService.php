<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Arbeitspakete (US-NW-01/02). An AP must map to ≥1 position before the
 * project can activate; bookings against a closed AP are refused AP_CLOSED.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\ApPosition;
use OCA\NachweisCheck\Db\ApPositionMapper;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Db\WorkPackage;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\PeriodUtil;
use OCP\IDBConnection;
use OCP\IUserManager;

class WorkPackageService
{
	public function __construct(
		private readonly WorkPackageMapper $mapper,
		private readonly ApPositionMapper $apPosMapper,
		private readonly PositionMapper $posMapper,
		private readonly FinplanService $finplan,
		private readonly AuditService $audit,
		private readonly IDBConnection $db,
		private readonly IUserManager $userManager,
		private readonly ProjectMemberMapper $memberMapper,
	) {
	}

	public function create(int $projectId, string $code, string $title, ?string $ownerUid, ?string $startYmd, ?string $endYmd, string $actor): WorkPackage
	{
		$fields = [];
		if (!preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $code)) {
			$fields['code'] = 'Invalid work package code.';
		} elseif ($this->mapper->findByProjectAndCode($projectId, $code) !== null) {
			$fields['code'] = 'Code already used in this project.';
		}
		if (trim($title) === '') {
			$fields['title'] = 'Title is required.';
		}
		if ($startYmd !== null && !PeriodUtil::isValidYmd($startYmd)) {
			$fields['start_date'] = 'Invalid date.';
		}
		if ($endYmd !== null && !PeriodUtil::isValidYmd($endYmd)) {
			$fields['end_date'] = 'Invalid date.';
		}
		if ($startYmd !== null && $endYmd !== null && $endYmd < $startYmd) {
			$fields['end_date'] = 'End must not precede start.';
		}
		if ($ownerUid !== null) {
			// An Arbeitsfeld owner must be a live project member: the uid
			// must exist, be enabled, and be a member of this project —
			// all three misses collapse into one code (no existence oracle).
			$target = $this->userManager->get($ownerUid);
			if ($target === null || !$target->isEnabled()
				|| $this->memberMapper->findByProjectAndUid($projectId, $ownerUid) === null) {
				$fields['owner_uid'] = 'Unknown user.';
			}
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$ap = new WorkPackage();
		$ap->setProjectId($projectId);
		$ap->setCode($code);
		$ap->setTitle(trim($title));
		$ap->setOwnerUid($ownerUid);
		$ap->setStartDate($startYmd !== null ? new \DateTime($startYmd) : null);
		$ap->setEndDate($endYmd !== null ? new \DateTime($endYmd) : null);
		$ap->setState('active');
		$ap->setCreatedAt(new \DateTime());
		$ap = $this->mapper->insert($ap);
		$this->audit->record($projectId, 'workpackage', $ap->getId(), 'create', $actor, null, [
			'code' => $code, 'title' => $ap->getTitle(),
		]);
		return $ap;
	}

	public function findByCode(int $projectId, string $code): ?WorkPackage
	{
		return $this->mapper->findByProjectAndCode($projectId, $code);
	}

	public function getOrFail(int $projectId, int $apId): WorkPackage
	{
		$ap = $this->mapper->findById($apId);
		if ($ap === null || $ap->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $ap;
	}

	/** Position ids must belong to the project's ACTIVE plan version. */
	public function setPositions(WorkPackage $ap, array $positionIds, string $actor): void
	{
		$active = $this->finplan->activeFor($ap->getProjectId());
		if ($active === null) {
			throw new ValidationException(['positions' => 'No active Finanzierungsplan.']);
		}
		$valid = [];
		foreach ($this->posMapper->findByPlanVer($active->getId()) as $pos) {
			$valid[$pos->getId()] = true;
		}
		$clean = [];
		foreach ($positionIds as $pid) {
			$pid = (int)$pid;
			if (!isset($valid[$pid])) {
				throw new ValidationException(['positions' => 'Position is not part of the active plan.']);
			}
			$clean[$pid] = true;
		}
		$this->db->beginTransaction();
		try {
			$this->apPosMapper->deleteByAp($ap->getId());
			foreach (array_keys($clean) as $pid) {
				$link = new ApPosition();
				$link->setApId($ap->getId());
				$link->setPositionId($pid);
				$this->apPosMapper->insert($link);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->audit->record($ap->getProjectId(), 'workpackage', $ap->getId(), 'set_positions', $actor, null, [
			'position_ids' => array_keys($clean),
		]);
	}

	/** @return list<int> position ids mapped to this AP */
	public function positionIds(int $apId): array
	{
		$ids = [];
		foreach ($this->apPosMapper->findByAp($apId) as $link) {
			$ids[] = $link->getPositionId();
		}
		return $ids;
	}

	/** APs lacking any position mapping — blocks project activation. */
	public function unmappedFor(int $projectId): array
	{
		$out = [];
		foreach ($this->mapper->findByProject($projectId) as $ap) {
			if ($this->apPosMapper->findByAp($ap->getId()) === []) {
				$out[] = ['id' => $ap->getId(), 'code' => $ap->getCode()];
			}
		}
		return $out;
	}

	public function close(WorkPackage $ap, string $actor): WorkPackage
	{
		if ($ap->getState() === 'closed') {
			return $ap;
		}
		$ap->setState('closed');
		$ap = $this->mapper->update($ap);
		$this->audit->record($ap->getProjectId(), 'workpackage', $ap->getId(), 'close', $actor);
		return $ap;
	}

	/** Guard used by time/receipt booking paths. */
	public function requireBookable(WorkPackage $ap): void
	{
		if ($ap->getState() !== 'active') {
			throw new ConflictException('AP_CLOSED', 'This work package is closed.');
		}
	}
}
