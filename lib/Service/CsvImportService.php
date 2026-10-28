<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * CSV import (V1-14, I-03): generic schema plus presets shaped like common
 * exports (arbeitszeitcheck times, budgetcheck receipts). Idempotent via
 * external_ref — a re-imported row is skipped, never duplicated. Every
 * batch is recorded; row errors are collected, not fatal.
 *
 * Generic times header:    date,uid,ap_code,minutes,note[,external_ref]
 * Generic receipts header: invoice_date,vendor,invoice_no,total_cents,tax_cents,payment_date,purpose,position_code[,external_ref]
 * Money in import CSV is CENTS (integer) or EURO decimal ("12,34"/"12.34")
 * when the column name ends in _eur — both normalized to minor units.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\ImportBatch;
use OCA\NachweisCheck\Db\ImportBatchMapper;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Exception\ValidationException;

class CsvImportService
{
	public function __construct(
		private readonly ImportBatchMapper $batchMapper,
		private readonly TimeEntryMapper $entryMapper,
		private readonly ReceiptMapper $receiptMapper,
		private readonly TimeEntryService $timeService,
		private readonly ReceiptService $receiptService,
		private readonly WorkPackageService $apService,
		private readonly FinplanService $finplan,
	) {
	}

	/**
	 * @return array{batch_id:int, rows_ok:int, rows_failed:int, errors:list<array{row:int, error:string}>}
	 */
	public function importTimes(int $projectId, string $preset, string $csvText, string $filename, string $actor): array
	{
		$rows = $this->parseCsv($csvText);
		$batch = $this->openBatch($projectId, 'times', $preset, $filename, $actor);
		$ok = 0;
		$failed = 0;
		$errors = [];
		foreach ($rows as $i => $row) {
			try {
				$m = $this->mapTimeRow($preset, $row);
				$ref = $m['external_ref'] ?? null;
				if ($ref === null || $ref === '') {
					$ref = $preset . ':' . hash('sha256', json_encode($row));
				}
				if ($this->entryMapper->findByExternalRef($projectId, $ref) !== null) {
					$ok++; // idempotent: already imported
					continue;
				}
				$ap = $this->apService->findByCode($projectId, $m['ap_code']);
				if ($ap === null) {
					throw new \OCA\NachweisCheck\Exception\NotFoundException();
				}
				$this->timeService->create($projectId, $ap->getId(), $m['uid'], $m['date'], $m['minutes'], $m['note'] ?? '', $ref, $actor);
				$ok++;
			} catch (\Throwable $e) {
				if ($this->isDuplicateRef($e)) {
					$ok++; // idempotent: concurrent import won the unique key
					continue;
				}
				$failed++;
				$errors[] = ['row' => $i + 2, 'error' => $e->getMessage()];
			}
		}
		return $this->closeBatch($batch, $ok, $failed, $errors);
	}

	public function importReceipts(int $projectId, string $preset, string $csvText, string $filename, string $actor): array
	{
		$rows = $this->parseCsv($csvText);
		$batch = $this->openBatch($projectId, 'receipts', $preset, $filename, $actor);
		$ok = 0;
		$failed = 0;
		$errors = [];
		foreach ($rows as $i => $row) {
			try {
				$m = $this->mapReceiptRow($preset, $row);
				$ref = $m['external_ref'] ?? null;
				if ($ref === null || $ref === '') {
					$ref = $preset . ':' . hash('sha256', json_encode($row));
				}
				if ($this->receiptMapper->findByExternalRef($projectId, $ref) !== null) {
					$ok++;
					continue;
				}
				$posId = $this->positionIdByCode($projectId, $m['position_code']);
				$this->receiptService->create(
					$projectId, $m['vendor'], $m['invoice_no'] ?? null, $m['invoice_date'],
					$m['payment_date'] ?? null, 'EUR', null, $m['total_minor'],
					$m['tax_minor'] ?? 0, $m['purpose'] ?? '', null, 'receipt',
					[['position_id' => $posId, 'amount_minor' => $m['total_minor'], 'note' => '']],
					$ref, $actor, true,
				);
				$ok++;
			} catch (\Throwable $e) {
				if ($this->isDuplicateRef($e)) {
					$ok++;
					continue;
				}
				$failed++;
				$errors[] = ['row' => $i + 2, 'error' => $e->getMessage()];
			}
		}
		return $this->closeBatch($batch, $ok, $failed, $errors);
	}

	// ---- presets --------------------------------------------------------

	/**
	 * A concurrent import that won the external_ref unique key surfaces as a
	 * ValidationException on that field (services convert the DB unique
	 * violation). That row is already imported → count ok, not failed.
	 */
	private function isDuplicateRef(\Throwable $e): bool
	{
		return $e instanceof \OCA\NachweisCheck\Exception\ValidationException
			&& isset($e->getFields()['external_ref']);
	}

	private function mapTimeRow(string $preset, array $row): array
	{
		return match ($preset) {
			// arbeitszeitcheck-shaped export: date;person;task_or_ap;hours;note
			'arbeitszeitcheck' => [
				'date' => (string)($row['date'] ?? $row['datum'] ?? ''),
				'uid' => (string)($row['uid'] ?? $row['person'] ?? $row['user'] ?? ''),
				'ap_code' => (string)($row['ap_code'] ?? $row['ap'] ?? $row['task'] ?? ''),
				'minutes' => $this->toMinutes($row['minutes'] ?? $row['hours'] ?? $row['stunden'] ?? ''),
				'note' => (string)($row['note'] ?? $row['beschreibung'] ?? ''),
				'external_ref' => $row['external_ref'] ?? $row['id'] ?? null,
			],
			default => [
				'date' => (string)($row['date'] ?? ''),
				'uid' => (string)($row['uid'] ?? ''),
				'ap_code' => (string)($row['ap_code'] ?? ''),
				'minutes' => $this->toMinutes($row['minutes'] ?? ''),
				'note' => (string)($row['note'] ?? ''),
				'external_ref' => $row['external_ref'] ?? null,
			],
		};
	}

	private function mapReceiptRow(string $preset, array $row): array
	{
		return match ($preset) {
			// budgetcheck-shaped: date;vendor;amount;category;note
			'budgetcheck' => [
				'invoice_date' => (string)($row['date'] ?? $row['invoice_date'] ?? $row['datum'] ?? ''),
				'vendor' => (string)($row['vendor'] ?? $row['payee'] ?? $row['haendler'] ?? ''),
				'invoice_no' => $row['invoice_no'] ?? $row['beleg_nr'] ?? null,
				'total_minor' => $this->toMinor($row['total_cents'] ?? $row['amount'] ?? $row['betrag'] ?? ''),
				'tax_minor' => 0,
				'payment_date' => $row['payment_date'] ?? $row['date'] ?? null,
				'purpose' => (string)($row['purpose'] ?? $row['note'] ?? $row['verwendungszweck'] ?? ''),
				'position_code' => (string)($row['position_code'] ?? $row['category'] ?? $row['kategorie'] ?? ''),
				'external_ref' => $row['external_ref'] ?? $row['id'] ?? null,
			],
			default => [
				'invoice_date' => (string)($row['invoice_date'] ?? ''),
				'vendor' => (string)($row['vendor'] ?? ''),
				'invoice_no' => $row['invoice_no'] ?? null,
				'total_minor' => $this->toMinor($row['total_cents'] ?? $row['total_eur'] ?? ''),
				'tax_minor' => $this->toMinor($row['tax_cents'] ?? $row['tax_eur'] ?? '0'),
				'payment_date' => $row['payment_date'] ?? null,
				'purpose' => (string)($row['purpose'] ?? ''),
				'position_code' => (string)($row['position_code'] ?? ''),
				'external_ref' => $row['external_ref'] ?? null,
			],
		};
	}

	private function toMinutes(mixed $v): int
	{
		if (is_int($v)) {
			return $v;
		}
		$s = str_replace(',', '.', trim((string)$v));
		if (is_numeric($s) && str_contains($s, '.')) {
			return (int)round(((float)$s) * 60); // hours → minutes
		}
		return (int)$s;
	}

	private function toMinor(mixed $v): int
	{
		if (is_int($v)) {
			return $v;
		}
		$s = trim((string)$v);
		if ($s === '') {
			return 0;
		}
		if (preg_match('/^-?\d+$/', $s)) {
			return (int)$s; // already minor units
		}
		$s = str_replace(['.', ','], ['', '.'], $s); // 1.234,56 → 1234.56
		if (!is_numeric($s)) {
			throw new ValidationException(['amount' => 'Unparseable amount: ' . $v]);
		}
		return (int)round(((float)$s) * 100);
	}

	// ---- csv plumbing ---------------------------------------------------

	/** @return list<array<string, string>> keyed rows */
	private function parseCsv(string $text): array
	{
		$text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
		$fh = fopen('php://memory', 'r+');
		fwrite($fh, $text);
		rewind($fh);
		$first = fgets($fh) ?: '';
		$sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
		rewind($fh);
		$header = fgetcsv($fh, 0, $sep, '"', '');
		if ($header === false || $header === null) {
			fclose($fh);
			throw new ValidationException(['file' => 'Empty or unreadable CSV.']);
		}
		$header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
		$rows = [];
		while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false && $r !== null) {
			if (count($r) === 1 && trim((string)$r[0]) === '') {
				continue;
			}
			$rows[] = array_combine($header, array_map('strval', $r)) ?: [];
		}
		fclose($fh);
		if ($rows === []) {
			// Header-only / garbage input must fail visibly — a 200 with a
			// zero-row batch looks like a successful import that did nothing.
			throw new ValidationException(['file' => 'CSV has no data rows.']);
		}
		return $rows;
	}

	private function openBatch(int $projectId, string $kind, string $preset, string $filename, string $actor): ImportBatch
	{
		$b = new ImportBatch();
		$b->setProjectId($projectId);
		$b->setKind($kind);
		$b->setPreset($preset);
		$b->setFilename($filename);
		$b->setCreatedBy($actor);
		$b->setCreatedAt(new \DateTime());
		return $this->batchMapper->insert($b);
	}

	private function closeBatch(ImportBatch $b, int $ok, int $failed, array $errors): array
	{
		$b->setRowsOk($ok);
		$b->setRowsFailed($failed);
		$this->batchMapper->update($b);
		return ['batch_id' => $b->getId(), 'rows_ok' => $ok, 'rows_failed' => $failed, 'errors' => $errors];
	}

	private function positionIdByCode(int $projectId, string $code): int
	{
		$active = $this->finplan->activeFor($projectId);
		if ($active === null) {
			throw new ValidationException(['position_code' => 'No active Finanzierungsplan.']);
		}
		foreach ($this->finplan->positions($active) as $pos) {
			if (strcasecmp($pos->getCode(), $code) === 0) {
				return $pos->getId();
			}
		}
		throw new \OCA\NachweisCheck\Exception\NotFoundException();
	}
}
