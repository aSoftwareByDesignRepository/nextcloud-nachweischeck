<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\ProjectService;
use OCA\NachweisCheck\Service\SollIstService;
use OCA\NachweisCheck\Service\DeadlineService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class DashboardApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly ProjectService $projects,
		private readonly SollIstService $sollIst,
		private readonly DeadlineService $deadlines,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function project(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		$deadlines = array_map(fn($d) => [
			'kind' => $d->getKind(),
			'due_date' => $d->getDueDate()?->format('Y-m-d'),
			'notified' => $d->getNotifiedAt() !== null,
		], $this->deadlines->forProject($projectId));
		return $this->ok([
			'soll_ist' => $this->sollIst->forProject($p),
			'work_packages' => $this->sollIst->perWorkPackage($p),
			'deadlines' => $deadlines,
		]);
	}
}
