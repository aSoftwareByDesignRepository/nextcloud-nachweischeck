<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Arbeitsberichte per AP per month (US-NW-04 AC-4): the Sachbericht
 * skeleton in the export is pre-filled from these. States:
 * draft → submitted → accepted (Projektleitung accepts).
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\WorkReport;
use OCA\NachweisCheck\Db\WorkReportMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\DbErrors;
use OCA\NachweisCheck\Support\PeriodUtil;

class WorkReportService
{
	public function __construct(
		private readonly WorkReportMapper $mapper,
		private readonly WorkPackageService $apService,
		private readonly AuditService $audit,
	) {
	}

	public function create(int $projectId, int $apId, string $period, string $summary, string $outcome, string $authorUid): WorkReport
	{
		$ap = $this->apService->getOrFail($projectId, $apId);
		if (!PeriodUtil::validPeriod($period)) {
			throw new ValidationException(['period' => 'Invalid period (YYYY-MM).']);
		}
		if (trim($summary) === '') {
			throw new ValidationException(['summary' => 'A short report text is required.']);
		}
		$wr = new WorkReport();
		$wr->setApId($ap->getId());
		$wr->setPeriod($period);
		$wr->setSummary(trim($summary));
		$wr->setOutcome(trim($outcome));
		$wr->setState('draft');
		$wr->setAuthorUid($authorUid);
		$now = new \DateTime();
		$wr->setCreatedAt($now);
		$wr->setUpdatedAt($now);
		try {
			$wr = $this->mapper->insert($wr);
		} catch (\Throwable $e) {
			// (ap_id, period) is unique — concurrent create of the same report.
			if (DbErrors::isUniqueViolation($e)) {
				throw new ConflictException('DUPLICATE_PERIOD', 'A report for this work package and period already exists.');
			}
			throw $e;
		}
		$this->audit->record($projectId, 'workreport', $wr->getId(), 'create', $authorUid, null, [
			'ap_id' => $apId, 'period' => $period,
		]);
		return $wr;
	}

	public function getOrFail(int $projectId, int $id): WorkReport
	{
		$wr = $this->mapper->findById($id);
		if ($wr === null) {
			throw new NotFoundException();
		}
		// scope via owning AP
		$ap = $this->apService->getOrFail($projectId, $wr->getApId());
		return $wr;
	}

	public function transition(WorkReport $wr, string $target, string $actor): WorkReport
	{
		$allowed = ['draft' => ['submitted'], 'submitted' => ['accepted', 'draft']];
		if (!in_array($target, $allowed[$wr->getState()] ?? [], true)) {
			throw new ConflictException('INVALID_STATE', "Cannot move {$wr->getState()} → {$target}.");
		}
		$from = $wr->getState();
		$wr->setState($target);
		$wr->setUpdatedAt(new \DateTime());
		$wr = $this->mapper->update($wr);
		$this->audit->record(null, 'workreport', $wr->getId(), 'state:' . $target, $actor, ['state' => $from], ['state' => $target]);
		return $wr;
	}
}
