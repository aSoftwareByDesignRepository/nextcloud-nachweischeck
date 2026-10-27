<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Interest on unused grant funds (NH-06, ANBest-P Nr. 8): German
 * commercial convention act/360 — every month counts 30 days, the year
 * 360. All math stays in integer minor units; the half-up rounding
 * happens once at the end, never mid-formula (NN-02).
 *
 *   interest = principal × rate_bp × days / (360 × 10 000)
 */

namespace OCA\NachweisCheck\Support;

final class InterestCalculator
{
	private function __construct()
	{
	}

	/**
	 * Days between two Y-m-d dates under the 30/360 convention:
	 * each month contributes 30 days, end-of-month clamps to 30.
	 * Inclusive start, exclusive end (bank convention); a zero-length or
	 * inverted period yields 0 days — never a negative charge.
	 */
	public static function days360(string $fromYmd, string $toYmd): int
	{
		if (!PeriodUtil::isValidYmd($fromYmd) || !PeriodUtil::isValidYmd($toYmd) || $toYmd <= $fromYmd) {
			return 0;
		}
		[$y1, $m1, $d1] = array_map('intval', explode('-', $fromYmd));
		[$y2, $m2, $d2] = array_map('intval', explode('-', $toYmd));
		$d1 = min($d1, 30);
		// 31st of the end month counts as the 30th only when the start was
		// also month-end — the ISDA/European (30E/360) rule used in German
		// grant practice.
		$d2 = min($d2, 30);
		return max(0, ($y2 - $y1) * 360 + ($m2 - $m1) * 30 + ($d2 - $d1));
	}

	/**
	 * Interest in minor units, rounded half-up at the final step only.
	 * Negative rate or principal yields 0 — a charge can never invert.
	 */
	public static function interestMinor(int $principalMinor, int $rateBp, int $days): int
	{
		if ($principalMinor <= 0 || $rateBp <= 0 || $days <= 0) {
			return 0;
		}
		$numerator = $principalMinor * $rateBp * $days;
		$denominator = 360 * 10000;
		return intdiv($numerator + intdiv($denominator, 2), $denominator);
	}
}
