<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * GAC-2 split invariant: receipt splits must always sum to the receipt total,
 * every split strictly positive, at least one split. Evaluated on the wire
 * shape (plain arrays) so the same rule guards API, import and service paths.
 */

namespace OCA\NachweisCheck\Support;

use OCA\NachweisCheck\Exception\ValidationException;

final class SplitValidator
{
	private function __construct()
	{
	}

	/**
	 * @param list<array{position_id:mixed, amount_minor:mixed, ap_id?:mixed}> $splits
	 * @throws ValidationException
	 */
	public static function validate(int $totalMinor, array $splits): void
	{
		if ($totalMinor <= 0) {
			throw new ValidationException(['total_minor' => 'Total must be a positive amount.']);
		}
		if (count($splits) === 0) {
			throw new ValidationException(['splits' => 'At least one split position is required.']);
		}
		$sum = 0;
		foreach ($splits as $i => $s) {
			$amount = $s['amount_minor'] ?? null;
			$pos = $s['position_id'] ?? null;
			if (!is_int($amount) || (is_string($amount) && !preg_match('/^-?\d+$/', $amount))) {
				if (!is_numeric($amount) || (string)(int)$amount !== (string)$amount) {
					throw new ValidationException(['splits' => "Split #{$i}: amount must be an integer minor unit."]);
				}
				$amount = (int)$amount;
			}
			$amount = (int)$amount;
			if ($amount <= 0) {
				throw new ValidationException(['splits' => "Split #{$i}: amount must be positive."]);
			}
			if (!is_numeric($pos) || (int)$pos <= 0) {
				throw new ValidationException(['splits' => "Split #{$i}: invalid position."]);
			}
			$sum += $amount;
		}
		if ($sum !== $totalMinor) {
			throw new ValidationException([
				'splits' => 'Split amounts must add up to the receipt total.',
			]);
		}
	}
}
