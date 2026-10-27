<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Sachbericht-Textbausteine (NH-08): assembles the Sachbericht skeleton
 * from accepted/submitted Arbeitsberichte — a writing AID, not an
 * automatism. Each AP gets one suggested block per reporting period
 * (summary + outcome, newest report wins within a period); the editor
 * stays responsible for the final text. Draft reports are excluded:
 * unsigned content must never leak into a funder-facing suggestion.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Db\WorkPackageMapper;
use OCA\NachweisCheck\Db\WorkReportMapper;

class SachberichtComposer
{
	public function __construct(
		private readonly WorkPackageMapper $apMapper,
		private readonly WorkReportMapper $reportMapper,
	) {
	}

	/**
	 * @return list<array{ap_code:string, ap_title:string, blocks:list<array{period:string, text:string}>}>
	 */
	public function compose(Project $project, string $fromYmd, string $toYmd): array
	{
		$periodStart = substr($fromYmd, 0, 7);
		$periodEnd = substr($toYmd, 0, 7);
		$out = [];
		foreach ($this->apMapper->findByProject($project->getId()) as $ap) {
			$byPeriod = [];
			foreach ($this->reportMapper->findByAp($ap->getId()) as $wr) {
				if (!in_array($wr->getState(), ['submitted', 'accepted', 'signed'], true)) {
					continue;
				}
				$period = $wr->getPeriod();
				if ($period < $periodStart || $period > $periodEnd) {
					continue;
				}
				// newest id wins within a period
				if (!isset($byPeriod[$period]) || $wr->getId() > $byPeriod[$period]['id']) {
					$byPeriod[$period] = ['id' => $wr->getId(), 'wr' => $wr];
				}
			}
			ksort($byPeriod);
			$blocks = [];
			foreach ($byPeriod as $period => $entry) {
				$wr = $entry['wr'];
				$text = trim((string)$wr->getSummary());
				$outcome = trim((string)$wr->getOutcome());
				if ($outcome !== '') {
					$text .= ($text !== '' ? "\n" : '') . 'Ergebnis: ' . $outcome;
				}
				if ($text !== '') {
					$blocks[] = ['period' => $period, 'text' => $text];
				}
			}
			$out[] = [
				'ap_code' => $ap->getCode(),
				'ap_title' => $ap->getTitle(),
				'blocks' => $blocks,
			];
		}
		return $out;
	}
}
