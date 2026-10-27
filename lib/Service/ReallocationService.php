<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Umschichtungs-Workflow (MH-12): moving budget between Einzelansätze needs
 * a mandatory Begründung plus a documented funder decision. Approval does
 * NOT mutate the frozen plan — it mints a new plan version with the moved
 * amounts (ADR-02), which keeps bewilligt-vs-verwendet comparable.
 *
 * Position ids are per-version: the stored from/to ids are resolved to
 * stable position CODES on the version the request was filed against, then
 * applied to the freshly cloned draft.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\Reallocation;
use OCA\NachweisCheck\Db\ReallocationMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;

class ReallocationService
{
	public function __construct(
		private readonly ReallocationMapper $mapper,
		private readonly FinplanService $finplan,
		private readonly PositionMapper $posMapper,
		private readonly AuditService $audit,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	public function request(int $projectId, int $fromPositionId, int $toPositionId, int $amountMinor, string $reasonText, string $uid): Reallocation
	{
		$active = $this->finplan->activeFor($projectId);
		if ($active === null) {
			throw new ValidationException(['positions' => 'No active Finanzierungsplan.']);
		}
		$fields = [];
		if ($fromPositionId === $toPositionId) {
			$fields['to_position_id'] = 'Source and target must differ.';
		}
		if ($amountMinor <= 0) {
			$fields['amount_minor'] = 'Amount must be positive.';
		}
		if (trim($reasonText) === '') {
			$fields['reason_text'] = 'A justification (Begründung) is required.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$valid = [];
		foreach ($this->finplan->positions($active) as $pos) {
			$valid[$pos->getId()] = true;
		}
		if (!isset($valid[$fromPositionId]) || !isset($valid[$toPositionId])) {
			throw new NotFoundException();
		}
		$r = new Reallocation();
		$r->setProjectId($projectId);
		$r->setPlanVerId($active->getId());
		$r->setFromPositionId($fromPositionId);
		$r->setToPositionId($toPositionId);
		$r->setAmountMinor($amountMinor);
		$r->setReasonText(trim($reasonText));
		$r->setState('pending');
		$r->setUid($uid);
		$r->setCreatedAt(new \DateTime());
		$r = $this->mapper->insert($r);
		$this->audit->record($projectId, 'reallocation', $r->getId(), 'request', $uid, null, [
			'from' => $fromPositionId, 'to' => $toPositionId, 'amount_minor' => $amountMinor,
		]);
		return $r;
	}

	/**
	 * Approve: mint a new plan version with the moved amount and mark the
	 * reallocation applied.
	 */
	public function approve(Reallocation $r, string $actor): Reallocation
	{
		return $this->guard->run(ConcurrencyGuard::projectKey($r->getProjectId()), function () use ($r, $actor): Reallocation {
			// Re-read inside the lock: a racing decision may have landed.
			$fresh = $this->mapper->findById($r->getId());
			if ($fresh === null) {
				throw new NotFoundException();
			}
			$r = $fresh;
			if ($r->getState() !== 'pending') {
				throw new ConflictException('INVALID_STATE', 'Already decided.');
			}
			$fromCode = $this->codeFor($r->getPlanVerId(), $r->getFromPositionId());
			$toCode = $this->codeFor($r->getPlanVerId(), $r->getToPositionId());
			if ($fromCode === null || $toCode === null) {
				throw new ConflictException('STALE_POSITIONS', 'The plan changed since the request — file a new Umschichtung.');
			}
			$draft = $this->finplan->newDraft($r->getProjectId(), $actor, 'Umschichtung #' . $r->getId());
			$positions = [];
			foreach ($this->finplan->positions($draft) as $pos) {
				$budget = $pos->getBudgetMinor();
				if ($pos->getCode() === $fromCode) {
					$budget -= $r->getAmountMinor();
				} elseif ($pos->getCode() === $toCode) {
					$budget += $r->getAmountMinor();
				}
				if ($budget < 0) {
					throw new ValidationException(['amount_minor' => 'Umschichtung exceeds the source position budget.']);
				}
				$positions[] = [
					'code' => $pos->getCode(), 'title' => $pos->getTitle(),
					'kind' => $pos->getKind(), 'budget_minor' => $budget,
				];
			}
			$this->finplan->replacePositions($draft, $positions, $actor);
			$this->finplan->activate($draft, $draft->getBaseVersion(), $actor);
			$r->setState('applied');
			$r->setApprovedBy($actor);
			$r->setDecidedAt(new \DateTime());
			$r = $this->mapper->update($r);
			$this->audit->record($r->getProjectId(), 'reallocation', $r->getId(), 'approve', $actor, null, [
				'new_plan_version' => $draft->getVersion(),
			]);
			return $r;
		});
	}

	public function reject(Reallocation $r, string $actor): Reallocation
	{
		// Same project lock as approve(): approve vs. reject must never
		// decide the same pending request twice in opposite directions.
		return $this->guard->run(ConcurrencyGuard::projectKey($r->getProjectId()), function () use ($r, $actor): Reallocation {
			$fresh = $this->mapper->findById($r->getId());
			if ($fresh === null) {
				throw new NotFoundException();
			}
			$r = $fresh;
			if ($r->getState() !== 'pending') {
				throw new ConflictException('INVALID_STATE', 'Already decided.');
			}
			$r->setState('rejected');
			$r->setApprovedBy($actor);
			$r->setDecidedAt(new \DateTime());
			$r = $this->mapper->update($r);
			$this->audit->record($r->getProjectId(), 'reallocation', $r->getId(), 'reject', $actor);
			return $r;
		});
	}

	public function getOrFail(int $projectId, int $id): Reallocation
	{
		$r = $this->mapper->findById($id);
		if ($r === null || $r->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $r;
	}

	private function codeFor(int $planVerId, int $positionId): ?string
	{
		$pos = $this->posMapper->findById($positionId);
		if ($pos === null || $pos->getPlanVerId() !== $planVerId) {
			return null;
		}
		return $pos->getCode();
	}
}
