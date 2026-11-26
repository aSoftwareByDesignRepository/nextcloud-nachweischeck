<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Agent;

use OCA\NachweisCheck\Service\AccessDoorService;
use OCP\IUserManager;

/**
 * App-door adapter: the agent surface honours the exact same access policy
 * as the web UI. canUseApp = the access door (mode + allow lists); isAdmin
 * = the dedicated NachweisCheck app-admin role (isAppAdmin already covers
 * NC server admins) — matching who may reach the settings page that hosts
 * the "AI access (MCP)" section. AccessDoorService is IUser-shaped; the
 * user manager resolves the uid to the account (missing user → closed).
 */
final class NachweisCheckAgentAccessGate implements AgentAccessGate
{
	public function __construct(
		private readonly AccessDoorService $door,
		private readonly IUserManager $userManager,
	) {
	}

	public function canUseApp(string $uid): bool
	{
		$user = $this->userManager->get($uid);
		return $user !== null && ($this->door->isAllowed($user) || $this->door->isAppAdmin($user));
	}

	public function canAdminister(string $uid): bool
	{
		$user = $this->userManager->get($uid);
		return $user !== null && $this->door->isAppAdmin($user);
	}
}
