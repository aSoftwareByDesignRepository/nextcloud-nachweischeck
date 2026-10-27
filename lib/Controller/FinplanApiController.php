<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\PermissionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class FinplanApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly FinplanService $finplan,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function versions(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->finplan->listVersions($projectId) as $v) {
			$out[] = $this->serializeVer($v);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function positions(int $projectId, int $versionId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$ver = $this->finplan->getVersionOrFail($projectId, $versionId);
		return $this->ok(array_map(fn($p) => [
			'id' => $p->getId(), 'code' => $p->getCode(), 'title' => $p->getTitle(),
			'kind' => $p->getKind(), 'budget_minor' => $p->getBudgetMinor(), 'sort' => $p->getSort(),
			'cofunder_id' => $p->getCofunderId(),
		], $this->finplan->positions($ver)));
	}

	#[NoAdminRequired]
	public function newDraft(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$in = $this->params();
		$ver = $this->finplan->newDraft($projectId, $uid, (string)($in['note'] ?? ''));
		return $this->ok($this->serializeVer($ver), 201);
	}

	#[NoAdminRequired]
	public function replacePositions(int $projectId, int $versionId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$ver = $this->finplan->getVersionOrFail($projectId, $versionId);
		$in = $this->params();
		$positions = $in['positions'] ?? null;
		if (!is_array($positions)) {
			throw new \OCA\NachweisCheck\Exception\ValidationException(['positions' => 'Position list required.']);
		}
		$this->finplan->replacePositions($ver, $positions, $uid);
		return $this->ok(['version' => $ver->getVersion()]);
	}

	#[NoAdminRequired]
	public function activate(int $projectId, int $versionId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid);
		$ver = $this->finplan->getVersionOrFail($projectId, $versionId);
		$in = $this->params();
		$base = $in['base_version'] ?? null;
		$ver = $this->finplan->activate($ver, $base === null ? null : (int)$base, $uid);
		return $this->ok($this->serializeVer($ver));
	}

	private function serializeVer($v): array
	{
		return [
			'id' => $v->getId(), 'version' => $v->getVersion(), 'state' => $v->getState(),
			'base_version' => $v->getBaseVersion(), 'note' => $v->getNote(),
			'created_by' => $v->getCreatedBy(), 'activated_at' => $v->getActivatedAt()?->format(\DateTime::ATOM),
		];
	}
}
