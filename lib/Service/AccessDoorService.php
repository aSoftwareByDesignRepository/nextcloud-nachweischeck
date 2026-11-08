<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Access door (design-system §Access door, family standard): the app is
 * either Open (every NC user) or Restricted to an allowlist of groups +
 * individual users. A dedicated App Admin role exists inside the door.
 *
 * Mirrored platform state (api-security rule #8): the group allowlist is
 * mirrored into Nextcloud's native app restriction ("Limit to groups")
 * via enableAppForGroups(). The PLATFORM value is the only read authority —
 * out-of-band edits (Apps page, occ app:enable -g) must win on every read;
 * the local snapshot keys exist for write-through and user/admin lists.
 *
 * Persisted in appconfig: nw_access_mode, nw_access_users (json),
 * nw_admin_uids (json). Groups live ONLY in the platform restriction
 * (IAppManager::getAppRestriction) — reading a local group snapshot would
 * freeze the first-saved list forever (azc issue #46).
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Exception\ValidationException;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

class AccessDoorService
{
	private const APP_ID = 'nachweischeck';

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly IAppManager $appManager,
		private readonly IUserManager $userManager,
	) {
	}

	public function isAllowed(?IUser $user): bool
	{
		if ($user === null) {
			return false;
		}
		if ($this->groupManager->isAdmin($user->getUID())) {
			return true;
		}
		// Fail closed on divergence: a non-empty platform restriction wins
		// even when the app flag still says Open — out-of-band edits made
		// through the Apps page must reach the door immediately.
		$groups = $this->allowedGroups();
		if ($this->mode() === 'open' && $groups === []) {
			return true;
		}
		$uid = $user->getUID();
		if (in_array($uid, $this->list('nw_access_users'), true)) {
			return true;
		}
		if (in_array($uid, $this->list('nw_admin_uids'), true)) {
			return true;
		}
		foreach ($groups as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}
		return false;
	}

	public function isAppAdmin(?IUser $user): bool
	{
		if ($user === null) {
			return false;
		}
		return $this->groupManager->isAdmin($user->getUID())
			|| in_array($user->getUID(), $this->list('nw_admin_uids'), true);
	}

	public function mode(): string
	{
		$mode = $this->appConfig->getValueString(self::APP_ID, 'nw_access_mode', 'open');
		return $mode === 'restricted' ? 'restricted' : 'open';
	}

	/**
	 * Effective allowed group ids — read from the NC platform restriction
	 * only. Non-empty platform restriction ⇒ restricted even when the app
	 * flag says Open (fail closed on divergence).
	 * @return list<string>
	 */
	public function allowedGroups(): array
	{
		$restriction = $this->appManager->getAppRestriction(self::APP_ID);
		$groups = [];
		foreach (is_array($restriction) ? $restriction : [] as $gid) {
			$gid = trim((string)$gid);
			if ($gid !== '') {
				$groups[$gid] = true;
			}
		}
		return array_keys($groups);
	}

	/** @return array{mode:string, groups:list<string>, users:list<string>, admins:list<string>} */
	public function config(): array
	{
		return [
			'mode' => $this->mode(),
			'groups' => $this->allowedGroups(),
			'users' => $this->list('nw_access_users'),
			'admins' => $this->list('nw_admin_uids'),
		];
	}

	public function saveConfig(string $mode, array $groups, array $users, array $admins): void
	{
		$mode = $mode === 'restricted' ? 'restricted' : 'open';
		// uid write-validation BEFORE any state changes: a stored allowlist
		// must never hold ghosts. Nonexistent and deactivated accounts
		// collapse into the same code — no existence oracle.
		$this->assertLiveUids($users, 'users');
		$this->assertLiveUids($admins, 'admins');
		$this->appConfig->setValueString(self::APP_ID, 'nw_access_mode', $mode);
		$this->appConfig->setValueString(self::APP_ID, 'nw_access_users', json_encode(array_values($users)));
		$this->appConfig->setValueString(self::APP_ID, 'nw_admin_uids', json_encode(array_values($admins)));
		// Write-through: mirror the group allowlist into the platform
		// restriction so the Apps page and this door can never diverge.
		$cleanGroups = array_values(array_unique(array_filter(array_map('strval', $groups), static fn(string $g): bool => $g !== '')));
		if ($mode === 'restricted' && $cleanGroups !== []) {
			$groupObjs = [];
			foreach ($cleanGroups as $gid) {
				$g = $this->groupManager->get($gid);
				if ($g !== null) {
					$groupObjs[] = $g;
				}
			}
			$this->appManager->enableAppForGroups(self::APP_ID, $groupObjs);
		} else {
			// Open or no groups ⇒ clear the platform restriction.
			$this->appManager->enableApp(self::APP_ID);
		}
	}

	/** @param array<int|string, mixed> $uids */
	private function assertLiveUids(array $uids, string $field): void
	{
		foreach ($uids as $uid) {
			$u = $this->userManager->get((string)$uid);
			if ($u === null || !$u->isEnabled()) {
				throw new ValidationException([$field => 'Unknown user.']);
			}
		}
	}

	/** @return list<string> */
	private function list(string $key): array
	{
		$raw = $this->appConfig->getValueString(self::APP_ID, $key, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_filter(array_map('strval', $decoded), static fn(string $v): bool => $v !== ''));
	}
}
