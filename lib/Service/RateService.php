<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Dated-rate service (US-NW-02). History rows are append-only — changing a
 * rate inserts a new row with a later valid_from. Past entries keep their
 * captured rate_minor/amount_minor (GAC-3); nothing ever rewrites them.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\RateHistory;
use OCA\NachweisCheck\Db\RateHistoryMapper;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\PeriodUtil;
use OCA\NachweisCheck\Support\RateResolver;

class RateService
{
	public function __construct(
		private readonly RateHistoryMapper $mapper,
		private readonly AuditService $audit,
	) {
	}

	public function addRate(int $projectId, string $uid, string $roleKey, int $rateMinor, string $validFromYmd, string $actor): RateHistory
	{
		$fields = [];
		if ($rateMinor < 0) {
			$fields['rate_minor'] = 'Rate must not be negative.';
		}
		if (!PeriodUtil::isValidYmd($validFromYmd)) {
			$fields['valid_from'] = 'Invalid date (YYYY-MM-DD).';
		}
		if ($uid === '') {
			$fields['uid'] = 'Person is required.';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$row = new RateHistory();
		$row->setProjectId($projectId);
		$row->setUid($uid);
		$row->setRoleKey($roleKey);
		$row->setRateMinor($rateMinor);
		$row->setValidFrom(new \DateTime($validFromYmd));
		$row->setCreatedBy($actor);
		$row->setCreatedAt(new \DateTime());
		$row = $this->mapper->insert($row);
		// Salary values stay out of the audit payload (threat model: PII in
		// logs) — the event records THAT a rate changed, never its amount.
		$this->audit->record($projectId, 'rate_history', $row->getId(), 'create', $actor, null, [
			'uid' => $uid, 'valid_from' => $validFromYmd, 'role_key' => $roleKey,
		]);
		return $row;
	}

	public function resolve(int $projectId, string $uid, string $dateYmd): ?int
	{
		return RateResolver::resolve($this->mapper->historyFor($projectId, $uid), $dateYmd);
	}

	/** @return list<RateHistory> finance-role display rows */
	public function history(int $projectId, string $uid): array
	{
		return $this->mapper->findByProjectUid($projectId, $uid);
	}
}
