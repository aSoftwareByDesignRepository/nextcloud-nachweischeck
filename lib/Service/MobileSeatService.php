<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Mobile companion seats (Pro, per-seat) — the contract surface the future
 * companion app authenticates against. Seat = one NC uid holding an
 * app-level mobile slot; assigned/freed by app admins and capped by the
 * license's mobileSeats entitlement.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\MobileSeat;
use OCA\NachweisCheck\Db\MobileSeatMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\NwException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\DbErrors;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class MobileSeatService
{
	private const SEAT_LOCK = 'nachweischeck/seat_assign';

	public function __construct(
		private readonly MobileSeatMapper $mapper,
		private readonly LicenseService $license,
		private readonly AuditService $audit,
		private readonly ILockingProvider $locking,
	) {
	}

	public function hasSeat(string $uid): bool
	{
		return $this->license->hasFeature(LicenseService::FEATURE_MOBILE_SEATS)
			&& $this->seatWithinLimit($uid);
	}

	/**
	 * A seat entitles mobile access only while its rank fits the current
	 * license limit — downgraded licenses park surplus seats, never delete.
	 */
	public function seatWithinLimit(string $uid): bool
	{
		$seat = $this->mapper->findByUid($uid);
		if ($seat === null) {
			return false;
		}
		return SeatRank::isWithinLimit(
			self::rankInput($this->mapper->findAllRanked()),
			(int)$seat->getId(),
			$this->license->seatLimit(),
		);
	}

	public function assign(string $uid, string $actor): MobileSeat
	{
		$this->license->requireFeature(LicenseService::FEATURE_MOBILE_SEATS);
		if ($uid === '') {
			throw new ValidationException(['uid' => 'User is required.']);
		}
		$existing = $this->mapper->findByUid($uid);
		if ($existing !== null) {
			return $existing;
		}

		// Limit check + insert under one exclusive lock — two concurrent
		// assigns must not overshoot the entitlement.
		$attempts = 0;
		while (true) {
			try {
				$this->locking->acquireLock(self::SEAT_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException) {
				if (++$attempts >= 40) {
					throw new NwException('seat_busy', 'Another seat update is in progress. Try again in a moment.', 409);
				}
				usleep(25_000);
			}
		}
		try {
			$existing = $this->mapper->findByUid($uid);
			if ($existing !== null) {
				return $existing;
			}
			if ($this->mapper->countAll() >= $this->license->seatLimit()) {
				throw new NwException(
					'seat_limit_reached',
					'All licensed seats are assigned. Remove a seat or upgrade the license.',
					409,
				);
			}
			$s = new MobileSeat();
			$s->setUid($uid);
			$s->setAssignedAt(new \DateTime());
			$s->setAssignedBy($actor);
			try {
				$s = $this->mapper->insert($s);
			} catch (\Throwable $e) {
				// uid is unique — a concurrent assign won; return that seat.
				if (DbErrors::isUniqueViolation($e)) {
					$winner = $this->mapper->findByUid($uid);
					if ($winner !== null) {
						return $winner;
					}
				}
				throw $e;
			}
		} finally {
			$this->locking->releaseLock(self::SEAT_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
		$this->audit->record(null, 'mobile_seat', $s->getId(), 'assign', $actor, null, ['uid' => $uid]);
		return $s;
	}

	public function free(string $uid, string $actor): void
	{
		$seat = $this->mapper->findByUid($uid);
		if ($seat === null) {
			throw new NotFoundException();
		}
		$this->mapper->delete($seat);
		$this->audit->record(null, 'mobile_seat', $seat->getId(), 'free', $actor, ['uid' => $uid]);
	}

	/**
	 * @param list<MobileSeat> $seats
	 * @return list<array{id: int, assignedAt: int}>
	 */
	public static function rankInput(array $seats): array
	{
		return array_map(
			static fn (MobileSeat $s): array => [
				'id' => (int)$s->getId(),
				'assignedAt' => $s->getAssignedAt()?->getTimestamp() ?? 0,
			],
			$seats,
		);
	}
}
