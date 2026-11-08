<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Time capture (US-NW-02). rate_minor/amount_minor are resolved at insert
 * and frozen (ADR-04, GAC-3): updates re-price only through the STORED rate;
 * a retro rate-history change never touches existing entries. Entries
 * without a resolvable rate stay unpriced and surface in "Fehlende Angaben".
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Db\TimeEntry;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\WorkPackage;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\DbErrors;
use OCA\NachweisCheck\Support\Money;
use OCA\NachweisCheck\Support\PeriodUtil;
use OCP\IUserManager;

class TimeEntryService
{
	public function __construct(
		private readonly TimeEntryMapper $mapper,
		private readonly WorkPackageService $apService,
		private readonly RateService $rates,
		private readonly AuditService $audit,
		private readonly IUserManager $userManager,
		private readonly ProjectMemberMapper $memberMapper,
	) {
	}

	public function create(int $projectId, int $apId, string $uid, string $dateYmd, int $minutes, string $note, ?string $externalRef, string $actor): TimeEntry
	{
		$ap = $this->apService->getOrFail($projectId, $apId);
		$this->apService->requireBookable($ap);
		$this->validate($dateYmd, $minutes);

		// Time rows belong to live project members only: the uid must
		// exist, be enabled, and be a member of this project — all three
		// misses collapse into one code (no existence oracle). Covers the
		// web create AND the CSV-import row path alike.
		$target = $this->userManager->get($uid);
		if ($target === null || !$target->isEnabled()
			|| $this->memberMapper->findByProjectAndUid($projectId, $uid) === null) {
			throw new ValidationException(['uid' => 'Unknown user.']);
		}

		// Idempotency key (I-03): '' normalizes to null so the unique index
		// sees NULL (uncollided), never an empty-string sentinel.
		$externalRef = $externalRef !== null && trim($externalRef) !== '' ? trim($externalRef) : null;
		if ($externalRef !== null
			&& $this->mapper->findByExternalRef($projectId, $externalRef) !== null) {
			throw new ValidationException(['external_ref' => 'Duplicate external reference.']);
		}

		$rate = $this->rates->resolve($projectId, $uid, $dateYmd);
		$e = new TimeEntry();
		$e->setProjectId($projectId);
		$e->setApId($apId);
		$e->setUid($uid);
		$e->setEntryDate(new \DateTime($dateYmd));
		$e->setMinutes($minutes);
		$e->setNote($note);
		$e->setRateMinor($rate);
		$e->setAmountMinor($rate !== null ? Money::mulRate($minutes, $rate) : null);
		$e->setExternalRef($externalRef);
		$now = new \DateTime();
		$e->setCreatedAt($now);
		$e->setUpdatedAt($now);
		try {
			$e = $this->mapper->insert($e);
		} catch (\Throwable $x) {
			// Lost the pre-check race → unique index fired (concurrent
			// import/booking with the same external_ref).
			if (DbErrors::isUniqueViolation($x)) {
				throw new ValidationException(['external_ref' => 'Duplicate external reference.']);
			}
			throw $x;
		}
		// Person-linked money (rate, derived amount) is salary data — it
		// does not belong in the audit payload (PII-in-logs threat row).
		$this->audit->record($projectId, 'time_entry', $e->getId(), 'create', $actor, null, [
			'uid' => $uid, 'date' => $dateYmd, 'minutes' => $minutes,
		]);
		return $e;
	}

	public function update(TimeEntry $e, string $dateYmd, int $minutes, string $note, string $actor): TimeEntry
	{
		$this->validate($dateYmd, $minutes);
		$before = [
			'date' => $e->getEntryDate()?->format('Y-m-d'), 'minutes' => $e->getMinutes(),
			'amount_minor' => $e->getAmountMinor(),
		];
		$e->setEntryDate(new \DateTime($dateYmd));
		$e->setMinutes($minutes);
		$e->setNote($note);
		// Re-price ONLY via the captured rate; a missing rate stays missing.
		$e->setAmountMinor($e->getRateMinor() !== null ? Money::mulRate($minutes, $e->getRateMinor()) : null);
		$e->setUpdatedAt(new \DateTime());
		$e = $this->mapper->update($e);
		$this->audit->record($e->getProjectId(), 'time_entry', $e->getId(), 'update', $actor, $before, [
			'date' => $dateYmd, 'minutes' => $minutes,
		]);
		return $e;
	}

	public function delete(TimeEntry $e, string $actor): void
	{
		$this->audit->record($e->getProjectId(), 'time_entry', $e->getId(), 'delete', $actor, [
			'uid' => $e->getUid(), 'date' => $e->getEntryDate()?->format('Y-m-d'),
			'minutes' => $e->getMinutes(),
		]);
		$this->mapper->delete($e);
	}

	public function getOrFail(int $projectId, int $id): TimeEntry
	{
		$e = $this->mapper->findById($id);
		if ($e === null || $e->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $e;
	}

	private function validate(string $dateYmd, int $minutes): void
	{
		$fields = [];
		if (!PeriodUtil::isValidYmd($dateYmd)) {
			$fields['entry_date'] = 'Invalid date.';
		}
		if ($minutes <= 0 || $minutes > 24 * 60) {
			$fields['minutes'] = 'Minutes must be between 1 and 1440.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
	}
}
