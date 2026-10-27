<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Support;

final class PeriodUtil
{
	private function __construct()
	{
	}

	/** YYYY-MM validation + first/last day helpers. */
	public static function validPeriod(string $period): bool
	{
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
			return false;
		}
		return true;
	}

	public static function periodFirstDay(string $period): string
	{
		return $period . '-01';
	}

	public static function periodLastDay(string $period): string
	{
		return (new \DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d');
	}

	public static function isValidYmd(string $ymd): bool
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
			return false;
		}
		[$y, $m, $d] = array_map('intval', explode('-', $ymd));
		return checkdate($m, $d, $y);
	}
}
