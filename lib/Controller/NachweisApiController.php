<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\NachweisMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\DatevExportService;
use OCA\NachweisCheck\Service\ExportPackageBuilder;
use OCA\NachweisCheck\Service\NachweisService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\ProjectService;
use OCA\NachweisCheck\Service\ReadinessService;
use OCA\NachweisCheck\Service\SachberichtComposer;
use OCA\NachweisCheck\Support\PeriodUtil;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

class NachweisApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly NachweisService $nachweis,
		private readonly NachweisMapper $mapper,
		private readonly ProjectService $projects,
		private readonly ExportPackageBuilder $exporter,
		private readonly AuditService $audit,
		private readonly ReadinessService $readiness,
		private readonly DatevExportService $datev,
		private readonly SachberichtComposer $sachbericht,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	#[NoAdminRequired]
	public function index(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->mapper->findByProject($projectId) as $n) {
			$out[] = $this->serialize($n);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function show(int $projectId, int $id): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$n = $this->nachweis->getOrFail($projectId, $id);
		return $this->ok($this->serialize($n) + [
			'payload' => json_decode($n->getPayloadJson(), true),
			'integrity' => $this->nachweis->verify($n),
		]);
	}

	#[NoAdminRequired]
	public function generate(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		$in = $this->params();
		$n = $this->nachweis->generate(
			$p, (string)($in['kind'] ?? 'final'),
			(string)($in['period_from'] ?? ''), (string)($in['period_to'] ?? ''),
			$uid,
			isset($in['supersedes_id']) && $in['supersedes_id'] !== '' ? (int)$in['supersedes_id'] : null,
			$in['reason'] ?? null,
		);
		// ADR-06: pre-build the export package in the background so a
		// 2k-Beleg download never builds inside the request that needs it.
		\OCP\Server::get(\OCP\BackgroundJob\IJobList::class)->add(
			\OCA\NachweisCheck\BackgroundJob\NachweisExportJob::class,
			['nachweisId' => $n->getId(), 'projectId' => $projectId],
		);
		return $this->ok($this->serialize($n), 201);
	}

	/** Preview the payload WITHOUT generating (UI "Prüfen" step). */
	#[NoAdminRequired]
	public function preview(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		$in = $this->params();
		$payload = $this->nachweis->buildPayload($p, (string)($in['kind'] ?? 'final'),
			(string)($in['period_from'] ?? ''), (string)($in['period_to'] ?? ''));
		return $this->ok($payload);
	}

	/**
	 * Download one section CSV or the full ZIP package.
	 */
	#[NoAdminRequired]
	public function download(int $projectId, int $id, string $section): Response
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$n = $this->nachweis->getOrFail($projectId, $id);
		if ($section === 'zip') {
			$path = $this->exporter->zip($n);
			if ($path === null) {
				throw new ValidationException(['zip' => 'ZIP extension unavailable.']);
			}
			$content = file_get_contents($path);
			@unlink($path);
			return new DataDownloadResponse($content, "nachweis-{$n->getId()}-v{$n->getVersion()}.zip", 'application/zip');
		}
		$files = $this->exporter->sections($n);
		if (!isset($files[$section])) {
			throw new NotFoundException();
		}
		$mime = str_ends_with($section, '.ods')
			? 'application/vnd.oasis.opendocument.spreadsheet'
			: (str_ends_with($section, '.pdf') ? 'application/pdf' : 'text/csv; charset=utf-8');
		return new DataDownloadResponse($files[$section], $section, $mime);
	}

	/**
	 * "Pruefungsbereit" checklist (NH-04): every audit gap as a countable
	 * task before the export runs — same sources, no second truth.
	 */
	#[NoAdminRequired]
	public function readiness(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		return $this->ok($this->readiness->checklist($p));
	}

	/**
	 * DATEV-naher Belegexport (NH-07): one CSV row per split, semicolon
	 * Buchungsstapel layout. Finance-role only — it leaves the instance.
	 */
	#[NoAdminRequired]
	public function datev(int $projectId): Response
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$this->projects->getOrFail($projectId);
		$in = $this->params();
		$from = (string)($in['from'] ?? '0001-01-01');
		$to = (string)($in['to'] ?? '9999-12-31');
		if (!PeriodUtil::isValidYmd($from) || !PeriodUtil::isValidYmd($to) || $to < $from) {
			throw new ValidationException(['period' => 'Invalid period.']);
		}
		$gegenkonto = (string)($in['gegenkonto'] ?? '1200');
		if (!preg_match('/^[0-9]{1,10}$/', $gegenkonto)) {
			throw new ValidationException(['gegenkonto' => 'Digits only.']);
		}
		$csv = $this->datev->buildCsv($projectId, $from, $to, $gegenkonto);
		// Money-adjacent export — every download lands in the audit trail
		// (NFR Observability: export events logged, actor + artifact hash).
		$this->audit->record($projectId, 'export', $projectId, 'datev_download', $uid, null, [
			'from' => $from, 'to' => $to, 'sha256' => hash('sha256', $csv),
		]);
		return new DataDownloadResponse($csv, "datev-export-{$projectId}.csv", 'text/csv; charset=utf-8');
	}

	/**
	 * Sachbericht-Textbausteine (NH-08): suggested blocks per AP from
	 * submitted/accepted work reports inside the requested period.
	 */
	#[NoAdminRequired]
	public function sachberichtSuggestions(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$p = $this->projects->getOrFail($projectId);
		$in = $this->params();
		$from = (string)($in['from'] ?? ($p->getBewStart()?->format('Y-m-d') ?? '0001-01-01'));
		$to = (string)($in['to'] ?? ($p->getBewEnd()?->format('Y-m-d') ?? '9999-12-31'));
		if (!PeriodUtil::isValidYmd($from) || !PeriodUtil::isValidYmd($to) || $to < $from) {
			throw new ValidationException(['period' => 'Invalid period.']);
		}
		return $this->ok($this->sachbericht->compose($p, $from, $to));
	}

	private function serialize($n): array
	{
		return [
			'id' => $n->getId(), 'kind' => $n->getKind(), 'version' => $n->getVersion(),
			'period_from' => $n->getPeriodFrom()?->format('Y-m-d'),
			'period_to' => $n->getPeriodTo()?->format('Y-m-d'),
			'manifest_hash' => $n->getManifestHash(),
			'state' => $n->getState(),
			'supersedes_id' => $n->getSupersedesId(),
			'reason' => $n->getReason(),
			'generated_by' => $n->getGeneratedBy(),
			'generated_at' => $n->getGeneratedAt()?->format(\DateTime::ATOM),
		];
	}
}
