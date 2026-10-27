<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\DeviationMapper;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\DeviationService;
use OCA\NachweisCheck\Service\PermissionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class DeviationApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly DeviationService $deviations,
		private readonly DeviationMapper $mapper,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function index(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->mapper->findByProject($projectId) as $d) {
			$out[] = [
				'id' => $d->getId(), 'scope' => $d->getScope(), 'ref_id' => $d->getRefId(),
				'kind' => $d->getKind(), 'reason_text' => $d->getReasonText(),
				'uid' => $d->getUid(), 'created_at' => $d->getCreatedAt()?->format(\DateTime::ATOM),
			];
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function create(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireBook($projectId, $uid);
		$in = $this->params();
		$d = $this->deviations->create(
			$projectId, (string)($in['scope'] ?? ''), (int)($in['ref_id'] ?? 0),
			(string)($in['kind'] ?? ''), (string)($in['reason_text'] ?? ''), $uid,
		);
		return $this->ok(['id' => $d->getId()], 201);
	}
}
