<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\WorkPackageService;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class WorkPackageApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly WorkPackageService $aps,
		private readonly WorkPackageMapper $apMapper,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function index(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->apMapper->findByProject($projectId) as $ap) {
			$out[] = $this->serializeAp($ap);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function create(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$in = $this->params();
		$ap = $this->aps->create(
			$projectId, (string)($in['code'] ?? ''), (string)($in['title'] ?? ''),
			$in['owner_uid'] !== '' ? ($in['owner_uid'] ?? null) : null,
			$in['start_date'] !== '' ? ($in['start_date'] ?? null) : null,
			$in['end_date'] !== '' ? ($in['end_date'] ?? null) : null,
			$uid,
		);
		return $this->ok($this->serializeAp($ap), 201);
	}

	#[NoAdminRequired]
	public function setPositions(int $projectId, int $apId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$ap = $this->aps->getOrFail($projectId, $apId);
		$in = $this->params();
		$ids = $in['position_ids'] ?? null;
		if (!is_array($ids)) {
			throw new \OCA\NachweisCheck\Exception\ValidationException(['position_ids' => 'Position id list required.']);
		}
		$this->aps->setPositions($ap, $ids, $uid);
		return $this->ok($this->serializeAp($ap));
	}

	#[NoAdminRequired]
	public function close(int $projectId, int $apId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$ap = $this->aps->getOrFail($projectId, $apId);
		$ap = $this->aps->close($ap, $uid);
		return $this->ok($this->serializeAp($ap));
	}

	private function serializeAp($ap): array
	{
		return [
			'id' => $ap->getId(), 'code' => $ap->getCode(), 'title' => $ap->getTitle(),
			'owner_uid' => $ap->getOwnerUid(), 'state' => $ap->getState(),
			'start_date' => $ap->getStartDate()?->format('Y-m-d'),
			'end_date' => $ap->getEndDate()?->format('Y-m-d'),
			'position_ids' => $this->aps->positionIds($ap->getId()),
		];
	}
}
