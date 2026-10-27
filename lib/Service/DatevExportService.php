<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * DATEV-naher Belegexport (NH-07): a Buchungsstapel-shaped CSV the
 * external Steuerberater can map onto DATEV imports. "DATEV-nah" means
 * the column layout — not a certified EXTF/DTVF header, which requires
 * funder-specific Berater-/Mandantennummer the app does not own.
 *
 * Injection safety is a reject class (api-security-patterns §7): every
 * substituted text field goes through CsvCell::delimitedSafe with ';'
 * as the field separator, and every row through CsvCell::buildCsv which
 * additionally guards against formula-leading characters.
 *
 * Amounts use the German accounting format "1.234,56" without currency
 * symbols, like a real Buchungsstapel.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Db\Receipt;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\ReceiptSplitMapper;
use OCA\NachweisCheck\Support\CsvCell;
use OCA\NachweisCheck\Support\Money;

class DatevExportService
{
	/** Column layout of the exported Buchungsstapel (documented contract). */
	public const COLUMNS = [
		'Umsatz (ohne Soll/Haben-Kz)',
		'Soll/Haben-Kennzeichen',
		'WKZ Umsatz',
		'Konto',
		'Gegenkonto (ohne BU-Schluessel)',
		'Belegdatum',
		'Belegfeld 1',
		'Buchungstext',
	];

	public function __construct(
		private readonly ReceiptMapper $receiptMapper,
		private readonly ReceiptSplitMapper $splitMapper,
		private readonly PositionMapper $posMapper,
	) {
	}

	/**
	 * Render one CSV row per receipt SPLIT (position-exact), restricted to
	 * receipts whose invoice date falls inside [fromYmd, toYmd].
	 *
	 * Konto is derived from the position code — the project owner keeps
	 * position codes aligned to their chart of accounts, which is exactly
	 * how the Steuerberater maps them. Gegenkonto defaults to '1200'
	 * (bank) unless overridden.
	 *
	 * @return string CSV (semicolon-delimited, CRLF rows)
	 */
	public function buildCsv(int $projectId, string $fromYmd, string $toYmd, string $gegenkonto = '1200'): string
	{
		$rows = [self::COLUMNS];
		$receipts = $this->receiptMapper->findByProjectPeriod($projectId, $fromYmd, $toYmd);
		$posTitle = [];
		foreach ($receipts as $r) {
			foreach ($this->splitMapper->findByReceipt($r->getId()) as $split) {
				if (!isset($posTitle[$split->getPositionId()])) {
					$pos = $this->posMapper->findById($split->getPositionId());
					$posTitle[$split->getPositionId()] = $pos !== null
						? $pos->getCode()
						: (string)$split->getPositionId();
				}
				$rows[] = $this->splitRow($r, (int)$split->getAmountMinor(), $posTitle[$split->getPositionId()], $gegenkonto);
			}
		}
		return CsvCell::buildCsv($rows, ';');
	}

	/**
	 * @return list<string>
	 */
	private function splitRow(Receipt $r, int $amountMinor, string $konto, string $gegenkonto): array
	{
		$belegdatum = $r->getInvoiceDate()?->format('dmy') ?? '';
		$text = trim((string)$r->getVendor() . ' ' . (string)$r->getPurpose());
		if ($text === '') {
			$text = (string)$r->getInvoiceNo();
		}
		return [
			Money::formatMinor($amountMinor),
			'S',
			'EUR',
			CsvCell::delimitedSafe($konto, ';'),
			CsvCell::delimitedSafe($gegenkonto, ';'),
			$belegdatum,
			CsvCell::delimitedSafe((string)($r->getInvoiceNo() ?? ''), ';'),
			CsvCell::delimitedSafe(mb_substr($text, 0, 60), ';'),
		];
	}
}
