<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Versioned Finanzierungsplan (ADR-02, US-NW-01 AC-2, MH-01).
 * The active version's positions are frozen — edits happen on a draft
 * version cloned from it; activating the draft atomically supersedes the
 * old version. Optimistic lock: the client must name the version it based
 * its edits on (base_version); a mismatch → 409, never a merge.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\CofunderMapper;
use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\FinplanVersionMapper;
use OCA\NachweisCheck\Db\Position;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\DbErrors;
use OCP\IDBConnection;

class FinplanService
{
	public const KINDS = ['personal', 'sach', 'reise', 'extern', 'eigen', 'fremd'];

	public function __construct(
		private readonly FinplanVersionMapper $verMapper,
		private readonly PositionMapper $posMapper,
		private readonly CofunderMapper $cofunderMapper,
		private readonly AuditService $audit,
		private readonly IDBConnection $db,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	public function listVersions(int $projectId): array
	{
		return $this->verMapper->findByProject($projectId);
	}

	public function draftFor(int $projectId): ?FinplanVersion
	{
		foreach ($this->verMapper->findByProject($projectId) as $v) {
			if ($v->getState() === 'draft') {
				return $v;
			}
		}
		return null;
	}

	public function activeFor(int $projectId): ?FinplanVersion
	{
		return $this->verMapper->activeFor($projectId);
	}

	/** Positions of a version, ordered (Finanzierungsplan-Gliederung). */
	public function positions(FinplanVersion $ver): array
	{
		return $this->posMapper->findByPlanVer($ver->getId());
	}

	/**
	 * Clone the current active (or latest) version into a new draft.
	 * Idempotent under concurrency: the project lock serializes the
	 * check-then-insert, and a unique-key loss on (project_id, version)
	 * returns the draft the racing caller created instead of a 500.
	 */
	public function newDraft(int $projectId, string $actor, string $note = ''): FinplanVersion
	{
		return $this->guard->run(ConcurrencyGuard::projectKey($projectId), function () use ($projectId, $actor, $note): FinplanVersion {
			$existing = $this->draftFor($projectId);
			if ($existing !== null) {
				return $existing;
			}
			$base = $this->verMapper->activeFor($projectId);
			$source = $base;
			if ($source === null) {
				foreach ($this->verMapper->findByProject($projectId) as $v) {
					$source = $v; // latest by version ASC loop ends on highest
				}
			}
			$ver = new FinplanVersion();
			$ver->setProjectId($projectId);
			$ver->setVersion($this->verMapper->maxVersion($projectId) + 1);
			$ver->setState('draft');
			$ver->setBaseVersion($source?->getVersion());
			$ver->setNote($note);
			$ver->setCreatedBy($actor);
			$ver->setCreatedAt(new \DateTime());
			try {
				$ver = $this->verMapper->insert($ver);
			} catch (\Throwable $e) {
				if (!DbErrors::isUniqueViolation($e)) {
					throw $e;
				}
				// Lost the (project_id, version) race — the winner's row is
				// authoritative; surface it idempotently rather than 500ing.
				$winner = $this->draftFor($projectId);
				if ($winner === null) {
					throw $e;
				}
				return $winner;
			}
			if ($source !== null) {
				foreach ($this->posMapper->findByPlanVer($source->getId()) as $pos) {
					$clone = new Position();
					$clone->setPlanVerId($ver->getId());
					$clone->setCode($pos->getCode());
					$clone->setTitle($pos->getTitle());
					$clone->setKind($pos->getKind());
					$clone->setBudgetMinor($pos->getBudgetMinor());
					$clone->setCofunderId($pos->getCofunderId());
					$clone->setSort($pos->getSort());
					$this->posMapper->insert($clone);
				}
			}
			$this->audit->record($projectId, 'finplan_version', $ver->getId(), 'create_draft', $actor, null, [
				'version' => $ver->getVersion(), 'base' => $ver->getBaseVersion(),
			]);
			return $ver;
		});
	}

	/**
	 * Replace a draft version's position set atomically.
	 * @param list<array{code:string,title:string,kind:string,budget_minor:int}> $positions
	 */
	public function replacePositions(FinplanVersion $ver, array $positions, string $actor): void
	{
		if ($ver->getState() !== 'draft') {
			throw new ConflictException('IMMUTABLE_PLAN', 'Only draft plan versions are editable.');
		}
		$seen = [];
		foreach ($positions as $i => $p) {
			$code = trim((string)($p['code'] ?? ''));
			$title = trim((string)($p['title'] ?? ''));
			$kind = (string)($p['kind'] ?? '');
			$budget = $p['budget_minor'] ?? null;
			if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $code)) {
				throw new ValidationException(["positions.{$i}.code" => 'Invalid position code.']);
			}
			if (isset($seen[$code])) {
				throw new ValidationException(["positions.{$i}.code" => 'Duplicate position code.']);
			}
			$seen[$code] = true;
			if ($title === '') {
				throw new ValidationException(["positions.{$i}.title" => 'Title required.']);
			}
			if (!in_array($kind, self::KINDS, true)) {
				throw new ValidationException(["positions.{$i}.kind" => 'Invalid kind.']);
			}
			if (!is_int($budget) || $budget < 0) {
				throw new ValidationException(["positions.{$i}.budget_minor" => 'Budget must be a non-negative integer.']);
			}
			$cofId = $p['cofunder_id'] ?? null;
			if ($cofId !== null) {
				if (!is_int($cofId) || $cofId <= 0) {
					throw new ValidationException(["positions.{$i}.cofunder_id" => 'Invalid co-funder.']);
				}
				$cof = $this->cofunderMapper->findById($cofId);
				if ($cof === null || $cof->getProjectId() !== $ver->getProjectId()) {
					throw new ValidationException(["positions.{$i}.cofunder_id" => 'Invalid co-funder.']);
				}
			}
		}
		if ($positions === []) {
			throw new ValidationException(['positions' => 'At least one position is required.']);
		}
		$this->db->beginTransaction();
		try {
			foreach ($this->posMapper->findByPlanVer($ver->getId()) as $old) {
				$this->posMapper->delete($old);
			}
			$sort = 0;
			foreach ($positions as $p) {
				$pos = new Position();
				$pos->setPlanVerId($ver->getId());
				$pos->setCode(trim((string)$p['code']));
				$pos->setTitle(trim((string)$p['title']));
				$pos->setKind((string)$p['kind']);
				$pos->setBudgetMinor((int)$p['budget_minor']);
				$pos->setCofunderId(isset($p['cofunder_id']) && is_int($p['cofunder_id']) ? $p['cofunder_id'] : null);
				$pos->setSort($sort++);
				$this->posMapper->insert($pos);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->audit->record($ver->getProjectId(), 'finplan_version', $ver->getId(), 'replace_positions', $actor, null, [
			'count' => count($positions),
		]);
	}

	/**
	 * Activate a draft version — fail-closed CAS on base_version.
	 * The CAS read runs INSIDE the project lock: without it two concurrent
	 * activations could both observe the same active version and produce
	 * two 'active' rows (there is no partial unique index on state).
	 * @param ?int $expectBaseVersion client-declared base; required. null → 409.
	 */
	public function activate(FinplanVersion $ver, ?int $expectBaseVersion, string $actor): FinplanVersion
	{
		return $this->guard->run(ConcurrencyGuard::projectKey($ver->getProjectId()), function () use ($ver, $expectBaseVersion, $actor): FinplanVersion {
			if ($ver->getState() !== 'draft') {
				throw new ConflictException('INVALID_STATE', 'Only a draft plan can be activated.');
			}
			if ($expectBaseVersion === null) {
				throw new ConflictException('STALE_BASE', 'Missing base version — reload the plan and retry.');
			}
			$current = $this->verMapper->activeFor($ver->getProjectId());
			$currentVersion = $current?->getVersion();
			// CAS: the client's declared base must equal the currently active
			// version. First activation of a project expects base 0.
			if ($expectBaseVersion !== ($currentVersion ?? 0)) {
				throw new ConflictException('STALE_BASE', 'The plan changed meanwhile — reload and re-apply your edits.');
			}
			$positions = $this->posMapper->findByPlanVer($ver->getId());
			if ($positions === []) {
				throw new ValidationException(['positions' => 'Cannot activate an empty plan.']);
			}
			$this->db->beginTransaction();
			try {
				if ($current !== null) {
					$current->setState('superseded');
					$this->verMapper->update($current);
				}
				$ver->setState('active');
				$ver->setActivatedAt(new \DateTime());
				$ver = $this->verMapper->update($ver);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			$this->audit->record($ver->getProjectId(), 'finplan_version', $ver->getId(), 'activate', $actor, [
				'superseded' => $currentVersion,
			], ['version' => $ver->getVersion()]);
			return $ver;
		});
	}

	public function getVersionOrFail(int $projectId, int $versionId): FinplanVersion
	{
		$v = $this->verMapper->findById($versionId);
		if ($v === null || $v->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $v;
	}
}
