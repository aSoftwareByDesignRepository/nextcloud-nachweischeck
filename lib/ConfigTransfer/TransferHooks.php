<?php

declare(strict_types=1);

/**
 * NachweisCheck wiring for the shared transfer engine.
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (AccessDoorService::isAppAdmin — system admins + configured app
 *    admins).
 *  - validateValue/normalizeValue mirror the exact transforms
 *    AccessDoorService::saveConfig applies at write time (validator
 *    parity, NN-08): strict mode discriminator (the settings API rejects
 *    unknown modes — a typo must not silently collapse to 'open') and
 *    compact JSON id lists.
 *  - writeMirrored runs the whole door policy through
 *    AccessDoorService::saveConfig in ONE atomic call — never raw
 *    setValueString (NN-02). saveConfig also mirrors the group allowlist
 *    into the NC platform restriction via enableAppForGroups — this app
 *    HAS a real platform mirror (api-security §8, azc #46 class).
 *  - createSafetySnapshot delegates to UpgradeBackupService for the
 *    mandatory pre-replace snapshot (FS-2).
 */

namespace OCA\NachweisCheck\ConfigTransfer;

use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\UpgradeBackupService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	private const DOOR_KEYS = [
		'nw_access_mode',
		'nw_access_users',
		'nw_access_groups',
		'nw_admin_uids',
	];

	public function __construct(
		private readonly AccessDoorService $door,
		private readonly UpgradeBackupService $backups,
		private readonly AuditService $audit,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		$user = $uid === '' ? null : $this->userManager->get($uid);
		if ($user === null || !$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	private function normalize(string $key, string $value): string
	{
		return match ($key) {
			// saveConfig coerces to 'restricted'|'open'; the settings API
			// validates the discriminator strictly BEFORE saveConfig —
			// parity means rejecting here, not coercing.
			'nw_access_mode' => in_array($value, ['open', 'restricted'], true)
				? $value
				: throw new \InvalidArgumentException('INVALID_ACCESS_MODE'),
			// Stored form is json_encode(array_values($list)) — compact,
			// no whitespace; the read path filters to non-empty strings.
			'nw_access_users', 'nw_admin_uids' => $this->normalizeIdList($value),
			// The platform mirror (enableAppForGroups) dedupes + drops
			// empties — normalize to exactly what saveConfig writes through.
			'nw_access_groups' => $this->normalizeGidList($value),
			default => strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG'),
		};
	}

	private function normalizeIdList(string $value): string
	{
		$list = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$clean = [];
		foreach ($list as $id) {
			if (is_string($id) && $id !== '') {
				$clean[] = $id;
			}
		}
		return json_encode(array_values($clean), JSON_THROW_ON_ERROR);
	}

	private function normalizeGidList(string $value): string
	{
		$list = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$clean = [];
		foreach ($list as $gid) {
			if (is_string($gid) && $gid !== '') {
				$clean[] = $gid;
			}
		}
		return json_encode(array_values(array_unique($clean)), JSON_THROW_ON_ERROR);
	}

	public function validateMirrored(array $keyValues): ?string
	{
		$decoded = $this->decodePolicy($keyValues);
		if ($decoded === null) {
			return 'INVALID_POLICY_JSON';
		}
		[$mode, $groups, $users, $admins] = $decoded;
		// saveConfig's assertLiveUids parity: a stored allowlist must never
		// hold ghosts — nonexistent and disabled accounts collapse into the
		// same code (no existence oracle).
		foreach (array_merge($users, $admins) as $uid) {
			$u = $this->userManager->get($uid);
			if ($u === null || !$u->isEnabled()) {
				return 'INVALID_DOOR_USER';
			}
		}
		foreach ($groups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				return 'INVALID_DOOR_GROUP';
			}
		}
		return null;
	}

	public function writeMirrored(array $keyValues): void
	{
		$decoded = $this->decodePolicy($keyValues);
		if ($decoded === null) {
			throw new \InvalidArgumentException('INVALID_POLICY_JSON');
		}
		[$mode, $groups, $users, $admins] = $decoded;
		// One atomic policy write through the app's central writer — the
		// platform group mirror (enableAppForGroups / enableApp) happens
		// inside saveConfig. decodePolicy overlays the transferred subset
		// onto the current policy, so a file that carries only a subset
		// never blanks the rest (and preserves the live platform groups
		// when the file has no group leg).
		$this->door->saveConfig($mode, $groups, $users, $admins);
	}

	/**
	 * Overlay the transferred mirrored keys onto the current effective
	 * policy: mode + user/admin lists from appconfig, groups from the
	 * platform restriction (the only read authority — §8).
	 *
	 * @return array{0:string,1:list<string>,2:list<string>,3:list<string>}|null
	 */
	private function decodePolicy(array $kv): ?array
	{
		$current = $this->door->config();
		$mode = $current['mode'];
		$groups = $current['groups'];
		$users = $current['users'];
		$admins = $current['admins'];
		if (isset($kv['nw_access_mode'])) {
			$mode = $kv['nw_access_mode'];
		}
		if (isset($kv['nw_access_groups'])) {
			$groups = json_decode($kv['nw_access_groups'], true);
			if (!is_array($groups)) {
				return null;
			}
		}
		if (isset($kv['nw_access_users'])) {
			$users = json_decode($kv['nw_access_users'], true);
			if (!is_array($users)) {
				return null;
			}
		}
		if (isset($kv['nw_admin_uids'])) {
			$admins = json_decode($kv['nw_admin_uids'], true);
			if (!is_array($admins)) {
				return null;
			}
		}
		return [
			(string)$mode,
			array_values(array_map('strval', $groups)),
			array_values(array_map('strval', $users)),
			array_values(array_map('strval', $admins)),
		];
	}

	public function securityRelevantKeys(): array
	{
		return self::DOOR_KEYS;
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			// No data to back up yet (fresh instance) — not an error, but no
			// snapshot id either; the apply must not be silently unprotected
			// when tables DO exist, so rethrow real failures on non-empty DBs.
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match (true) {
			str_starts_with($key, 'nw_access_'), $key === 'nw_admin_uids' => 'access',
			str_starts_with($key, 'nw_license_') => 'license',
			default => null,
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		try {
			$this->audit->record(
				null,
				'config_transfer',
				0,
				'config_transfer_' . $action,
				$actor === '' ? 'cli' : $actor,
				null,
				$details,
			);
		} catch (\Throwable $e) {
			// audit must never fail the transfer itself
			$this->logger->warning('nachweischeck: config transfer audit event failed', [
				'app' => 'nachweischeck',
				'action' => $action,
				'exception' => $e,
			]);
		}
	}
}
