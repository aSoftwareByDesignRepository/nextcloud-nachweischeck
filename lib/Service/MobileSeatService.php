<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Mobile companion seats (Pro, per-seat) — the contract surface the future
 * companion app authenticates against. Seat = one NC uid holding an
 * app-level mobile slot; assigned/freed by app admins.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\MobileSeat;
use OCA\NachweisCheck\Db\MobileSeatMapper;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\DbErrors;

class MobileSeatService
{
	public function __construct(
		private readonly MobileSeatMapper $mapper,
		private readonly LicenseService $license,
		private readonly AuditService $audit,
	) {
	}

	public function hasSeat(string $uid): bool
	{
		return $this->license->hasFeature(LicenseService::FEATURE_MOBILE_SEATS)
			&& $this->mapper->findByUid($uid) !== null;
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
}
