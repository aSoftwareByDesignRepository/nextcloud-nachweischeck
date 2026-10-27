<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Project-scoped role model (MH-07, GAC-6):
 *   admin   — full project control incl. grants, plan activation, exports
 *   finance — rates, receipts, exports, payments; sees salary data
 *   member  — own time entries/receipt drafts/reports; NEVER sees rates
 *   auditor — account-bound, scoped, expiring read-only via nw_auditor_grant
 *
 * Every check is server-side; controllers call require* which throw
 * NotFoundException for out-of-scope ids (no existence oracle) and
 * ForbiddenException only for known-resource role failures.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\AuditorGrantMapper;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Exception\NotFoundException;

class PermissionService
{
	public const ROLE_ADMIN = 'admin';
	public const ROLE_FINANCE = 'finance';
	public const ROLE_MEMBER = 'member';
	public const ROLE_AUDITOR = 'auditor';

	public function __construct(
		private readonly ProjectMemberMapper $memberMapper,
		private readonly AuditorGrantMapper $grantMapper,
		private readonly AccessDoorService $door,
		private readonly AuditorReadLogger $readLogger,
	) {
	}

	/** app door + app-admin shortcut */
	public function requireAppAccess(\OCP\IUser $user): void
	{
		if (!$this->door->isAllowed($user)) {
			throw new ForbiddenException('This app is restricted.');
		}
	}

	public function isAppAdmin(\OCP\IUser $user): bool
	{
		return $this->door->isAppAdmin($user);
	}

	public function roleIn(int $projectId, string $uid): ?string
	{
		$member = $this->memberMapper->findByProjectAndUid($projectId, $uid);
		return $member?->getRole();
	}

	/** Effective capability view for one uid on one project. */
	public function capabilities(int $projectId, string $uid): array
	{
		$role = $this->roleIn($projectId, $uid);
		$isAuditor = $role === null && $this->grantMapper->activeFor($projectId, $uid) !== null;
		return [
			'role' => $role ?? ($isAuditor ? self::ROLE_AUDITOR : null),
			'can_view' => $role !== null || $isAuditor,
			'can_book' => in_array($role, [self::ROLE_ADMIN, self::ROLE_FINANCE, self::ROLE_MEMBER], true),
			'can_finance' => in_array($role, [self::ROLE_ADMIN, self::ROLE_FINANCE], true),
			'can_admin' => $role === self::ROLE_ADMIN,
			'can_see_rates' => in_array($role, [self::ROLE_ADMIN, self::ROLE_FINANCE], true),
		];
	}

	public function requireView(int $projectId, string $uid): void
	{
		if ($this->roleIn($projectId, $uid) !== null) {
			return;
		}
		$grant = $this->grantMapper->activeFor($projectId, $uid);
		if ($grant !== null) {
			$this->readLogger->log($grant, 'project:view', 'project', $projectId);
			return;
		}
		throw new NotFoundException();
	}

	public function requireBook(int $projectId, string $uid): void
	{
		if (!in_array($this->roleIn($projectId, $uid), [self::ROLE_ADMIN, self::ROLE_FINANCE, self::ROLE_MEMBER], true)) {
			throw new NotFoundException();
		}
	}

	public function requireFinance(int $projectId, string $uid): void
	{
		$role = $this->roleIn($projectId, $uid);
		if ($role === null) {
			throw new NotFoundException();
		}
		if (!in_array($role, [self::ROLE_ADMIN, self::ROLE_FINANCE], true)) {
			throw new ForbiddenException('Finance role required.');
		}
	}

	public function requireAdmin(int $projectId, string $uid): void
	{
		$role = $this->roleIn($projectId, $uid);
		if ($role === null) {
			throw new NotFoundException();
		}
		if ($role !== self::ROLE_ADMIN) {
			throw new ForbiddenException('Project admin role required.');
		}
	}

	public function canSeeRates(int $projectId, string $uid): bool
	{
		return in_array($this->roleIn($projectId, $uid), [self::ROLE_ADMIN, self::ROLE_FINANCE], true);
	}
}
