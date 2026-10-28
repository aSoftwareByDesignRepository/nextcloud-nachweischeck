<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\TimeEntryService;
use OCA\NachweisCheck\Support\PeriodUtil;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class TimeEntryApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly TimeEntryService $entries,
		private readonly TimeEntryMapper $entryMapper,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function index(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$in = $this->params();
		$from = (string)($in['from'] ?? '1900-01-01');
		$to = (string)($in['to'] ?? '2100-12-31');
		if (!PeriodUtil::isValidYmd($from) || !PeriodUtil::isValidYmd($to)) {
			throw new \OCA\NachweisCheck\Exception\ValidationException(['period' => 'Invalid date range.']);
		}
		$rows = $this->entryMapper->findByProjectPeriod($projectId, $from, $to);
		// Members see only their own entries (privacy floor); finance/admin see all.
		$role = $this->perm->roleIn($projectId, $uid);
		$out = [];
		foreach ($rows as $e) {
			if (!in_array($role, ['admin', 'finance'], true) && $e->getUid() !== $uid) {
				continue;
			}
			$out[] = $this->serializeTime($e, $projectId, $uid);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function create(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireBook($projectId, $uid);
		$in = $this->params();
		$targetUid = (string)($in['uid'] ?? $uid);
		if ($targetUid !== $uid) {
			// booking for others is a finance/admin capability
			$this->perm->requireFinance($projectId, $uid);
		}
		$e = $this->entries->create(
			$projectId, (int)($in['ap_id'] ?? 0), $targetUid,
			(string)($in['entry_date'] ?? ''), (int)($in['minutes'] ?? 0),
			(string)($in['note'] ?? ''), $in['external_ref'] ?? null, $uid,
		);
		return $this->ok($this->serializeTime($e, $projectId, $uid), 201);
	}

	#[NoAdminRequired]
	public function update(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$e = $this->entries->getOrFail($projectId, $id);
		$this->requireOwnerOrFinance($projectId, $uid, $e->getUid());
		$in = $this->params();
		$e = $this->entries->update($e, (string)($in['entry_date'] ?? $e->getEntryDate()?->format('Y-m-d')),
			(int)($in['minutes'] ?? $e->getMinutes()), (string)($in['note'] ?? $e->getNote()), $uid);
		return $this->ok($this->serializeTime($e, $projectId, $uid));
	}

	#[NoAdminRequired]
	public function delete(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$e = $this->entries->getOrFail($projectId, $id);
		$this->requireOwnerOrFinance($projectId, $uid, $e->getUid());
		$this->entries->delete($e, $uid);
		return $this->ok(['deleted' => true]);
	}

	private function requireOwnerOrFinance(int $projectId, string $uid, string $ownerUid): void
	{
		if ($ownerUid === $uid) {
			$this->perm->requireBook($projectId, $uid);
			return;
		}
		$this->perm->requireFinance($projectId, $uid);
	}
}
