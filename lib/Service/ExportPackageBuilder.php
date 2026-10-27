<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Nachweis export package (US-NW-05, I-04): per-section CSV renderings of
 * the STORED payload (never re-queried — the immutable payload is the
 * source of truth) plus manifest.json with the manifest hash. Amounts are
 * rendered de-DE "1.234,56" for the funder; raw minor units stay in the
 * JSON payload for machine consumers.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Nachweis;
use OCA\NachweisCheck\Support\CsvCell;
use OCA\NachweisCheck\Support\Money;
use OCA\NachweisCheck\Support\OdsBuilder;
use OCA\NachweisCheck\Support\SimplePdfBuilder;

class ExportPackageBuilder
{
	/**
	 * Section CSVs for one stored Nachweis.
	 * @return array<string, string> filename => csv content
	 */
	public function sections(Nachweis $n): array
	{
		$payload = json_decode($n->getPayloadJson(), true);
		if (!is_array($payload)) {
			return [];
		}
		$files = [];

		$rows = [['Position', 'Titel', 'Art', 'Geber', 'Bewilligt (EUR)', 'Verwendet (EUR)']];
		foreach ($payload['ausgaben'] ?? [] as $a) {
			$rows[] = [
				CsvCell::delimitedSafe($a['code']), CsvCell::delimitedSafe($a['title']),
				$a['kind'], CsvCell::delimitedSafe((string)($a['funder'] ?? '')),
				Money::formatMinor((int)$a['budget_minor']),
				Money::formatMinor((int)$a['used_minor']),
			];
		}
		$files['01_ausgaben.csv'] = CsvCell::buildCsv($rows);

		$rows = [['Datum', 'Betrag (EUR)', 'Abruf-Nr./Referenz', 'Art']];
		foreach ($payload['einnahmen'] ?? [] as $e) {
			$rows[] = [
				$e['received_date'], Money::formatMinor((int)$e['amount_minor']),
				CsvCell::delimitedSafe($e['call_ref']), $e['kind'],
			];
		}
		$files['02_einnahmen.csv'] = CsvCell::buildCsv($rows);

		$rows = [['Rechnungsdatum', 'Zahldatum', 'Empfänger', 'Rechnungsnr.', 'Verwendungszweck', 'Betrag (EUR)', 'Anhang']];
		foreach ($payload['belegliste'] ?? [] as $b) {
			$rows[] = [
				$b['invoice_date'], $b['payment_date'] ?? '',
				CsvCell::delimitedSafe($b['vendor']), CsvCell::delimitedSafe($b['invoice_no'] ?? ''),
				CsvCell::delimitedSafe($b['purpose']), Money::formatMinor((int)$b['amount_minor']),
				$b['has_attachment'] ? 'ja' : 'fehlt',
			];
		}
		$files['03_belegliste.csv'] = CsvCell::buildCsv($rows);

		$rows = [['Typ', 'Entität', 'ID', 'Detail']];
		foreach ($payload['fehlende_angaben'] ?? [] as $g) {
			$rows[] = [$g['type'], $g['entity'], $g['entity_id'], CsvCell::delimitedSafe($g['detail'])];
		}
		$files['04_fehlende_angaben.csv'] = CsvCell::buildCsv($rows);

		$txt = '';
		foreach ($payload['sachbericht'] ?? [] as $ap) {
			$txt .= '=== ' . $ap['ap_code'] . ' — ' . $ap['ap_title'] . " ===\n";
			foreach ($ap['reports'] as $r) {
				$txt .= "[{$r['period']} / {$r['state']}]\n{$r['summary']}\n{$r['outcome']}\n\n";
			}
		}
		$files['05_sachbericht.txt'] = $txt;

		// Zinsen/Rueckforderungen (NH-06) + Mittelabruf-Historie (NH-09) —
		// ANBest-P Nr. 8 verlangt die Meldung verzinslicher Restmittel.
		$rows = [['Art', 'Titel', 'Stichtag', 'Grundbetrag (EUR)', 'Satz (Bp)', 'Betrag (EUR)', 'Stand']];
		foreach ($payload['claims'] ?? [] as $c) {
			$rows[] = [
				$c['kind'], CsvCell::delimitedSafe($c['title']), $c['occurred_date'],
				Money::formatMinor((int)$c['principal_minor']), (string)(int)$c['rate_bp'],
				Money::formatMinor((int)$c['amount_minor']), $c['state'],
			];
		}
		$files['06_forderungen.csv'] = CsvCell::buildCsv($rows);

		$rows = [['Abrufdatum', 'Betrag (EUR)', 'Abruf-Nr.', 'Stand']];
		foreach ($payload['drawdowns'] ?? [] as $d) {
			$rows[] = [
				$d['requested_date'], Money::formatMinor((int)$d['amount_minor']),
				CsvCell::delimitedSafe((string)($d['call_ref'] ?? '')), $d['state'],
			];
		}
		$files['07_mittelabrufe.csv'] = CsvCell::buildCsv($rows);

		// Spreadsheet + human-readable renderings of the same payload (I-04).
		$files['10_nachweis.ods'] = $this->buildOds($payload);
		$files['11_nachweis.pdf'] = $this->buildPdf($n, $payload);

		$files['manifest.json'] = json_encode([
			'app' => 'nachweischeck',
			'manifest_hash' => $n->getManifestHash(),
			'kind' => $n->getKind(),
			'version' => $n->getVersion(),
			'period_from' => $n->getPeriodFrom()?->format('Y-m-d'),
			'period_to' => $n->getPeriodTo()?->format('Y-m-d'),
			'generated_by' => $n->getGeneratedBy(),
			'generated_at' => $n->getGeneratedAt()?->format(\DateTime::ATOM),
			'sections' => array_map(static fn(string $c): int => substr_count($c, "\r\n"), array_diff_key($files, ['manifest.json' => true])),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

		return $files;
	}

	/**
	 * ODS rendering of Einnahmen/Ausgaben/Belegliste/Fehlende Angaben.
	 * Amounts stay minor units? No — funder-facing ODS uses EUR decimals
	 * (float cells only for display; the CSV/JSON remain canonical).
	 * @param array<string,mixed> $payload
	 */
	private function buildOds(array $payload): string
	{
		$sheets = [];
		$rows = [];
		foreach ($payload['ausgaben'] ?? [] as $a) {
			$rows[] = [$a['code'], $a['title'], $a['kind'], (string)($a['funder'] ?? ''),
				Money::formatMinor((int)$a['budget_minor']), Money::formatMinor((int)$a['used_minor'])];
		}
		$sheets[] = ['name' => 'Ausgaben', 'columns' => ['Position', 'Titel', 'Art', 'Geber', 'Bewilligt (EUR)', 'Verwendet (EUR)'], 'rows' => $rows];
		$rows = [];
		foreach ($payload['einnahmen'] ?? [] as $e) {
			$rows[] = [$e['received_date'], Money::formatMinor((int)$e['amount_minor']), $e['call_ref'], $e['kind']];
		}
		$sheets[] = ['name' => 'Einnahmen', 'columns' => ['Datum', 'Betrag (EUR)', 'Referenz', 'Art'], 'rows' => $rows];
		$rows = [];
		foreach ($payload['belegliste'] ?? [] as $b) {
			$rows[] = [$b['invoice_date'], $b['payment_date'] ?? '', $b['vendor'],
				$b['invoice_no'] ?? '', $b['purpose'], Money::formatMinor((int)$b['amount_minor']),
				($b['has_attachment'] ?? false) ? 'ja' : 'fehlt'];
		}
		$sheets[] = ['name' => 'Belegliste', 'columns' => ['Rechnungsdatum', 'Zahldatum', 'Empfänger', 'Rechnungsnr.', 'Zweck', 'Betrag (EUR)', 'Anhang'], 'rows' => $rows];
		$rows = [];
		foreach ($payload['fehlende_angaben'] ?? [] as $g) {
			$rows[] = [$g['type'], $g['entity'], (string)$g['entity_id'], $g['detail']];
		}
		$sheets[] = ['name' => 'Fehlende Angaben', 'columns' => ['Typ', 'Entität', 'ID', 'Detail'], 'rows' => $rows];
		return (new OdsBuilder())->build($sheets, 'NachweisCheck');
	}

	/**
	 * PDF rendering — zahlenmäßiger Nachweis (Ausgaben vs. Bewilligung) as a
	 * statement the funder can read without opening spreadsheets.
	 * @param array<string,mixed> $payload
	 */
	private function buildPdf(Nachweis $n, array $payload): string
	{
		$meta = $payload['meta'] ?? [];
		$rows = [];
		$totB = 0; $totU = 0;
		foreach ($payload['ausgaben'] ?? [] as $a) {
			$totB += (int)$a['budget_minor']; $totU += (int)$a['used_minor'];
			$rows[] = [$a['code'] . ' ' . $a['title'], Money::formatMinor((int)$a['budget_minor']) . ' €',
				Money::formatMinor((int)$a['used_minor']) . ' €'];
		}
		$rows[] = ['Summe', Money::formatMinor($totB) . ' €', Money::formatMinor($totU) . ' €'];
		return SimplePdfBuilder::buildStatement([
			'brand' => 'NachweisCheck',
			'title' => ($meta['kind'] ?? '') === 'zwischen' ? 'Zwischennachweis' : 'Verwendungsnachweis',
			'meta' => [
				['Projekt', (string)($meta['project_name'] ?? '')],
				['Zuwendungsgeber', (string)($meta['funder_name'] ?? '')],
				['Aktenzeichen', (string)($meta['funder_ref'] ?? '')],
				['Zeitraum', ($meta['period_from'] ?? '') . ' bis ' . ($meta['period_to'] ?? '')],
				['Finanzierungsplan', 'Version ' . (string)($meta['plan_version'] ?? '—')],
				['Manifest', substr((string)$n->getManifestHash(), 0, 16) . '…'],
			],
			'tableTitle' => 'Ausgaben je Einzelansatz',
			'columns' => ['Position', 'Bewilligt', 'Verwendet'],
			'colWidths' => [0.5, 0.25, 0.25],
			'rows' => $rows,
			'note' => 'Fehlende Angaben: ' . count($payload['fehlende_angaben'] ?? [])
				. ' — siehe Belegliste und Fehlende-Angaben-Sektion im Paket.',
		]);
	}

	/** Bundle the sections into a ZIP archive; returns the temp path. */
	public function zip(Nachweis $n): ?string
	{
		if (!class_exists(\ZipArchive::class)) {
			return null;
		}
		$files = $this->sections($n);
		$tmp = tempnam(sys_get_temp_dir(), 'nw_export_') . '.zip';
		$zip = new \ZipArchive();
		if ($zip->open($tmp, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
			return null;
		}
		foreach ($files as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		return $tmp;
	}
}
