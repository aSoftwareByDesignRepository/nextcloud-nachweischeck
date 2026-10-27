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
	 * @return list<array{type:string, entity:string, entity_id:int, detail:string}>
	 */
	public static function collect(array $receipts, array $timeEntries): array
	{
		$gaps = [];
		foreach ($receipts as $r) {
			$id = (int)$r['id'];
			if (empty($r['file_id'])) {
				$gaps[] = ['type' => 'missing_attachment', 'entity' => 'receipt', 'entity_id' => $id,
					'detail' => 'Beleg ohne Anhang: ' . (string)($r['vendor'] ?? '')];
			}
			if (($r['invoice_no'] ?? '') === '') {
				$gaps[] = ['type' => 'missing_invoice_no', 'entity' => 'receipt', 'entity_id' => $id,
					'detail' => 'Beleg ohne Rechnungsnummer: ' . (string)($r['vendor'] ?? '')];
			}
			if (empty($r['payment_date'])) {
				$gaps[] = ['type' => 'missing_payment_date', 'entity' => 'receipt', 'entity_id' => $id,
					'detail' => 'Beleg ohne Zahldatum: ' . (string)($r['vendor'] ?? '')];
			}
		}
		foreach ($timeEntries as $t) {
			if ($t['rate_minor'] === null || $t['amount_minor'] === null) {
				$gaps[] = ['type' => 'unpriced_time', 'entity' => 'time_entry', 'entity_id' => (int)$t['id'],
					'detail' => 'Zeiteintrag ohne Stundensatz: ' . (string)($t['entry_date'] ?? '') . ' / ' . (string)($t['uid'] ?? '')];
			}
		}
		return $gaps;
	}
}
