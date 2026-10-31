<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Nachweis generation (US-NW-05, ADR-05/06, GAC-4/5/7).
 *
 *  - The payload is built once, hashed (SHA-256 over canonical JSON) and
 *    stored with state 'final'. There is NO update or delete path on a
 *    generated Nachweis — corrections mean generate() with supersedesId,
 *    which marks the old row superseded and mints version+1.
 *  - kind 'zwischen': only amounts RECEIVED in the reported year appear on
 *    the Einnahmen side (ANBest-P Nr. 6.1/6.6 semantics).
 *  - "Fehlende Angaben": every incomplete source record inside the period
 *    is enumerated — the export never silently drops.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Nachweis;
use OCA\NachweisCheck\Db\NachweisMapper;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\ReceiptSplitMapper;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\IncomingPaymentMapper;
use OCA\NachweisCheck\Db\CarryoverMapper;
use OCA\NachweisCheck\Db\ClaimMapper;
use OCA\NachweisCheck\Db\CofunderMapper;
use OCA\NachweisCheck\Db\DrawdownMapper;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Db\WorkReportMapper;
use OCA\NachweisCheck\Db\ApPositionMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\DbErrors;
use OCA\NachweisCheck\Support\GapCollector;
use OCA\NachweisCheck\Support\ManifestHasher;
use OCA\NachweisCheck\Support\Money;
use OCA\NachweisCheck\Support\PeriodUtil;

class NachweisService
{
	public const KIND_FINAL = 'final';
	public const KIND_ZWISCHEN = 'zwischen';

	public function __construct(
		private readonly NachweisMapper $mapper,
		private readonly ReceiptMapper $receiptMapper,
		private readonly ReceiptSplitMapper $splitMapper,
		private readonly TimeEntryMapper $entryMapper,
		private readonly IncomingPaymentMapper $paymentMapper,
		private readonly CarryoverMapper $carryoverMapper,
		private readonly ClaimMapper $claimMapper,
		private readonly CofunderMapper $cofunderMapper,
		private readonly DrawdownMapper $drawdownMapper,
		private readonly WorkPackageMapper $apMapper,
		private readonly WorkReportMapper $reportMapper,
		private readonly ApPositionMapper $apPosMapper,
		private readonly FinplanService $finplan,
		private readonly AuditService $audit,
		private readonly ConcurrencyGuard $guard,
	) {
	}

	public function getOrFail(int $projectId, int $id): Nachweis
	{
		$n = $this->mapper->findById($id);
		if ($n === null || $n->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $n;
	}

	/**
	 * Build the payload WITHOUT persisting — used for preview and for
	 * testing generation determinism.
	 */
	public function buildPayload(Project $project, string $kind, string $fromYmd, string $toYmd): array
	{
		if (!in_array($kind, [self::KIND_FINAL, self::KIND_ZWISCHEN], true)) {
			throw new ValidationException(['kind' => 'Invalid kind.']);
		}
		if (!PeriodUtil::isValidYmd($fromYmd) || !PeriodUtil::isValidYmd($toYmd) || $toYmd < $fromYmd) {
			throw new ValidationException(['period' => 'Invalid period.']);
		}

		$active = $this->finplan->activeFor($project->getId());
		$positions = $active !== null ? $this->finplan->positions($active) : [];

		$receipts = $this->receiptMapper->findByProjectPeriod($project->getId(), $fromYmd, $toYmd);
		$timeEntries = $this->entryMapper->findByProjectPeriod($project->getId(), $fromYmd, $toYmd);
		$payments = $this->paymentMapper->findByProject($project->getId());
		$carryovers = $this->carryoverMapper->findByProject($project->getId());

		// --- Ausgaben per Finanzierungsplan-Gliederung ----------------------
		$receiptIds = array_map(static fn($r) => $r->getId(), $receipts);
		$posSpend = array_fill_keys(array_map(static fn($p) => $p->getId(), $positions), 0);
		if ($receiptIds !== []) {
			// sum splits only for receipts inside the period
			$splitSums = $this->splitMapper->sumByPositions($receiptIds);
			foreach ($splitSums as $posId => $sum) {
				if (isset($posSpend[$posId])) {
					$posSpend[$posId] += $sum;
				}
			}
		}
		// personnel inside period
		foreach ($this->apMapper->findByProject($project->getId()) as $ap) {
			$links = $this->apPosMapper->findByAp($ap->getId());
			if ($links === []) {
				continue;
			}
			$mapped = array_map(static fn($l) => $l->getPositionId(), $links);
			sort($mapped);
			$apAmount = 0;
			foreach ($timeEntries as $e) {
				if ($e->getApId() === $ap->getId()) {
					$apAmount += $e->getAmountMinor() ?? 0;
				}
			}
			if ($apAmount === 0) {
				continue;
			}
			$share = intdiv($apAmount, count($mapped));
			$rem = $apAmount - $share * count($mapped);
			foreach ($mapped as $i => $posId) {
				if (isset($posSpend[$posId])) {
					$posSpend[$posId] += $share + ($i === 0 ? $rem : 0);
				}
			}
		}

		$cofNames = [0 => (string)$project->getFunderName()];
		foreach ($this->cofunderMapper->findByProject($project->getId()) as $cof) {
			$cofNames[$cof->getId()] = $cof->getName();
		}
		$ausgaben = [];
		foreach ($positions as $pos) {
			$ausgaben[] = [
				'code' => $pos->getCode(),
				'title' => $pos->getTitle(),
				'kind' => $pos->getKind(),
				'budget_minor' => $pos->getBudgetMinor(),
				'used_minor' => $posSpend[$pos->getId()] ?? 0,
				'cofunder_id' => $pos->getCofunderId(),
				'funder' => $cofNames[$pos->getCofunderId() ?? 0] ?? '',
			];
		}

		// --- Einnahmen -------------------------------------------------------
		$periodYears = range((int)substr($fromYmd, 0, 4), (int)substr($toYmd, 0, 4));
		$einnahmen = [];
		foreach ($payments as $p) {
			$rd = $p->getReceivedDate()?->format('Y-m-d');
			if ($kind === self::KIND_ZWISCHEN) {
				if (!in_array($p->getYearReceived(), $periodYears, true)) {
					continue; // Nr. 6.1/6.6: only the year's received amounts
				}
			} elseif ($rd === null || $rd < $fromYmd || $rd > $toYmd) {
				continue;
			}
			$einnahmen[] = [
				'received_date' => $rd,
				'amount_minor' => $p->getAmountMinor(),
				'call_ref' => $p->getCallRef(),
				'kind' => 'zuwendung',
			];
		}
		foreach ($carryovers as $c) {
			if (!in_array($c->getToYear(), $periodYears, true)) {
				continue;
			}
			$einnahmen[] = [
				'received_date' => $c->getToYear() . '-01-01',
				'amount_minor' => $c->getAmountMinor(),
				'call_ref' => $c->getDocRef(),
				'kind' => 'mitteluebertrag',
			];
		}
		usort($einnahmen, static fn($a, $b) => strcmp((string)$a['received_date'], (string)$b['received_date']));

		// --- Belegliste (ANBest-P Nr. 6.4) -----------------------------------
		$belegliste = [];
		foreach ($receipts as $r) {
			$belegliste[] = [
				'id' => $r->getId(),
				'invoice_date' => $r->getInvoiceDate()?->format('Y-m-d'),
				'payment_date' => $r->getPaymentDate()?->format('Y-m-d'),
				'vendor' => $r->getVendor(),
				'invoice_no' => $r->getInvoiceNo(),
				'purpose' => $r->getPurpose(),
				'amount_minor' => $r->getTotalMinor(),
				'kind' => $r->getKind(),
				'salary_period' => $r->getSalaryPeriod(),
				'has_attachment' => $r->getFileId() !== null,
			];
		}

		// --- Sachbericht-Gerüst ----------------------------------------------
		$periodStart = substr($fromYmd, 0, 7);
		$periodEnd = substr($toYmd, 0, 7);
		$sachbericht = [];
		foreach ($this->apMapper->findByProject($project->getId()) as $ap) {
			$reports = [];
			foreach ($this->reportMapper->findByAp($ap->getId()) as $wr) {
				if ($wr->getPeriod() < $periodStart || $wr->getPeriod() > $periodEnd) {
					continue;
				}
				$reports[] = [
					'period' => $wr->getPeriod(),
					'state' => $wr->getState(),
					'summary' => $wr->getSummary(),
					'outcome' => $wr->getOutcome(),
				];
			}
			$sachbericht[] = [
				'ap_code' => $ap->getCode(),
				'ap_title' => $ap->getTitle(),
				'reports' => $reports,
			];
		}

		// --- Zinsen/Rueckforderungen + Mittelabrufe (NH-06/NH-09) ------------
		$claims = [];
		foreach ($this->claimMapper->findByProject($project->getId()) as $c) {
			$occ = $c->getOccurredDate()?->format('Y-m-d');
			if ($occ === null || $occ < $fromYmd || $occ > $toYmd) {
				continue;
			}
			$claims[] = [
				'kind' => $c->getKind(), 'title' => $c->getTitle(),
				'principal_minor' => $c->getPrincipalMinor(), 'rate_bp' => $c->getRateBp(),
				'period_from' => $c->getPeriodFrom()?->format('Y-m-d'),
				'period_to' => $c->getPeriodTo()?->format('Y-m-d'),
				'amount_minor' => $c->getAmountMinor(), 'state' => $c->getState(),
				'occurred_date' => $occ,
			];
		}
		$drawdowns = [];
		foreach ($this->drawdownMapper->findByProject($project->getId()) as $d) {
			$drawdowns[] = [
				'requested_date' => $d->getRequestedDate()?->format('Y-m-d'),
				'amount_minor' => $d->getAmountMinor(), 'call_ref' => $d->getCallRef(),
				'state' => $d->getState(),
			];
		}

		// --- Fehlende Angaben -------------------------------------------------
		$gaps = GapCollector::collect(
			array_map(static fn($r) => [
				'id' => $r->getId(), 'vendor' => $r->getVendor(),
				'file_id' => $r->getFileId(), 'invoice_no' => $r->getInvoiceNo(),
				'payment_date' => $r->getPaymentDate()?->format('Y-m-d'),
			], $receipts),
			array_map(static fn($t) => [
				'id' => $t->getId(), 'uid' => $t->getUid(),
				'entry_date' => $t->getEntryDate()?->format('Y-m-d'),
				'rate_minor' => $t->getRateMinor(), 'amount_minor' => $t->getAmountMinor(),
			], $timeEntries),
		);

		return [
			'meta' => [
				'project_id' => $project->getId(),
				'project_name' => $project->getName(),
				'funder_name' => $project->getFunderName(),
				'funder_ref' => $project->getFunderRef(),
				'kind' => $kind,
				'period_from' => $fromYmd,
				'period_to' => $toYmd,
				'plan_version' => $active?->getVersion(),
			],
			'einnahmen' => $einnahmen,
			'ausgaben' => $ausgaben,
			'belegliste' => $belegliste,
			'sachbericht' => $sachbericht,
			'claims' => $claims,
			'drawdowns' => $drawdowns,
			'cofunders' => array_map(static fn($id, $name) => ['id' => $id, 'name' => $name],
				array_keys($cofNames), array_values($cofNames)),
			'fehlende_angaben' => $gaps,
		];
	}

	/**
	 * Generate + persist. To correct a finalized Nachweis pass
	 * $supersedesId + $reason — the old version is marked superseded and a
	 * new version minted. There is no other write path (NN-01, GAC-4).
	 */
	public function generate(Project $project, string $kind, string $fromYmd, string $toYmd, string $actor, ?int $supersedesId = null, ?string $reason = null): Nachweis
	{
		// Project lock: the version counter (max+1) and the supersede
		// state-check are both check-then-write races — two concurrent
		// generates would collide on the (project_id, version) unique key
		// or double-supersede the same Nachweis.
		return $this->guard->run(ConcurrencyGuard::projectKey($project->getId()), function () use ($project, $kind, $fromYmd, $toYmd, $actor, $supersedesId, $reason): Nachweis {
			$supersedes = null;
			if ($supersedesId !== null) {
				$supersedes = $this->getOrFail($project->getId(), $supersedesId);
				if ($supersedes->getState() !== 'final') {
					throw new ConflictException('ALREADY_SUPERSEDED', 'That Nachweis version is already superseded.');
				}
				if ($reason === null || trim($reason) === '') {
					throw new ValidationException(['reason' => 'A new version requires a reason.']);
				}
			}
			$payload = $this->buildPayload($project, $kind, $fromYmd, $toYmd);
			$hash = ManifestHasher::hash($payload);

			$n = new Nachweis();
			$n->setProjectId($project->getId());
			$n->setPeriodFrom(new \DateTime($fromYmd));
			$n->setPeriodTo(new \DateTime($toYmd));
			$n->setKind($kind);
			$n->setVersion($this->mapper->maxVersion($project->getId()) + 1);
			$n->setManifestHash($hash);
			$n->setPayloadJson(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			$n->setState('final');
			$n->setSupersedesId($supersedesId);
			$n->setReason($reason !== null ? trim($reason) : '');
			$n->setGeneratedBy($actor);
			$n->setGeneratedAt(new \DateTime());

			if ($supersedes !== null) {
				$supersedes->setState('superseded');
				$this->mapper->update($supersedes);
			}
			try {
				$n = $this->mapper->insert($n);
			} catch (\Throwable $e) {
				if (!DbErrors::isUniqueViolation($e)) {
					throw $e;
				}
				throw new ConflictException('VERSION_CONFLICT', 'A Nachweis was generated meanwhile — reload and retry.');
			}
			$this->audit->record($project->getId(), 'nachweis', $n->getId(), 'generate', $actor, null, [
				'kind' => $kind, 'version' => $n->getVersion(), 'manifest_hash' => $hash,
				'supersedes' => $supersedesId,
			]);
			// Receipts inside the reported period are now covered by a fiscal
			// document — flip their lifecycle to 'filed' so list surfaces can
			// badge them. Source data stays editable (corrections mint a new
			// version + reason); the flag means "was part of a Nachweis".
			foreach ($this->receiptMapper->findByProjectPeriod($project->getId(), $fromYmd, $toYmd) as $r) {
				if ($r->getState() !== 'filed') {
					$r->setState('filed');
					$this->receiptMapper->update($r);
				}
			}
			return $n;
		});
	}

	/**
	 * Re-verify integrity: recompute the manifest from stored payload and
	 * compare — a tampered row fails loudly (Threat-Model: tamper test).
	 */
	public function verify(Nachweis $n): bool
	{
		$payload = json_decode($n->getPayloadJson(), true);
		if (!is_array($payload)) {
			return false;
		}
		return hash_equals($n->getManifestHash(), ManifestHasher::hash($payload));
	}
}
