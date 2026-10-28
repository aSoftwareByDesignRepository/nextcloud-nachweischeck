<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Pruefungsbereit" checklist (NH-04): every known audit finding rendered
 * as a countable task BEFORE the export runs, so the team fixes gaps
 * itself instead of the funder finding them. Same data sources as the
 * Nachweis "Fehlende Angaben" section — a clean checklist means a clean
 * export. Sorted blocker → warning → info; every check lists its items.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\ApPositionMapper;
use OCA\NachweisCheck\Db\ClaimMapper;
use OCA\NachweisCheck\Db\Drawdown;
use OCA\NachweisCheck\Db\DrawdownMapper;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\TimesheetMapper;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Support\GapCollector;
use OCP\IL10N;

class ReadinessService
{
	public const SEV_BLOCKER = 'blocker';
	public const SEV_WARNING = 'warning';
	public const SEV_INFO = 'info';

	public function __construct(
		private readonly FinplanService $finplan,
		private readonly WorkPackageMapper $apMapper,
		private readonly ApPositionMapper $apPosMapper,
		private readonly ReceiptMapper $receiptMapper,
		private readonly TimeEntryMapper $entryMapper,
		private readonly TimesheetMapper $timesheetMapper,
		private readonly DrawdownMapper $drawdownMapper,
		private readonly ClaimMapper $claimMapper,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Localized gap detail for the UI checklist. The Nachweis export keeps
	 * GapCollector's canonical German wording (manifest-hashed payload) —
	 * only this render boundary translates.
	 *
	 * @param array<string, string> $refs
	 */
	private function gapDetail(string $type, array $refs): string
	{
		return match ($type) {
			'missing_attachment' => $this->l10n->t('Receipt without attachment: %s', [$refs['vendor'] ?? '']),
			'missing_invoice_no' => $this->l10n->t('Receipt without invoice number: %s', [$refs['vendor'] ?? '']),
			'missing_payment_date' => $this->l10n->t('Receipt without payment date: %s', [$refs['vendor'] ?? '']),
			'unpriced_time' => $this->l10n->t('Time entry without hourly rate: %1$s / %2$s', [$refs['entry_date'] ?? '', $refs['uid'] ?? '']),
			default => $type,
		};
	}

	private function stateLabel(string $state): string
	{
		return match ($state) {
			'draft' => $this->l10n->t('draft'),
			'submitted' => $this->l10n->t('submitted'),
			'signed' => $this->l10n->t('signed'),
			'rejected' => $this->l10n->t('rejected'),
			default => $state,
		};
	}

	private function claimKindLabel(string $kind): string
	{
		return match ($kind) {
			'interest' => $this->l10n->t('Interest'),
			'recovery' => $this->l10n->t('Recovery'),
			default => $kind,
		};
	}

	/**
	 * @return array{checks:list<array{id:string,severity:string,count:int,items:list<string>}>, ready:bool}
	 */
	public function checklist(Project $project): array
	{
		$checks = [];

		// 1. No active Finanzierungsplan → nothing else can be correct.
		if ($this->finplan->activeFor($project->getId()) === null) {
			$checks[] = [
				'id' => 'no_active_plan', 'severity' => self::SEV_BLOCKER, 'count' => 1,
				'items' => [$this->l10n->t('No active financing plan — create and activate a plan.')],
			];
		}

		// 2. APs without a position mapping block clean cost attribution.
		$unmapped = [];
		foreach ($this->apMapper->findByProject($project->getId()) as $ap) {
			if ($ap->getState() !== 'closed' && $this->apPosMapper->findByAp($ap->getId()) === []) {
				$unmapped[] = $ap->getCode() . ' ' . $ap->getTitle();
			}
		}
		if ($unmapped !== []) {
			$checks[] = [
				'id' => 'unmapped_aps', 'severity' => self::SEV_BLOCKER, 'count' => count($unmapped),
				'items' => $unmapped,
			];
		}

		// 3. Receipt + time gaps — the exact same list the export prints.
		$receipts = $this->receiptMapper->findByProject($project->getId());
		$timeEntries = $this->entryMapper->findByProjectPeriod($project->getId(), '0001-01-01', '9999-12-31');
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
			fn(string $type, array $refs): string => $this->gapDetail($type, $refs),
		);
		if ($gaps !== []) {
			$checks[] = [
				'id' => 'missing_data', 'severity' => self::SEV_WARNING, 'count' => count($gaps),
				'items' => array_map(static fn($g) => $g['detail'], $gaps),
			];
		}

		// 4. Unacknowledged duplicate receipts.
		$dups = [];
		foreach ($receipts as $r) {
			if ($r->getDupFlag()) {
				$dups[] = ($r->getVendor() ?? '') . ' / ' . ($r->getInvoiceDate()?->format('Y-m-d') ?? '');
			}
		}
		if ($dups !== []) {
			$checks[] = [
				'id' => 'duplicate_receipts', 'severity' => self::SEV_WARNING, 'count' => count($dups),
				'items' => $dups,
			];
		}

		// 5. Unsigned timesheets (draft or submitted, not signed).
		$unsigned = [];
		foreach ($this->timesheetMapper->findByProject($project->getId()) as $ts) {
			if ($ts->getState() !== 'signed') {
				$unsigned[] = $ts->getUid() . ' / ' . $ts->getPeriod()
					. ' (' . $this->stateLabel($ts->getState()) . ')';
			}
		}
		if ($unsigned !== []) {
			$checks[] = [
				'id' => 'unsigned_timesheets', 'severity' => self::SEV_WARNING, 'count' => count($unsigned),
				'items' => $unsigned,
			];
		}

		// 6. Requested drawdowns still open — informational, money in flight.
		$pendingDd = [];
		foreach ($this->drawdownMapper->findByProjectState($project->getId(), Drawdown::STATE_REQUESTED) as $d) {
			$pendingDd[] = ($d->getRequestedDate()?->format('Y-m-d') ?? '') . ' — ' . $d->getAmountMinor() . ' ct';
		}
		if ($pendingDd !== []) {
			$checks[] = [
				'id' => 'pending_drawdowns', 'severity' => self::SEV_INFO, 'count' => count($pendingDd),
				'items' => $pendingDd,
			];
		}

		// 7. Open claims (interest/recovery) — informational but visible.
		$openClaims = [];
		foreach ($this->claimMapper->findByProject($project->getId()) as $c) {
			if ($c->getState() === 'open') {
				$openClaims[] = $c->getTitle() . ' (' . $this->claimKindLabel($c->getKind()) . ')';
			}
		}
		if ($openClaims !== []) {
			$checks[] = [
				'id' => 'open_claims', 'severity' => self::SEV_INFO, 'count' => count($openClaims),
				'items' => $openClaims,
			];
		}

		$order = [self::SEV_BLOCKER => 0, self::SEV_WARNING => 1, self::SEV_INFO => 2];
		usort($checks, static fn($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

		return [
			'checks' => $checks,
			'ready' => !array_filter($checks, static fn($c) => $c['severity'] === self::SEV_BLOCKER),
		];
	}
}
