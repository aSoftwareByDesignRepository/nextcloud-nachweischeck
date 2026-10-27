<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Companion-app contract surface (I-02, Pro seats). The native client is a
 * separate build; this is the stable versioned API it binds to: capability
 * handshake, own time bookings, own timesheet view. Seat-gated and scoped —
 * the companion can never see more than the web member role can.
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\ReceiptSplitMapper;
use OCA\NachweisCheck\Db\TimeEntryMapper;
use OCA\NachweisCheck\Db\TimesheetMapper;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\LicenseService;
use OCA\NachweisCheck\Service\MobileSeatService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\ReceiptService;
use OCA\NachweisCheck\Service\TimeEntryService;
use OCA\NachweisCheck\Support\PeriodUtil;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class MobileApiController extends NwApiController
{
	public const API_VERSION = 'nw-mobile/1';

	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly MobileSeatService $seats,
		private readonly TimeEntryService $entries,
		private readonly TimeEntryMapper $entryMapper,
		private readonly LicenseService $license,
		private readonly ReceiptService $receipts,
		private readonly ReceiptMapper $receiptMapper,
		private readonly ReceiptSplitMapper $splitMapper,
		private readonly TimesheetMapper $timesheetMapper,
		private readonly FinplanService $finplan,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	/** Handshake: client learns tier + seat + api version in one call. */
	#[NoAdminRequired]
	public function status(): JSONResponse
	{
		$user = $this->user();
		return $this->ok([
			'api' => self::API_VERSION,
			'seat' => $this->seats->hasSeat($user->getUID()),
			'pro' => $this->license->isPro(),
		]);
	}

	#[NoAdminRequired]
	public function myEntries(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		if (!$this->seats->hasSeat($uid)) {
			throw new \OCA\NachweisCheck\Exception\LicenseException(LicenseService::FEATURE_MOBILE_SEATS);
		}
		$this->perm->requireView($projectId, $uid);
		$in = $this->params();
		$from = (string)($in['from'] ?? '0000-01-01');
		$to = (string)($in['to'] ?? '9999-12-31');
		if (!PeriodUtil::isValidYmd($from) || !PeriodUtil::isValidYmd($to)) {
			throw new \OCA\NachweisCheck\Exception\ValidationException(['period' => 'Invalid date range.']);
		}
		$out = [];
		foreach ($this->entryMapper->findByUidPeriod($projectId, $uid, $from, $to) as $e) {
			$out[] = $this->serializeTime($e, $projectId, $uid);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function book(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		if (!$this->seats->hasSeat($uid)) {
			throw new \OCA\NachweisCheck\Exception\LicenseException(LicenseService::FEATURE_MOBILE_SEATS);
		}
		$this->perm->requireBook($projectId, $uid);
		$in = $this->params();
		$e = $this->entries->create($projectId, (int)($in['ap_id'] ?? 0), $uid,
			(string)($in['entry_date'] ?? ''), (int)($in['minutes'] ?? 0),
			(string)($in['note'] ?? ''), $in['external_ref'] ?? null, $uid);
		return $this->ok($this->serializeTime($e, $projectId, $uid), 201);
	}

	/** Active-plan positions — the split picker source for receipt booking. */
	#[NoAdminRequired]
	public function positions(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->requireSeat($uid);
		$this->perm->requireView($projectId, $uid);
		$ver = $this->finplan->activeFor($projectId);
		$out = [];
		if ($ver !== null) {
			foreach ($this->finplan->positions($ver) as $p) {
				$out[] = [
					'id' => $p->getId(), 'code' => $p->getCode(), 'title' => $p->getTitle(),
					'kind' => $p->getKind(), 'budget_minor' => $p->getBudgetMinor(),
				];
			}
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function receipts(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->requireSeat($uid);
		$this->perm->requireView($projectId, $uid);
		$in = $this->params();
		$from = (string)($in['from'] ?? '0000-01-01');
		$to = (string)($in['to'] ?? '9999-12-31');
		if (!PeriodUtil::isValidYmd($from) || !PeriodUtil::isValidYmd($to)) {
			throw new \OCA\NachweisCheck\Exception\ValidationException(['period' => 'Invalid date range.']);
		}
		$out = [];
		foreach ($this->receiptMapper->findByProjectPeriod($projectId, $from, $to) as $r) {
			$out[] = $this->serializeReceipt($r) + [
				'splits' => array_map(static fn($s) => [
					'position_id' => $s->getPositionId(), 'ap_id' => $s->getApId(),
					'amount_minor' => $s->getAmountMinor(),
				], $this->splitMapper->findByReceipt($r->getId())),
			];
		}
		return $this->ok($out);
	}

	/**
	 * Receipt booking from the companion. Splits must sum to the total —
	 * same invariant as the web API (GAC-2). external_ref makes offline
	 * queue retries idempotent.
	 */
	#[NoAdminRequired]
	public function bookReceipt(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->requireSeat($uid);
		$this->perm->requireBook($projectId, $uid);
		$in = $this->params();
		$splits = $in['splits'] ?? null;
		if (!is_array($splits)) {
			throw new \OCA\NachweisCheck\Exception\ValidationException(['splits' => 'Split list required.']);
		}
		$r = $this->receipts->create($projectId,
			(string)($in['vendor'] ?? ''), $in['invoice_no'] ?? null,
			(string)($in['invoice_date'] ?? ''), $in['payment_date'] ?? null,
			(string)($in['currency'] ?? 'EUR'), isset($in['fx_rate_milli']) ? (int)$in['fx_rate_milli'] : null,
			(int)($in['total_minor'] ?? 0), (int)($in['tax_minor'] ?? 0),
			(string)($in['purpose'] ?? ''), isset($in['file_id']) ? (int)$in['file_id'] : null,
			(string)($in['kind'] ?? 'receipt'), $splits, $in['external_ref'] ?? null, $uid,
			($in['dup_ack'] ?? false) === true);
		return $this->ok($this->serializeReceipt($r), 201);
	}

	/** Own Stundenzettel — the companion shows state, never rates. */
	#[NoAdminRequired]
	public function timesheets(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->requireSeat($uid);
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->timesheetMapper->findByProject($projectId) as $ts) {
			if ($ts->getUid() !== $uid) {
				continue;
			}
			$out[] = [
				'id' => $ts->getId(), 'ap_id' => $ts->getApId(), 'period' => $ts->getPeriod(),
				'minutes_total' => $ts->getMinutesTotal(), 'state' => $ts->getState(),
				'submitted_at' => $ts->getSubmittedAt()?->format(\DateTime::ATOM),
				'signed_at' => $ts->getSignedAt()?->format(\DateTime::ATOM),
				'reject_reason' => $ts->getRejectReason(),
			];
		}
		return $this->ok($out);
	}

	private function requireSeat(string $uid): void
	{
		if (!$this->seats->hasSeat($uid)) {
			throw new \OCA\NachweisCheck\Exception\LicenseException(LicenseService::FEATURE_MOBILE_SEATS);
		}
	}
}
