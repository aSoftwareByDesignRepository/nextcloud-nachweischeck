<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * ANBest-P Nr. 1.2 threshold: an Einzelansatz may overshoot up to 20 % if
 * offset by savings elsewhere (within_20); beyond that, funder approval is
 * required (blocked_over — warn, never hard-block entries per spec edge case).
 * Under Festbetragsfinanzierung the rule is disabled entirely (template flag).
 */

namespace OCA\NachweisCheck\Support;

final class ThresholdEvaluator
{
	public const STATE_OK = 'ok';
	public const STATE_WITHIN_20 = 'within_20';
	public const STATE_BLOCKED_OVER = 'blocked_over';
	public const STATE_NOT_APPLICABLE = 'not_applicable';

	private function __construct()
	{
	}

	/**
	 * @param array<string, mixed> $ruleFlags template rule flags
	 */
	public static function evaluate(int $budgetMinor, int $usedMinor, array $ruleFlags): string
	{
		if (!($ruleFlags['einzelansatz_20pct'] ?? true) || ($ruleFlags['festbetrag'] ?? false)) {
			return self::STATE_NOT_APPLICABLE;
		}
		if ($usedMinor <= $budgetMinor) {
			return self::STATE_OK;
		}
		if ($budgetMinor === 0) {
			return self::STATE_BLOCKED_OVER; // spend on unbudgeted position
		}
		// used > 1.2·budget ⇔ (budget + r) > 6/5·budget ⇔ 5·r > budget.
		// Exact integer math — no float, no truncation, no overflow (r ≤ budget·(q−1),
		// and if r alone already exceeds budget, 5r > budget holds trivially).
		$remainder = $usedMinor - $budgetMinor;
		$over20 = $remainder > $budgetMinor || $remainder * 5 > $budgetMinor;
		return $over20 ? self::STATE_BLOCKED_OVER : self::STATE_WITHIN_20;
	}
}
