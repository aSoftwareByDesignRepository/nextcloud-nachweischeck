<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Fehlende Angaben" collector (US-NW-05 AC-4, GAC-7): every incomplete
 * record inside a Nachweis period is listed — never silently dropped.
 * Pure function over row arrays; the export lists exactly what this returns.
 */

namespace OCA\NachweisCheck\Support;

final class GapCollector
{
	private function __construct()
	{
	}

	/**
	 * @param list<array<string, mixed>> $receipts   receipt rows
	 * @param list<array<string, mixed>> $timeEntries time-entry rows
	 * @param null|callable(string, array<string, string>):string $detailFormatter
	 *        Localized detail renderer for UI callers. Receives the gap type
	 *        and the raw entity refs (vendor / entry_date+uid). When null the
	 *        canonical German export wording is emitted — the persisted
	 *        Nachweis payload ("Fehlende Angaben") is manifest-hashed, so its
	 *        detail text must never change between calls.
	 * @return list<array{type:string, entity:string, entity_id:int, detail:string}>
	 */
	public static function collect(array $receipts, array $timeEntries, ?callable $detailFormatter = null): array
	{
		$gaps = [];
		foreach ($receipts as $r) {
			$id = (int)$r['id'];
			$refs = ['vendor' => (string)($r['vendor'] ?? '')];
			if (empty($r['file_id'])) {
				$gaps[] = ['type' => 'missing_attachment', 'entity' => 'receipt', 'entity_id' => $id,
					'detail' => self::detail($detailFormatter, 'missing_attachment', $refs)];
			}
			if (($r['invoice_no'] ?? '') === '') {
				$gaps[] = ['type' => 'missing_invoice_no', 'entity' => 'receipt', 'entity_id' => $id,
					'detail' => self::detail($detailFormatter, 'missing_invoice_no', $refs)];
			}
			if (empty($r['payment_date'])) {
				$gaps[] = ['type' => 'missing_payment_date', 'entity' => 'receipt', 'entity_id' => $id,
					'detail' => self::detail($detailFormatter, 'missing_payment_date', $refs)];
			}
		}
		foreach ($timeEntries as $t) {
			if ($t['rate_minor'] === null || $t['amount_minor'] === null) {
				$gaps[] = ['type' => 'unpriced_time', 'entity' => 'time_entry', 'entity_id' => (int)$t['id'],
					'detail' => self::detail($detailFormatter, 'unpriced_time', [
						'entry_date' => (string)($t['entry_date'] ?? ''),
						'uid' => (string)($t['uid'] ?? ''),
					])];
			}
		}
		return $gaps;
	}

	/**
	 * @param null|callable(string, array<string, string>):string $formatter
	 * @param array<string, string> $refs
	 */
	private static function detail(?callable $formatter, string $type, array $refs): string
	{
		if ($formatter !== null) {
			return (string)$formatter($type, $refs);
		}
		return match ($type) {
			'missing_attachment' => 'Beleg ohne Anhang: ' . ($refs['vendor'] ?? ''),
			'missing_invoice_no' => 'Beleg ohne Rechnungsnummer: ' . ($refs['vendor'] ?? ''),
			'missing_payment_date' => 'Beleg ohne Zahldatum: ' . ($refs['vendor'] ?? ''),
			'unpriced_time' => 'Zeiteintrag ohne Stundensatz: ' . ($refs['entry_date'] ?? '') . ' / ' . ($refs['uid'] ?? ''),
			default => $type,
		};
	}
}
