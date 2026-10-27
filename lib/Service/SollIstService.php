<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Live Soll-Ist per Einzelansatz (US-NW-04). Personnel cost is attributed
 * from time entries to their AP's mapped positions — evenly, remainder to
 * the lowest position id (deterministic; documented in decisions). Receipt
 * cost comes from splits, which are already position-exact.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\ReceiptSplitMapper;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Db\ApPositionMapper;
use OCA\NachweisCheck\Db\WorkReportMapper;
use OCA\NachweisCheck\Support\Money;
use OCA\NachweisCheck\Support\ThresholdEvaluator;

class SollIstService
{
	public function __construct(
		private readonly FinplanService $finplan,
		private readonly TemplateService $templates,
		private readonly ReceiptMapper $receiptMapper,
		private readonly ReceiptSplitMapper $splitMapper,
		private readonly TimeEntryMapper $entryMapper,
		private readonly WorkPackageMapper $apMapper,
		private readonly ApPositionMapper $apPosMapper,
		private readonly WorkReportMapper $reportMapper,
	) {
	}

	/**
	 * @return array{positions:list<array>, totals:array{budget:int, used:int}}
	 */
	public function forProject(\OCA\NachweisCheck\Db\Project $project): array
	{
		$active = $this->finplan->activeFor($project->getId());
		if ($active === null) {
			return ['positions' => [], 'totals' => ['budget' => 0, 'used' => 0]];
		}
		$positions = $this->finplan->positions($active);
		$flags = $this->templates->ruleFlags($project->getTemplateKey());
		if ($project->getFinancingMode() === 'festbetrag') {
			$flags['festbetrag'] = true;
			$flags['einzelansatz_20pct'] = false;
		}
		$used = array_fill_keys(array_map(static fn($p) => $p->getId(), $positions), 0);

		// receipt splits → position sums
		$receiptIds = [];
		foreach ($this->receiptMapper->findByProject($project->getId()) as $r) {
			$receiptIds[] = $r->getId();
		}
		foreach ($this->splitMapper->sumByPositions($receiptIds) as $posId => $sum) {
			if (isset($used[$posId])) {
				$used[$posId] += $sum;
			}
		}

		// personnel: time amounts → AP → mapped positions, evenly split,
		// remainder to lowest position id.
		foreach ($this->apMapper->findByProject($project->getId()) as $ap) {
			$links = $this->apPosMapper->findByAp($ap->getId());
			if ($links === []) {
				continue;
			}
			$mappedIds = array_map(static fn($l) => $l->getPositionId(), $links);
			sort($mappedIds);
			$apTotal = 0;
			foreach ($this->entryMapper->findByAp($ap->getId()) as $e) {
				$apTotal += $e->getAmountMinor() ?? 0;
			}
			if ($apTotal === 0) {
				continue;
			}
			$share = intdiv($apTotal, count($mappedIds));
			$remainder = $apTotal - $share * count($mappedIds);
			foreach ($mappedIds as $i => $posId) {
				if (isset($used[$posId])) {
					$used[$posId] += $share + ($i === 0 ? $remainder : 0);
				}
			}
		}

		$out = [];
		$totalBudget = 0;
		$totalUsed = 0;
		foreach ($positions as $pos) {
			$u = $used[$pos->getId()];
			$b = $pos->getBudgetMinor();
			$totalBudget += $b;
			$totalUsed += $u;
			$out[] = [
				'position_id' => $pos->getId(),
				'code' => $pos->getCode(),
				'title' => $pos->getTitle(),
				'kind' => $pos->getKind(),
				'budget_minor' => $b,
				'used_minor' => $u,
				'ratio_milli' => Money::ratioMilli($u, $b),
				'threshold' => ThresholdEvaluator::evaluate($b, $u, $flags),
			];
		}
		return ['positions' => $out, 'totals' => ['budget' => $totalBudget, 'used' => $totalUsed]];
	}

	/** Per-AP view: hours, attributed cost, report completion states. */
	public function perWorkPackage(\OCA\NachweisCheck\Db\Project $project): array
	{
		$out = [];
		foreach ($this->apMapper->findByProject($project->getId()) as $ap) {
			$minutes = 0;
			$cost = 0;
			foreach ($this->entryMapper->findByAp($ap->getId()) as $e) {
				$minutes += $e->getMinutes();
				$cost += $e->getAmountMinor() ?? 0;
			}
			$reports = [];
			foreach ($this->reportMapper->findByAp($ap->getId()) as $wr) {
				$reports[] = ['period' => $wr->getPeriod(), 'state' => $wr->getState()];
			}
			$out[] = [
				'ap_id' => $ap->getId(), 'code' => $ap->getCode(), 'title' => $ap->getTitle(),
				'state' => $ap->getState(), 'minutes' => $minutes, 'cost_minor' => $cost,
				'reports' => $reports,
			];
		}
		return $out;
	}
}
