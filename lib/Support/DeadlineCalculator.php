<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Fristen-Engine (MH-10): computes the deadline set for a project from its
 * Bewilligungszeitraum and template flags. Pure calendar math — no DB.
 * Returned shapes are dedupe-keyed so recompute is idempotent.
 *
 * Kinds:
 *  - zwischennachweis: one per calendar-year boundary inside the period,
 *    due N months after year end (template: zwischen_months, default 2)
 *  - nachweis_frist:   final Verwendungsnachweis, M months after bew_end
 *    (template: final_months, default 6)
 *  - bew_end_warning:  bew_end minus warn_days (default 30)
 *  - ap_end:           one per work package end date (advisory)
 */

namespace OCA\NachweisCheck\Support;

final class DeadlineCalculator
{
	private function __construct()
	{
	}

	/**
	 * @param array<string, mixed> $flags   template rule flags
	 * @param list<array{id:int, end_date:?string}> $apEnds work-package ends
	 * @return list<array{kind:string, due_date:string, dedupe_key:string, ref_id:?int}>
	 */
	public static function forProject(
		int $projectId,
		?string $bewStartYmd,
		?string $bewEndYmd,
		array $flags,
		array $apEnds = [],
	): array {
		$out = [];
		if ($bewEndYmd === null || $bewEndYmd === '') {
			return $out;
		}
		$end = new \DateTimeImmutable($bewEndYmd);

		$finalMonths = (int)($flags['final_months'] ?? 6);
		$due = $end->modify('first day of +' . $finalMonths . ' months')->modify('last day of this month');
		$out[] = [
			'kind' => 'nachweis_frist',
			'due_date' => $due->format('Y-m-d'),
			'dedupe_key' => $projectId . ':nachweis_frist:' . $due->format('Y-m-d'),
			'ref_id' => null,
		];

		$warnDays = (int)($flags['bew_end_warn_days'] ?? 30);
		$warn = $end->modify('-' . $warnDays . ' days');
		$out[] = [
			'kind' => 'bew_end_warning',
			'due_date' => $warn->format('Y-m-d'),
			'dedupe_key' => $projectId . ':bew_end_warning:' . $warn->format('Y-m-d'),
			'ref_id' => null,
		];

		// Zwischennachweise at every full calendar-year boundary inside the period.
		if ($bewStartYmd !== null && $bewStartYmd !== '' && ($flags['zwischen_enabled'] ?? true)) {
			$zwMonths = (int)($flags['zwischen_months'] ?? 2);
			$startYear = (int)(new \DateTimeImmutable($bewStartYmd))->format('Y');
			$endYear = (int)$end->format('Y');
			for ($y = $startYear; $y < $endYear; $y++) {
				$yearEnd = new \DateTimeImmutable($y . '-12-31');
				if ($yearEnd >= $end) {
					continue; // boundary coincides with period end → final, not zwischen
				}
				$zDue = $yearEnd->modify('first day of +' . $zwMonths . ' months')->modify('last day of this month');
				$out[] = [
					'kind' => 'zwischennachweis',
					'due_date' => $zDue->format('Y-m-d'),
					'dedupe_key' => $projectId . ':zwischennachweis:' . $y,
					'ref_id' => null,
				];
			}
		}

		foreach ($apEnds as $ap) {
			$apEnd = $ap['end_date'] ?? null;
			if ($apEnd === null || $apEnd === '') {
				continue;
			}
			$out[] = [
				'kind' => 'ap_end',
				'due_date' => $apEnd,
				'dedupe_key' => $projectId . ':ap_end:' . (int)$ap['id'] . ':' . $apEnd,
				'ref_id' => (int)$ap['id'],
			];
		}
		return $out;
	}
}
