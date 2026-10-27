<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * All fiscal arithmetic in integer minor units (cents). No float ever (NN-02).
 * Rounding policy: round-half-up at each defined conversion boundary
 * (minutes*rate, FX conversion); sums of minor units are exact by construction.
 */

namespace OCA\NachweisCheck\Support;

final class Money
{
	private function __construct()
	{
	}

	/**
	 * Personnel cost for a booking: minutes * hourly-rate(minor) / 60,
	 * rounded half-up to the nearest cent.
	 */
	public static function mulRate(int $minutes, int $rateMinorPerHour): int
	{
		if ($minutes < 0 || $rateMinorPerHour < 0) {
			throw new \InvalidArgumentException('negative money operand');
		}
		return intdiv($minutes * $rateMinorPerHour + 30, 60);
	}

	/**
	 * Foreign-currency conversion via the manual rate field (stored as
	 * rate * 1000, e.g. 1 EUR = 1.080 USD stored as 1080 when converting
	 * a USD amount to EUR: eur = total / rate).
	 */
	public static function applyFx(int $totalMinor, int $fxRateMilli): int
	{
		if ($totalMinor < 0 || $fxRateMilli <= 0) {
			throw new \InvalidArgumentException('invalid fx operand');
		}
		return intdiv($totalMinor * 1000 + intdiv($fxRateMilli, 2), $fxRateMilli);
	}

	/** "123456" -> "1.234,56" (de-DE, funder-facing export format). */
	public static function formatMinor(int $minor): string
	{
		$neg = $minor < 0;
		$abs = abs($minor);
		$euro = intdiv($abs, 100);
		$cent = $abs % 100;
		$out = number_format($euro, 0, '', '.') . ',' . str_pad((string)$cent, 2, '0', STR_PAD_LEFT);
		return $neg ? '-' . $out : $out;
	}

	/**
	 * Ratio in per-mille (used/budget * 1000). budget 0 → PHP_INT_MAX when
	 * used > 0 (infinite overshoot), 0 when both zero.
	 * Quotient+remainder arithmetic: overflow-safe for any int64 pair.
	 */
	public static function ratioMilli(int $used, int $budget): int
	{
		if ($budget <= 0) {
			return $used > 0 ? PHP_INT_MAX : 0;
		}
		if ($used < 0) {
			return 0;
		}
		$q = intdiv($used, $budget);
		if ($q > intdiv(PHP_INT_MAX, 1000)) {
			return PHP_INT_MAX;
		}
		$r = $used - $q * $budget;
		return $q * 1000 + intdiv($r * 1000, $budget);
	}

	/** Sum with strict typing — guards against float contamination. */
	public static function sum(int ...$amounts): int
	{
		$total = 0;
		foreach ($amounts as $a) {
			$total += $a;
		}
		return $total;
	}
}
