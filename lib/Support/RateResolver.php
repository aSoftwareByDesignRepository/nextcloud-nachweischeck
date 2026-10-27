<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Dated rate resolution (ADR-04): the rate applied to a booking is the newest
 * history row with valid_from <= booking date. History rows are append-only —
 * a "rate edit" is a new row, so past entries keep their captured amount.
 */

namespace OCA\NachweisCheck\Support;

final class RateResolver
{
	private function __construct()
	{
	}

	/**
	 * @param list<array{valid_from:string, rate_minor:int}> $rows ISO-date sorted or not
	 */
	public static function resolve(array $rows, string $dateYmd): ?int
	{
		$bestDate = null;
		$bestRate = null;
		foreach ($rows as $row) {
			$vf = $row['valid_from'];
			if ($vf <= $dateYmd && ($bestDate === null || $vf >= $bestDate)) {
				$bestDate = $vf;
				$bestRate = $row['rate_minor'];
			}
		}
		return $bestRate;
	}
}
