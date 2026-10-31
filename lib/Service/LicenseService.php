<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pro gate (ADR-08, I-07) backed by a real NWC2 license:
 *   1. appconfig nw_license_mode = 'pro'|'free' (dev/self-host override)
 *   2. stored NWC2 key in nw_license_state, valid while today <= validUntil
 *      (inclusive, server-local day precision) → pro
 *   3. otherwise → free tier limits
 *
 * Free tier: 1 active project, ANBest-P template only, manual Beleg entry,
 * view-only timesheets, no auditor seats, no e-invoice parse, no mobile seats.
 * Grace on expiry: the stored license stops unlocking writes; every record it
 * enabled stays readable (spec: "Grace bei Ablauf nur read-only").
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\LicenseState;
use OCA\NachweisCheck\Db\LicenseStateMapper;
use OCA\NachweisCheck\Db\MobileSeatMapper;
use OCA\NachweisCheck\Exception\LicenseException;
use OCA\NachweisCheck\Exception\NwException;
use OCA\NachweisCheck\License\Nwc2Codec;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class LicenseService
{
	private const APP_ID = 'nachweischeck';
	private const LICENSE_LOCK = 'nachweischeck/license_apply';

	public const FEATURE_ERECHNUNG = 'erechnung';
	public const FEATURE_TEMPLATES = 'templates_extra';
	public const FEATURE_MULTI_PROJECT = 'multi_project';
	public const FEATURE_TIMESHEET_SIGNOFF = 'timesheet_signoff';
	public const FEATURE_AUDITOR_SEAT = 'auditor_seat';
	public const FEATURE_MOBILE_SEATS = 'mobile_seats';

	/** Storefront the license panel links to (purchase / renew). */
	public const PURCHASE_URL = 'https://nextcloud.software-by-design.de/';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LicenseStateMapper $licenseState,
		private readonly MobileSeatMapper $seats,
		private readonly ITimeFactory $timeFactory,
		private readonly IAppConfig $appConfig,
		private readonly ILockingProvider $locking,
		private readonly AuditService $audit,
	) {
	}

	public function isPro(): bool
	{
		$mode = $this->appConfig->getValueString(self::APP_ID, 'nw_license_mode', '');
		if ($mode === 'pro') {
			return true;
		}
		if ($mode === 'free') {
			return false;
		}
		return $this->storedLicenseValid();
	}

	private function storedLicenseValid(): bool
	{
		$state = $this->licenseState->findSingleton();
		if ($state === null) {
			return false;
		}
		return Nwc2Codec::isValidOn($state->getValidUntil(), $this->today());
	}

	private function today(): string
	{
		return $this->timeFactory->getDateTime()->format('Y-m-d');
	}

	/** Named mobile-seat entitlement of the active license (0 without pro). */
	public function seatLimit(): int
	{
		if (!$this->isPro()) {
			return 0;
		}
		return $this->licenseState->findSingleton()?->getMobileSeats() ?? 0;
	}

	public function hasFeature(string $feature): bool
	{
		if ($this->isPro()) {
			return true;
		}
		return match ($feature) {
			self::FEATURE_TEMPLATES, self::FEATURE_ERECHNUNG, self::FEATURE_TIMESHEET_SIGNOFF,
			self::FEATURE_AUDITOR_SEAT, self::FEATURE_MOBILE_SEATS, self::FEATURE_MULTI_PROJECT => false,
			default => true,
		};
	}

	public function requireFeature(string $feature): void
	{
		if (!$this->hasFeature($feature)) {
			throw new LicenseException($feature);
		}
	}

	public function checkActiveProjectLimit(int $activeCount): void
	{
		if (!$this->isPro() && $activeCount >= 1) {
			throw new LicenseException(self::FEATURE_MULTI_PROJECT);
		}
	}

	// ---- license administration ------------------------------------------

	/** @return array<string, mixed> */
	public function status(): array
	{
		$state = $this->licenseState->findSingleton();
		$today = $this->today();
		$valid = $state !== null && Nwc2Codec::isValidOn($state->getValidUntil(), $today);
		$daysLeft = null;
		$expiresSoon = false;
		if ($state !== null) {
			$until = \DateTimeImmutable::createFromFormat('!Y-m-d', $state->getValidUntil());
			$todayDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $today);
			if ($until !== false && $todayDt !== false) {
				$daysLeft = (int)$todayDt->diff($until)->format('%r%a');
				$expiresSoon = $daysLeft >= 0 && $daysLeft <= 30;
			}
		}
		return [
			'tier' => $this->isPro() ? 'pro' : 'free',
			'override' => $this->appConfig->getValueString(self::APP_ID, 'nw_license_mode', ''),
			'state' => $state === null ? null : [
				'customerId' => $state->getCustomerId(),
				'issuedAt' => $state->getIssuedAt(),
				'validUntil' => $state->getValidUntil(),
				'mobileSeats' => $state->getMobileSeats(),
				'valid' => $valid,
				'expiresSoon' => $expiresSoon,
				'daysRemaining' => $daysLeft,
				'appliedAt' => $state->getAppliedAt(),
				'appliedBy' => $state->getAppliedBy(),
			],
			'seats' => [
				'assigned' => $this->seats->countAll(),
				'limit' => $state?->getMobileSeats() ?? 0,
			],
			'purchaseUrl' => self::PURCHASE_URL,
		];
	}

	/**
	 * Signed envelope for the companion bootstrap: official clients re-verify
	 * it against the embedded vendor key before showing the main UI.
	 *
	 * @return array{format: string, payloadB64: string, signatureB64: string}|null
	 */
	public function buildEnvelope(): ?array
	{
		$state = $this->licenseState->findSingleton();
		if ($state === null) {
			return null;
		}
		if (!Nwc2Codec::isValidOn($state->getValidUntil(), $this->today())) {
			return null;
		}
		return [
			'format' => Nwc2Codec::FORMAT,
			'payloadB64' => $state->getPayloadB64(),
			'signatureB64' => $state->getSignatureB64(),
		];
	}

	/** @return array<string, mixed> */
	public function apply(string $uid, string $wireKey): array
	{
		$error = Nwc2Codec::classifyError($wireKey);
		if ($error !== '') {
			$message = match ($error) {
				Nwc2Codec::ERROR_INVALID_FORMAT => 'The key does not have the expected NWC2.<payload>.<signature> shape.',
				Nwc2Codec::ERROR_INVALID_SIGNATURE => 'The signature does not match — the key was altered or not issued for this product.',
				default => 'The key payload failed validation for NachweisCheck.',
			};
			throw new NwException('license_invalid', $message, 422);
		}

		/** @var array{payload: array<string, mixed>, payloadB64: string, signatureB64: string} $verified */
		$verified = Nwc2Codec::parseAndVerify($wireKey);
		$payload = $verified['payload'];

		return $this->withExclusiveLock(
			self::LICENSE_LOCK,
			'license_busy',
			'Another license update is in progress. Try again in a moment.',
			409,
			function () use ($uid, $payload, $verified): array {
				$state = new LicenseState();
				$state->setCustomerId((string)$payload['customerId']);
				$state->setIssuedAt((string)$payload['issuedAt']);
				$state->setValidUntil((string)$payload['validUntil']);
				$state->setMobileSeats((int)$payload['mobileSeats']);
				$state->setPayloadB64($verified['payloadB64']);
				$state->setSignatureB64($verified['signatureB64']);
				$state->setAppliedAt($this->timeFactory->getTime());
				$state->setAppliedBy($uid);

				$this->db->beginTransaction();
				try {
					$this->licenseState->deleteAll();
					$this->licenseState->insert($state);
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}

				$this->audit->record(null, 'license', (int)$state->getId(), 'apply', $uid, null, [
					'customerId' => $state->getCustomerId(),
					'validUntil' => $state->getValidUntil(),
					'mobileSeats' => $state->getMobileSeats(),
				]);

				return $this->status();
			},
		);
	}

	/** @return array<string, mixed> */
	public function remove(string $uid): array
	{
		return $this->withExclusiveLock(
			self::LICENSE_LOCK,
			'license_busy',
			'Another license update is in progress. Try again in a moment.',
			409,
			function () use ($uid): array {
				$before = $this->licenseState->findSingleton();
				$this->db->beginTransaction();
				try {
					$this->seats->deleteAll();
					$this->licenseState->deleteAll();
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}
				$this->audit->record(null, 'license', (int)($before?->getId() ?? 0), 'remove', $uid,
					$before === null ? null : ['customerId' => $before->getCustomerId()], null);
				return $this->status();
			},
		);
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withExclusiveLock(
		string $key,
		string $busyCode,
		string $busyMessage,
		int $busyStatus,
		callable $fn,
	): mixed {
		$attempts = 0;
		while (true) {
			try {
				$this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				if (++$attempts >= 40) {
					throw new NwException($busyCode, $busyMessage, $busyStatus);
				}
				usleep(25_000);
			}
		}
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
