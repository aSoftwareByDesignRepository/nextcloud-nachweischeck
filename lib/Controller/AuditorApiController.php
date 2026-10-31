<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Auditor read surface (US-NW-06): every endpoint resolves the caller's
 * ACTIVE grant for the project, logs the read, and returns scoped data.
 * Members/finance are intentionally NOT rerouted here — this surface is
 * auditor-only so the read log stays complete.
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\NachweisMapper;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\AuditorReadLogger;
use OCA\NachweisCheck\Service\AuditorService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\SollIstService;
use OCA\NachweisCheck\Service\ProjectService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class AuditorApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly AuditorService $auditors,
		private readonly AuditorReadLogger $readLogger,
		private readonly ProjectService $projects,
		private readonly SollIstService $sollIst,
		private readonly ReceiptMapper $receiptMapper,
		private readonly NachweisMapper $nachweisMapper,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	private function grant(int $projectId, string $section = ''): \OCA\NachweisCheck\Db\AuditorGrant
	{
		$uid = $this->uid();
		$g = $this->auditors->activeGrant($projectId, $uid);
		if ($g === null) {
			throw new NotFoundException(); // no existence oracle for non-grantees
		}
		if ($section !== '') {
			$scope = json_decode((string)($g->getScopeJson() ?? ''), true);
			if (is_array($scope) && $scope !== [] && !in_array($section, $scope, true)) {
				throw new NotFoundException(); // out-of-scope = same uniform 404
			}
		}
		return $g;
	}

	/** Projects the auditor currently has a valid grant for. */
	#[NoAdminRequired]
	public function myProjects(): JSONResponse
	{
		$uid = $this->uid();
		$out = [];
		$grantMapper = null;
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		foreach ($this->auditors->grantsForUid($uid) as $g) {
			$from = $g->getValidFrom()?->format('Y-m-d');
			$to = $g->getValidTo()?->format('Y-m-d');
			$state = $g->getRevokedAt() !== null ? 'revoked'
				: ($from !== null && $from > $today ? 'pending'
				: ($to !== null && $to < $today ? 'expired' : 'active'));
			$out[] = [
				'grant_id' => $g->getId(), 'project_id' => $g->getProjectId(),
				'valid_from' => $from, 'valid_to' => $to,
				'state' => $state, 'active' => $state === 'active',
			];
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function project(int $projectId): JSONResponse
	{
		$g = $this->grant($projectId);
		$this->readLogger->log($g, 'auditor:project', 'project', $projectId);
		$p = $this->projects->getOrFail($projectId);
		return $this->ok([
			'id' => $p->getId(), 'name' => $p->getName(), 'funder_name' => $p->getFunderName(),
			'funder_ref' => $p->getFunderRef(), 'state' => $p->getState(),
			'bew_start' => $p->getBewStart()?->format('Y-m-d'),
			'bew_end' => $p->getBewEnd()?->format('Y-m-d'),
		]);
	}

	#[NoAdminRequired]
	public function sollIst(int $projectId): JSONResponse
	{
		$g = $this->grant($projectId, 'soll_ist');
		$this->readLogger->log($g, 'auditor:soll_ist', 'project', $projectId);
		$p = $this->projects->getOrFail($projectId);
		return $this->ok($this->sollIst->forProject($p));
	}

	#[NoAdminRequired]
	public function belege(int $projectId): JSONResponse
	{
		$g = $this->grant($projectId, 'belege');
		$this->readLogger->log($g, 'auditor:belege', 'project', $projectId);
		$out = [];
		foreach ($this->receiptMapper->findByProject($projectId) as $r) {
			$out[] = $this->serializeReceipt($r);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function nachweise(int $projectId): JSONResponse
	{
		$g = $this->grant($projectId, 'nachweise');
		$this->readLogger->log($g, 'auditor:nachweise', 'project', $projectId);
		$out = [];
		foreach ($this->nachweisMapper->findByProject($projectId) as $n) {
			$out[] = [
				'id' => $n->getId(), 'kind' => $n->getKind(), 'version' => $n->getVersion(),
				'manifest_hash' => $n->getManifestHash(), 'state' => $n->getState(),
				'generated_at' => $n->getGeneratedAt()?->format(\DateTime::ATOM),
			];
		}
		return $this->ok($out);
	}
}
