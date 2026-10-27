<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Serializes project-scoped mutations that span check-then-write patterns
 * the database cannot enforce alone (plan-version CAS, version counters,
 * dedupe sets, license seat limits).
 *
 * The guard is reentrant WITHIN one request: services call each other while
 * already holding the project lock (e.g. ReallocationService::approve →
 * FinplanService::newDraft/activate; ProjectService::activate →
 * DeadlineService::recompute). NC's ILockingProvider implementations are
 * NOT reentrant, so re-acquisition is tracked here by lock-key depth.
 * Services are shared instances per request, which makes the depth map
 * safe; PHP-FPM requests are single-threaded.
 */

namespace OCA\NachweisCheck\Service;

use OCP\Lock\ILockingProvider;

class ConcurrencyGuard
{
	/** @var array<string, int> lock key → hold depth (request-local) */
	private array $held = [];

	public function __construct(
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * Run $fn while holding the exclusive lock for $key. Reentrant calls on
	 * the same key skip re-acquisition and just run.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function run(string $key, callable $fn): mixed
	{
		$depth = $this->held[$key] ?? 0;
		if ($depth > 0) {
			$this->held[$key] = $depth + 1;
			try {
				return $fn();
			} finally {
				$this->held[$key]--;
			}
		}
		$this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, 'nachweischeck');
		$this->held[$key] = 1;
		try {
			return $fn();
		} finally {
			unset($this->held[$key]);
			$this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Lock key serializing every plan/fiscal mutation of one project. */
	public static function projectKey(int $projectId): string
	{
		return 'nachweischeck-project-' . $projectId;
	}

	/**
	 * Global key for the free-tier active-project limit. Activation counts
	 * must not be checked concurrently — one activation at a time instance-
	 * wide (activations are rare; the critical section is microseconds).
	 */
	public static function activationKey(): string
	{
		return 'nachweischeck-project-activation';
	}
}
