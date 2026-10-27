<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Arbeitsberichte + signable Stundenzettel (US-NW-02 AC-3, US-NW-04 AC-4).
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\TimesheetMapper;
use OCA\NachweisCheck\Db\WorkReportMapper;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\LicenseService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\TimesheetService;
use OCA\NachweisCheck\Service\WorkReportService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ReportApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly WorkReportService $reports,
		private readonly WorkReportMapper $reportMapper,
		private readonly TimesheetService $timesheets,
		private readonly TimesheetMapper $timesheetMapper,
		private readonly LicenseService $license,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function reports(int $projectId, int $apId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->reportMapper->findByAp($apId) as $wr) {
			$out[] = $this->serializeReport($wr);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function createReport(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireBook($projectId, $uid);
		$in = $this->params();
		$wr = $this->reports->create($projectId, (int)($in['ap_id'] ?? 0), (string)($in['period'] ?? ''),
			(string)($in['summary'] ?? ''), (string)($in['outcome'] ?? ''), $uid);
		return $this->ok($this->serializeReport($wr), 201);
	}

	#[NoAdminRequired]
	public function transitionReport(int $projectId, int $id, string $target): JSONResponse
	{
		$uid = $this->uid();
		$wr = $this->reports->getOrFail($projectId, $id);
		if ($target === 'accepted') {
			$this->perm->requireFinance($projectId, $uid);
		} else {
			$this->perm->requireBook($projectId, $uid);
		}
		$wr = $this->reports->transition($wr, $target, $uid);
		return $this->ok($this->serializeReport($wr));
	}

	// ---- timesheets -----------------------------------------------------

	#[NoAdminRequired]
	public function timesheets(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$role = $this->perm->roleIn($projectId, $uid);
		$out = [];
		foreach ($this->timesheetMapper->findByProject($projectId) as $ts) {
			// Members see only their own Stundenzettel
			if (!in_array($role, ['admin', 'finance'], true) && $ts->getUid() !== $uid) {
				continue;
			}
			$out[] = $this->serializeTs($ts);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function generateTimesheet(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireBook($projectId, $uid);
		$in = $this->params();
		$targetUid = (string)($in['uid'] ?? $uid);
		if ($targetUid !== $uid) {
			$this->perm->requireFinance($projectId, $uid);
		}
		$ts = $this->timesheets->generate($projectId, $targetUid, (string)($in['period'] ?? ''),
			isset($in['ap_id']) && $in['ap_id'] !== '' ? (int)$in['ap_id'] : null, $uid);
		return $this->ok($this->serializeTs($ts), 201);
	}

	#[NoAdminRequired]
	public function submitTimesheet(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireBook($projectId, $uid);
		$this->license->requireFeature(LicenseService::FEATURE_TIMESHEET_SIGNOFF);
		$ts = $this->timesheets->submit($this->timesheets->getOrFail($projectId, $id), $uid);
		return $this->ok($this->serializeTs($ts));
	}

	#[NoAdminRequired]
	public function signTimesheet(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$this->license->requireFeature(LicenseService::FEATURE_TIMESHEET_SIGNOFF);
		$ts = $this->timesheets->sign($this->timesheets->getOrFail($projectId, $id), $uid);
		return $this->ok($this->serializeTs($ts));
	}

	#[NoAdminRequired]
	public function rejectTimesheet(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$this->license->requireFeature(LicenseService::FEATURE_TIMESHEET_SIGNOFF);
		$in = $this->params();
		$ts = $this->timesheets->reject($this->timesheets->getOrFail($projectId, $id), $uid, (string)($in['reason'] ?? ''));
		return $this->ok($this->serializeTs($ts));
	}

	private function serializeReport($wr): array
	{
		return [
			'id' => $wr->getId(), 'ap_id' => $wr->getApId(), 'period' => $wr->getPeriod(),
			'summary' => $wr->getSummary(), 'outcome' => $wr->getOutcome(),
			'state' => $wr->getState(), 'author_uid' => $wr->getAuthorUid(),
		];
	}

	private function serializeTs($ts): array
	{
		return [
			'id' => $ts->getId(), 'uid' => $ts->getUid(), 'ap_id' => $ts->getApId(),
			'period' => $ts->getPeriod(), 'minutes_total' => $ts->getMinutesTotal(),
			'state' => $ts->getState(), 'signed_by' => $ts->getSignedBy(),
			'signed_at' => $ts->getSignedAt()?->format(\DateTime::ATOM),
			'reject_reason' => $ts->getRejectReason(),
		];
	}
}
