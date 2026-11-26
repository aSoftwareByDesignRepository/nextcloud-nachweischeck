<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Agent;

use OCA\NachweisCheck\Service\MobileSeatService;

/**
 * Per-request entitlement for agent requests (BQ-3).
 *
 * NachweisCheck's companion API is Pro-seat licensed: the exact same
 * MobileSeatService::hasSeat check that guards /api/v1/mobile/* applies to
 * agent callers (license feature + seat rank within limit). An agent token
 * never widens entitlement — a user who loses the seat or whose license
 * lapses loses agent access on the next call, without waiting for token
 * expiry. Every gate failure collapses to one uniform 403 — no oracle on
 * which rung failed.
 */
final class NachweisCheckAgentSeatGate implements AgentSeatGate
{
	public function __construct(
		private readonly MobileSeatService $seats,
	) {
	}

	public function assertEntitled(string $uid): void
	{
		try {
			if (!$this->seats->hasSeat($uid)) {
				throw new AgentException(
					'agent_seat_revoked',
					'This agent token has no active entitlement.',
					403,
				);
			}
		} catch (AgentException $e) {
			throw $e;
		} catch (\Throwable) {
			throw new AgentException(
				'agent_seat_revoked',
				'This agent token has no active entitlement.',
				403,
			);
		}
	}
}
