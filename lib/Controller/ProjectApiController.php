<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\ProjectMapper;
use OCA\NachweisCheck\Db\ProjectMember;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\AntragExportService;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\DeadlineService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\ProjectService;
use OCA\NachweisCheck\Service\SollIstService;
use OCA\NachweisCheck\Service\WorkPackageService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

class ProjectApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectMemberMapper $memberMapper,
		private readonly ProjectService $projects,
		private readonly WorkPackageService $aps,
		private readonly SollIstService $sollIst,
		private readonly DeadlineService $deadlines,
		private readonly AuditService $audit,
		private readonly AntragExportService $antrag,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse
	{
		$uid = $this->uid();
		$out = [];
		foreach ($this->projectMapper->findForUid($uid) as $p) {
			$out[] = $this->serializeProject($p, $uid);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$user = $this->user();
		$in = $this->params();
		$p = $this->projects->create(
			$user->getUID(),
			(string)($in['name'] ?? ''),
			(string)($in['funder_name'] ?? ''),
			(string)($in['funder_ref'] ?? ''),
			$in['bew_start'] !== '' ? ($in['bew_start'] ?? null) : null,
			$in['bew_end'] !== '' ? ($in['bew_end'] ?? null) : null,
			(string)($in['template_key'] ?? 'anbest-p'),
			(string)($in['financing_mode'] ?? 'ausgaben'),
			$user->getDisplayName(),
		);
		return $this->ok($this->serializeProject($p, $user->getUID()), 201);
	}

	#[NoAdminRequired]
	public function show(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		return $this->ok($this->serializeProject($p, $uid) + [
			'soll_ist' => $this->sollIst->forProject($p),
			'work_packages' => $this->sollIst->perWorkPackage($p),
			'deadlines' => array_map(fn($d) => [
				'kind' => $d->getKind(), 'due_date' => $d->getDueDate()?->format('Y-m-d'),
				'notified' => $d->getNotifiedAt() !== null,
			], $this->deadlines->forProject($projectId)),
		]);
	}

	/** Project-level settings — currently retention_years (NFR, audited). */
	#[NoAdminRequired]
	public function update(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid);
		$p = $this->projects->updateSettings($this->projects->getOrFail($projectId), $this->params(), $uid);
		return $this->ok($this->serializeProject($p, $uid));
	}

	#[NoAdminRequired]
	public function activate(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		// Limit count + AP-mapping check run inside the service's
		// activation lock — a controller-side pre-check would be a TOCTOU.
		$p = $this->projects->activate($p, $uid);
		return $this->ok($this->serializeProject($p, $uid));
	}

	#[NoAdminRequired]
	public function transition(int $projectId, string $target): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		$p = $this->projects->transition($p, $target, $uid);
		return $this->ok($this->serializeProject($p, $uid));
	}

	#[NoAdminRequired]
	public function delete(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		if ($p->getState() !== 'draft') {
			throw new ConflictException('NOT_DELETABLE', 'Only draft projects can be deleted — a live project keeps its fiscal record.');
		}
		$this->projectMapper->delete($p);
		$this->audit->record($projectId, 'project', $projectId, 'delete', $uid);
		return $this->ok(['deleted' => true]);
	}

	/**
	 * Antragstellung document (NH-02): the draft/active Finanzierungsplan
	 * as a funder-facing application skeleton — CSV, ODS or PDF.
	 */
	#[NoAdminRequired]
	public function antrag(int $projectId): Response
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		$format = (string)($this->params()['format'] ?? 'pdf');
		[$content, $filename, $mime] = match ($format) {
			'csv' => [$this->antrag->buildCsv($p), "antrag-{$projectId}.csv", 'text/csv; charset=utf-8'],
			'ods' => [$this->antrag->buildOds($p), "antrag-{$projectId}.ods", 'application/vnd.oasis.opendocument.spreadsheet'],
			'pdf' => [$this->antrag->buildPdf($p), "antrag-{$projectId}.pdf", 'application/pdf'],
			default => throw new ValidationException(['format' => 'csv, ods or pdf.']),
		};
		$this->audit->record($projectId, 'export', $projectId, 'antrag_download', $uid, null, [
			'format' => $format, 'sha256' => hash('sha256', $content),
		]);
		return new DataDownloadResponse($content, $filename, $mime);
	}

	private function serializeProject($p, string $uid): array
	{
		return [
			'id' => $p->getId(),
			'name' => $p->getName(),
			'funder_name' => $p->getFunderName(),
			'funder_ref' => $p->getFunderRef(),
			'bew_start' => $p->getBewStart()?->format('Y-m-d'),
			'bew_end' => $p->getBewEnd()?->format('Y-m-d'),
			'template_key' => $p->getTemplateKey(),
			'financing_mode' => $p->getFinancingMode(),
			'retention_years' => $p->getRetentionYears(),
			'state' => $p->getState(),
			'capabilities' => $this->perm->capabilities($p->getId(), $uid),
		];
	}
}
