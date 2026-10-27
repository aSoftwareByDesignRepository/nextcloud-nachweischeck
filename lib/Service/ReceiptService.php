<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Beleg engine (US-NW-03, GAC-2). Split invariant enforced in-service on
 * every write path; the receipt row plus its splits commit in one
 * transaction so a half-written Beleg can never exist. Duplicate detection
 * (vendor+date+amount+number) is a soft flag — never auto-drops. Foreign
 * currency requires the manual fx_rate_milli field (no auto-FX).
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\Receipt;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\ReceiptSplit;
use OCA\NachweisCheck\Db\ReceiptSplitMapper;
use OCA\NachweisCheck\Db\PositionMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\DbErrors;
use OCA\NachweisCheck\Support\PeriodUtil;
use OCA\NachweisCheck\Support\SplitValidator;
use OCP\IDBConnection;

class ReceiptService
{
	public function __construct(
		private readonly ReceiptMapper $mapper,
		private readonly ReceiptSplitMapper $splitMapper,
		private readonly PositionMapper $posMapper,
		private readonly FinplanService $finplan,
		private readonly AuditService $audit,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @param list<array{position_id:int, ap_id?:?int, amount_minor:int, note?:string}> $splits
	 */
	public function create(
		int $projectId, string $vendor, ?string $invoiceNo, string $invoiceDateYmd,
		?string $paymentDateYmd, string $currency, ?int $fxRateMilli, int $totalMinor,
		int $taxMinor, string $purpose, ?int $fileId, string $kind,
		array $splits, ?string $externalRef, string $actor, bool $confirmDuplicate = false,
		?string $salaryPeriod = null,
	): Receipt {
		$fields = $this->validateFields($vendor, $invoiceDateYmd, $paymentDateYmd, $currency, $fxRateMilli, $totalMinor, $taxMinor, $kind);
		$fields += $this->validateSalaryPeriod($kind, $salaryPeriod);
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		SplitValidator::validate($totalMinor, $splits);
		$this->assertPositionsInActivePlan($projectId, $splits);

		$externalRef = $externalRef !== null && trim($externalRef) !== '' ? trim($externalRef) : null;
		if ($externalRef !== null
			&& $this->mapper->findByExternalRef($projectId, $externalRef) !== null) {
			throw new ValidationException(['external_ref' => 'Duplicate external reference.']);
		}

		$dup = $this->mapper->findDuplicate($projectId, $vendor, $invoiceDateYmd, $totalMinor, $invoiceNo);

		$this->db->beginTransaction();
		try {
			$r = new Receipt();
			$r->setProjectId($projectId);
			$r->setVendor($vendor);
			$r->setInvoiceNo($invoiceNo);
			$r->setInvoiceDate(new \DateTime($invoiceDateYmd));
			$r->setPaymentDate($paymentDateYmd !== null ? new \DateTime($paymentDateYmd) : null);
			$r->setCurrency($currency);
			$r->setFxRateMilli($fxRateMilli);
			$r->setTotalMinor($totalMinor);
			$r->setTaxMinor($taxMinor);
			$r->setPurpose($purpose);
			$r->setFileId($fileId);
			$r->setKind(in_array($kind, ['receipt', 'salary'], true) ? $kind : 'receipt');
			$r->setState('open');
			$r->setDupFlag($dup !== null && !$confirmDuplicate);
			$r->setExternalRef($externalRef);
			$r->setSalaryPeriod($salaryPeriod);
			$r->setCreatedBy($actor);
			$now = new \DateTime();
			$r->setCreatedAt($now);
			$r->setUpdatedAt($now);
			$r = $this->mapper->insert($r);
			$this->writeSplits($r->getId(), $splits);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if (DbErrors::isUniqueViolation($e)) {
				throw new ValidationException(['external_ref' => 'Duplicate external reference.']);
			}
			throw $e;
		}
		$this->audit->record($projectId, 'receipt', $r->getId(), 'create', $actor, null, [
			'vendor' => $vendor, 'total_minor' => $totalMinor, 'dup_flag' => $r->getDupFlag(),
			'dup_of' => $dup?->getId(),
		]);
		return $r;
	}

	/**
	 * Update mutable Beleg fields. Splits are NOT touched here — they have
	 * their own validated path; total_minor changes that would invalidate
	 * existing splits are refused unless the caller re-validates splits too.
	 */
	public function update(Receipt $r, array $in, string $actor): Receipt
	{
		$vendor = (string)($in['vendor'] ?? $r->getVendor());
		$invoiceNo = array_key_exists('invoice_no', $in) ? $in['invoice_no'] : $r->getInvoiceNo();
		$invoiceDate = (string)($in['invoice_date'] ?? $r->getInvoiceDate()?->format('Y-m-d'));
		$paymentDate = array_key_exists('payment_date', $in) ? $in['payment_date'] : $r->getPaymentDate()?->format('Y-m-d');
		$total = (int)($in['total_minor'] ?? $r->getTotalMinor());
		$tax = (int)($in['tax_minor'] ?? $r->getTaxMinor());
		$purpose = (string)($in['purpose'] ?? $r->getPurpose());
		$currency = (string)($in['currency'] ?? $r->getCurrency());
		$fx = array_key_exists('fx_rate_milli', $in) ? $in['fx_rate_milli'] : $r->getFxRateMilli();
		$fileId = array_key_exists('file_id', $in) ? $in['file_id'] : $r->getFileId();
		$salaryPeriod = array_key_exists('salary_period', $in) ? ($in['salary_period'] !== '' ? (string)$in['salary_period'] : null) : $r->getSalaryPeriod();

		$fields = $this->validateFields($vendor, $invoiceDate, $paymentDate, $currency, $fx === null ? null : (int)$fx, $total, $tax, $r->getKind());
		$fields += $this->validateSalaryPeriod($r->getKind(), $salaryPeriod);
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		// total changed → existing splits must still satisfy the invariant
		if ($total !== $r->getTotalMinor()) {
			$sum = 0;
			foreach ($this->splitMapper->findByReceipt($r->getId()) as $s) {
				$sum += $s->getAmountMinor();
			}
			if ($sum !== $total) {
				throw new ValidationException(['total_minor' => 'Total change invalidates the existing splits — update splits first.']);
			}
		}
		$before = [
			'vendor' => $r->getVendor(), 'total_minor' => $r->getTotalMinor(),
			'invoice_date' => $r->getInvoiceDate()?->format('Y-m-d'),
		];
		$r->setVendor($vendor);
		$r->setInvoiceNo($invoiceNo);
		$r->setInvoiceDate(new \DateTime($invoiceDate));
		$r->setPaymentDate($paymentDate !== null && $paymentDate !== '' ? new \DateTime($paymentDate) : null);
		$r->setTotalMinor($total);
		$r->setTaxMinor($tax);
		$r->setPurpose($purpose);
		$r->setCurrency($currency);
		$r->setFxRateMilli($fx === null ? null : (int)$fx);
		$r->setFileId($fileId === null ? null : (int)$fileId);
		$r->setSalaryPeriod($salaryPeriod);
		if (($in['dup_ack'] ?? null) === '1' || ($in['dup_ack'] ?? null) === true) {
			$r->setDupFlag(false);
		} else {
			$r->setDupFlag($this->mapper->findDuplicate($r->getProjectId(), $vendor, $invoiceDate, $total, $invoiceNo, $r->getId()) !== null);
		}
		$r->setUpdatedAt(new \DateTime());
		$r = $this->mapper->update($r);
		$this->audit->record($r->getProjectId(), 'receipt', $r->getId(), 'update', $actor, $before, [
			'vendor' => $vendor, 'total_minor' => $total,
		]);
		return $r;
	}

	public function replaceSplits(Receipt $r, array $splits, string $actor): Receipt
	{
		SplitValidator::validate($r->getTotalMinor(), $splits);
		$this->assertPositionsInActivePlan($r->getProjectId(), $splits);
		$this->db->beginTransaction();
		try {
			$this->splitMapper->deleteByReceipt($r->getId());
			$this->writeSplits($r->getId(), $splits);
			$r->setUpdatedAt(new \DateTime());
			$r = $this->mapper->update($r);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->audit->record($r->getProjectId(), 'receipt', $r->getId(), 'replace_splits', $actor, null, [
			'count' => count($splits), 'total_minor' => $r->getTotalMinor(),
		]);
		return $r;
	}

	public function delete(Receipt $r, string $actor): void
	{
		// Non-finalized Belege may be deleted (they are source data, not the
		// generated fiscal document); the audit row preserves the evidence.
		$this->audit->record($r->getProjectId(), 'receipt', $r->getId(), 'delete', $actor, [
			'vendor' => $r->getVendor(), 'total_minor' => $r->getTotalMinor(),
			'invoice_date' => $r->getInvoiceDate()?->format('Y-m-d'),
		]);
		$this->db->beginTransaction();
		try {
			$this->splitMapper->deleteByReceipt($r->getId());
			$this->mapper->delete($r);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function getOrFail(int $projectId, int $id): Receipt
	{
		$r = $this->mapper->findById($id);
		if ($r === null || $r->getProjectId() !== $projectId) {
			throw new NotFoundException();
		}
		return $r;
	}

	public function splitsOf(int $receiptId): array
	{
		return $this->splitMapper->findByReceipt($receiptId);
	}

	private function writeSplits(int $receiptId, array $splits): void
	{
		foreach ($splits as $s) {
			$row = new ReceiptSplit();
			$row->setReceiptId($receiptId);
			$row->setPositionId((int)$s['position_id']);
			$row->setApId(isset($s['ap_id']) && $s['ap_id'] !== null ? (int)$s['ap_id'] : null);
			$row->setAmountMinor((int)$s['amount_minor']);
			$row->setNote((string)($s['note'] ?? ''));
			$this->splitMapper->insert($row);
		}
	}

	private function assertPositionsInActivePlan(int $projectId, array $splits): void
	{
		$active = $this->finplan->activeFor($projectId);
		if ($active === null) {
			throw new ValidationException(['splits' => 'No active Finanzierungsplan.']);
		}
		$valid = [];
		foreach ($this->posMapper->findByPlanVer($active->getId()) as $pos) {
			$valid[$pos->getId()] = true;
		}
		foreach ($splits as $i => $s) {
			if (!isset($valid[(int)$s['position_id']])) {
				throw new ValidationException(['splits' => "Split #{$i}: position not in active plan."]);
			}
		}
	}

	/**
	 * Gehaltsnachweis linkage (MH-14): a salary-kind Beleg must name the
	 * Lohn-/Rate-Zeitraum it evidences (YYYY-MM); other kinds may carry it.
	 * @return array<string, string>
	 */
	private function validateSalaryPeriod(string $kind, ?string $salaryPeriod): array
	{
		if ($salaryPeriod !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $salaryPeriod)) {
			return ['salary_period' => 'Salary period must be YYYY-MM.'];
		}
		if ($kind === 'salary' && ($salaryPeriod === null || $salaryPeriod === '')) {
			return ['salary_period' => 'A salary receipt must name the rate period (YYYY-MM).'];
		}
		return [];
	}

	/** @return array<string, string> */
	private function validateFields(string $vendor, string $invoiceDateYmd, ?string $paymentDateYmd, string $currency, ?int $fxRateMilli, int $totalMinor, int $taxMinor, string $kind): array
	{
		$fields = [];
		if (trim($vendor) === '') {
			$fields['vendor'] = 'Vendor is required.';
		}
		if (!PeriodUtil::isValidYmd($invoiceDateYmd)) {
			$fields['invoice_date'] = 'Invalid invoice date.';
		}
		if ($paymentDateYmd !== null && !PeriodUtil::isValidYmd($paymentDateYmd)) {
			$fields['payment_date'] = 'Invalid payment date.';
		}
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			$fields['currency'] = 'Invalid currency.';
		}
		if ($currency !== 'EUR' && ($fxRateMilli === null || $fxRateMilli <= 0)) {
			$fields['fx_rate_milli'] = 'Foreign currency requires a manual EUR conversion rate.';
		}
		if ($totalMinor <= 0) {
			$fields['total_minor'] = 'Total must be positive.';
		}
		if ($taxMinor < 0 || $taxMinor > $totalMinor) {
			$fields['tax_minor'] = 'Tax must be between 0 and the total.';
		}
		return $fields;
	}
}
