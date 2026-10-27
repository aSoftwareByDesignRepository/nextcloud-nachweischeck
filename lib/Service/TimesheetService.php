<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Signable Stundenzettel (US-NW-02 AC-3, V1-12): monthly per-person summary,
 * optionally per AP, with a sign-off chain member → Projektleitung.
 * minutes_total is a snapshot taken at generation time; divergence vs.
 * live entries is surfaced, not silently patched.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Timesheet;
use OCA\NachweisCheck\Db\TimesheetMapper;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\PeriodUtil;

class TimesheetService
{
	public function __construct(
		private readonly TimesheetMapper $mapper,
		private readonly TimeEntryMapper $entryMapper,
		private readonly WorkPackageService $apService,
		private readonly AuditService $audit,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	public function generate(int $projectId, string $uid, string $period, ?int $apId, string $actor): Timesheet
	{
		// Check-then-insert on (project, uid, period, ap) is not unique-indexed;
		// the project lock keeps concurrent generates idempotent.
		return $this->guard->run(ConcurrencyGuard::projectKey($projectId), function () use ($projectId, $uid, $period, $apId, $actor): Timesheet {
			return $this->generateLocked($projectId, $uid, $period, $apId, $actor);
		});
	}

	private function generateLocked(int $projectId, string $uid, string $period, ?int $apId, string $actor): Timesheet
	{
		if (!PeriodUtil::validPeriod($period)) {
			throw new ValidationException(['period' => 'Invalid period (YYYY-MM).']);
		}
		if ($apId !== null) {
			$this->apService->getOrFail($projectId, $apId);
		}
		$from = PeriodUtil::periodFirstDay($period);
		$to = PeriodUtil::periodLastDay($period);
		$entries = $this->entryMapper->findByUidPeriod($projectId, $uid, $from, $to, $apId);
		$total = 0;
		foreach ($entries as $e) {
			$total += $e->getMinutes();
		}
		$existing = $this->mapper->findFor($projectId, $uid, $period, $apId);
		$now = new \DateTime();
		if ($existing !== null) {
			if ($existing->getState() === 'signed') {
				throw new ConflictException('IMMUTABLE', 'A signed Stundenzettel cannot be regenerated — the snapshot stands.');
			}
			$existing->setMinutesTotal($total);
			$existing->setUpdatedAt($now);
			return $this->mapper->update($existing);
		}
		$ts = new Timesheet();
		$ts->setProjectId($projectId);
		$ts->setApId($apId);
		$ts->setUid($uid);
		$ts->setPeriod($period);
		$ts->setMinutesTotal($total);
		$ts->setState('draft');
		$ts->setCreatedAt($now);
		$ts->setUpdatedAt($now);
		return $this->mapper->insert($ts);
	}

	public function submit(Timesheet $ts, string $actor): Timesheet
	{
		if ($ts->getState() !== 'draft' && $ts->getState() !== 'rejected') {
			throw new ConflictException('INVALID_STATE', 'Only a draft can be submitted.');
		}
		if ($ts->getUid() !== $actor) {
			throw new ForbiddenException('Only the owner submits their Stundenzettel.');
		}
		$ts->setState('submitted');
		$ts->setSubmittedAt(new \DateTime());
		$ts->setUpdatedAt(new \DateTime());
		$ts = $this->mapper->update($ts);
		$this->audit->record($ts->getProjectId(), 'timesheet', $ts->getId(), 'submit', $actor);
		return $ts;
	}

	/** Sign-off by Projektleitung — must NOT be the same person (V1-12 chain). */
	public function sign(Timesheet $ts, string $signerUid): Timesheet
	{
		if ($ts->getState() !== 'submitted') {
			throw new ConflictException('INVALID_STATE', 'Only a submitted Stundenzettel can be signed.');
		}
		if ($ts->getUid() === $signerUid) {
			throw new ForbiddenException('The signer must differ from the owner.');
		}
		$ts->setState('signed');
		$ts->setSignedBy($signerUid);
		$ts->setSignedAt(new \DateTime());
		$ts->setUpdatedAt(new \DateTime());
		$ts = $this->mapper->update($ts);
		$this->audit->record($ts->getProjectId(), 'timesheet', $ts->getId(), 'sign', $signerUid);
		return $ts;
	}

	public function reject(Timesheet $ts, string $signerUid, string $reason): Timesheet
	{
		if ($ts->getState() !== 'submitted') {
			throw new ConflictException('INVALID_STATE', 'Only a submitted Stundenzettel can be rejected.');
		}
		if ($ts->getUid() === $signerUid) {
			throw new ForbiddenException('The reviewer must differ from the owner.');
		}
		if (trim($reason) === '') {
			throw new ValidationException(['reject_reason' => 'A rejection reason is required.']);
		}
		$ts->setState('rejected');
		$ts->setRejectReason(trim($reason));
		$ts->setUpdatedAt(new \DateTime());
		$ts = $this->mapper->update($ts);
		$this->audit->record($ts->getProjectId(), 'timesheet', $ts->getId(), 'reject', $signerUid);
		return $ts;
	}

	public function getOrFail(int $projectId, int $id): Timesheet
	{
		$ts = $this->mapper->findById($id);
		if ($ts === null || $ts->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $ts;
	}
}
