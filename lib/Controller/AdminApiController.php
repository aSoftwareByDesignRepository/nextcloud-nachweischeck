<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Administrative surfaces: project members+roles, auditor grants,
 * template packs, the app access door, mobile seats.
 */

namespace OCA\NachweisCheck\Controller;

use OCA\NachweisCheck\Db\AuditorGrantMapper;
use OCA\NachweisCheck\Db\AuditorReadMapper;
use OCA\NachweisCheck\Db\ProjectMember;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Exception\ForbiddenException;
use OCA\NachweisCheck\Exception\NotFoundException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AccessDoorService;
use OCA\NachweisCheck\Service\AuditorService;
use OCA\NachweisCheck\Service\LicenseService;
use OCA\NachweisCheck\Service\MobileSeatService;
use OCA\NachweisCheck\Service\PermissionService;
use OCA\NachweisCheck\Service\ProjectService;
use OCA\NachweisCheck\Service\AuditService;
use OCA\NachweisCheck\Service\TemplateService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

class AdminApiController extends NwApiController
{
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		AccessDoorService $door, PermissionService $perm,
		private readonly ProjectMemberMapper $memberMapper,
		private readonly ProjectService $projects,
		private readonly AuditorService $auditors,
		private readonly AuditorGrantMapper $grantMapper,
		private readonly AuditorReadMapper $readMapper,
		private readonly TemplateService $templates,
		private readonly LicenseService $license,
		private readonly MobileSeatService $seats,
		private readonly \OCA\NachweisCheck\Db\MobileSeatMapper $seatMapper,
		private readonly AuditService $audit,
		private readonly IUserManager $userManager,
		private readonly \OCP\IGroupManager $groupManager,
		private readonly \OCP\IConfig $config,
	) {
		parent::__construct($appName, $request, $userSession, $door, $perm);
	}

	// ---- members ---------------------------------------------------------

	#[NoAdminRequired]
	public function members(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireView($projectId, $uid);
		$out = [];
		foreach ($this->memberMapper->findByProject($projectId) as $m) {
			$out[] = [
				'uid' => $m->getUid(), 'role' => $m->getRole(), 'display_name' => $m->getDisplayName(),
			];
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function addMember(int $projectId): JSONResponse
	{
		$actor = $this->uid();
		$this->perm->requireAdmin($projectId, $actor);
		$in = $this->params();
		$uid = trim((string)($in['uid'] ?? ''));
		$role = (string)($in['role'] ?? 'member');
		if (!in_array($role, [PermissionService::ROLE_ADMIN, PermissionService::ROLE_FINANCE, PermissionService::ROLE_MEMBER], true)) {
			throw new ValidationException(['role' => 'Invalid role.']);
		}
		$target = $this->userManager->get($uid);
		if ($target === null) {
			// uid existence gate: stale member rows must never accept ghosts
			throw new ValidationException(['uid' => 'Unknown user.']);
		}
		if ($this->memberMapper->findByProjectAndUid($projectId, $uid) !== null) {
			throw new ValidationException(['uid' => 'Already a member.']);
		}
		$m = new ProjectMember();
		$m->setProjectId($projectId);
		$m->setUid($uid);
		$m->setRole($role);
		$m->setDisplayName($target->getDisplayName());
		$m->setCreatedAt(new \DateTime());
		$m = $this->memberMapper->insert($m);
		$this->audit->record($projectId, 'project_member', $m->getId(), 'add', $actor, null, [
			'uid' => $uid, 'role' => $role,
		]);
		return $this->ok(['uid' => $uid, 'role' => $role], 201);
	}

	#[NoAdminRequired]
	public function updateMemberRole(int $projectId, string $uid): JSONResponse
	{
		$actor = $this->uid();
		$this->perm->requireAdmin($projectId, $actor);
		$in = $this->params();
		$role = (string)($in['role'] ?? '');
		if (!in_array($role, [PermissionService::ROLE_ADMIN, PermissionService::ROLE_FINANCE, PermissionService::ROLE_MEMBER], true)) {
			throw new ValidationException(['role' => 'Invalid role.']);
		}
		$m = $this->memberMapper->findByProjectAndUid($projectId, $uid);
		if ($m === null) {
			throw new NotFoundException();
		}
		$before = $m->getRole();
		$m->setRole($role);
		$this->memberMapper->update($m);
		$this->audit->record($projectId, 'project_member', $m->getId(), 'set_role', $actor, ['role' => $before], ['role' => $role]);
		return $this->ok(['uid' => $uid, 'role' => $role]);
	}

	#[NoAdminRequired]
	public function removeMember(int $projectId, string $uid): JSONResponse
	{
		$actor = $this->uid();
		$this->perm->requireAdmin($projectId, $actor);
		$m = $this->memberMapper->findByProjectAndUid($projectId, $uid);
		if ($m === null) {
			throw new NotFoundException();
		}
		if ($uid === $actor && $m->getRole() === PermissionService::ROLE_ADMIN) {
			throw new ValidationException(['uid' => 'You cannot remove your own admin role.']);
		}
		$this->memberMapper->delete($m);
		$this->audit->record($projectId, 'project_member', $m->getId(), 'remove', $actor, ['uid' => $uid, 'role' => $m->getRole()]);
		return $this->ok(['removed' => true]);
	}

	// ---- auditor grants ---------------------------------------------------

	#[NoAdminRequired]
	public function grants(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$out = [];
		foreach ($this->grantMapper->findByProject($projectId) as $g) {
			$out[] = $this->serializeGrant($g);
		}
		return $this->ok($out);
	}

	#[NoAdminRequired]
	public function createGrant(int $projectId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid);
		$this->license->requireFeature(LicenseService::FEATURE_AUDITOR_SEAT);
		$in = $this->params();
		$ncUid = trim((string)($in['nc_uid'] ?? ''));
		if ($ncUid !== '' && $this->userManager->get($ncUid) === null) {
			throw new ValidationException(['nc_uid' => 'Unknown user.']);
		}
		$scope = null;
		if (is_array($in['scope'] ?? null) && $in['scope'] !== []) {
			$allowed = ['soll_ist', 'belege', 'nachweise'];
			$scope = array_values(array_intersect($in['scope'], $allowed));
			if ($scope === []) {
				throw new ValidationException(['scope' => 'Unknown scope sections.']);
			}
		}
		$g = $this->auditors->grant($projectId, $ncUid, (string)($in['name'] ?? ''), (string)($in['firm'] ?? ''),
			(string)($in['valid_from'] ?? ''), (string)($in['valid_to'] ?? ''), $scope, $uid);
		return $this->ok($this->serializeGrant($g), 201);
	}

	#[NoAdminRequired]
	public function revokeGrant(int $projectId, int $grantId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireAdmin($projectId, $uid);
		$g = $this->auditors->revoke($this->auditors->getOrFail($projectId, $grantId), $uid);
		return $this->ok($this->serializeGrant($g));
	}

	/** Read-log of a grant — finance/admin see who looked at what. */
	#[NoAdminRequired]
	public function grantReads(int $projectId, int $grantId): JSONResponse
	{
		$uid = $this->uid();
		$this->perm->requireFinance($projectId, $uid);
		$g = $this->auditors->getOrFail($projectId, $grantId);
		$out = [];
		foreach ($this->readMapper->findByGrant($g->getId()) as $r) {
			$out[] = [
				'route' => $r->getRoute(), 'entity' => $r->getEntity(), 'entity_id' => $r->getEntityId(),
				'ts' => $r->getTs()?->format(\DateTime::ATOM),
			];
		}
		return $this->ok($out);
	}

	// ---- templates ---------------------------------------------------------

	#[NoAdminRequired]
	public function templates(): JSONResponse
	{
		$this->uid();
		return $this->ok($this->templates->listTemplates($this->license->isPro()));
	}

	// ---- access door --------------------------------------------------------

	#[NoAdminRequired]
	public function accessConfig(): JSONResponse
	{
		$user = $this->user();
		if (!$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
		return $this->ok($this->door->config());
	}

	#[NoAdminRequired]
	public function saveAccessConfig(): JSONResponse
	{
		$user = $this->user();
		if (!$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
		$in = $this->params();
		$this->door->saveConfig(
			(string)($in['mode'] ?? 'open'),
			is_array($in['groups'] ?? null) ? $in['groups'] : [],
			is_array($in['users'] ?? null) ? $in['users'] : [],
			is_array($in['admins'] ?? null) ? $in['admins'] : [],
		);
		$this->audit->record(null, 'access_door', 0, 'save', $user->getUID(), null, ['mode' => $in['mode'] ?? 'open']);
		return $this->ok($this->door->config());
	}

	// ---- mobile seats --------------------------------------------------------

	#[NoAdminRequired]
	public function assignSeat(): JSONResponse
	{
		$user = $this->user();
		if (!$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
		$in = $this->params();
		$s = $this->seats->assign((string)($in['uid'] ?? ''), $user->getUID());
		return $this->ok(['id' => $s->getId(), 'uid' => $s->getUid()], 201);
	}

	#[NoAdminRequired]
	public function freeSeat(string $uid): JSONResponse
	{
		$user = $this->user();
		if (!$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
		$this->seats->free($uid, $user->getUID());
		return $this->ok(['freed' => true]);
	}

	// ---- per-user notification opt-out (I-10) --------------------------------

	#[NoAdminRequired]
	public function notifyPref(): JSONResponse
	{
		$uid = $this->uid();
		$v = $this->config->getUserValue($uid, 'nachweischeck', 'notify_deadlines', '1');
		return $this->ok(['notify_deadlines' => $v === '1']);
	}

	#[NoAdminRequired]
	public function saveNotifyPref(): JSONResponse
	{
		$uid = $this->uid();
		$in = $this->params();
		$on = ($in['notify_deadlines'] ?? '1') === '1' || ($in['notify_deadlines'] ?? null) === true;
		$this->config->setUserValue($uid, 'nachweischeck', 'notify_deadlines', $on ? '1' : '0');
		return $this->ok(['notify_deadlines' => $on]);
	}

	// ---- template packs (Pro) -------------------------------------------------

	#[NoAdminRequired]
	public function installTemplate(): JSONResponse
	{
		$user = $this->user();
		if (!$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
		$this->license->requireFeature(LicenseService::FEATURE_TEMPLATES);
		$in = $this->params();
		$pack = $in['pack'] ?? null;
		if (!is_array($pack)) {
			throw new ValidationException(['pack' => 'Pack definition required.']);
		}
		$t = $this->templates->installPack(
			(string)($pack['key'] ?? ''), (string)($pack['name'] ?? ''),
			is_array($pack['rule_flags'] ?? null) ? $pack['rule_flags'] : [],
			is_array($pack['skeleton'] ?? null) ? $pack['skeleton'] : [],
			$user->getUID(),
		);
		return $this->ok(['key' => $t->getTplKey(), 'name' => $t->getName()], 201);
	}

	// ---- directory search (pickers, never raw uid typing) -----------------

	#[NoAdminRequired]
	public function seats(): JSONResponse
	{
		$user = $this->user();
		if (!$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
		$out = [];
		foreach ($this->seatMapper->findAll() as $s) {
			$out[] = ['id' => $s->getId(), 'uid' => $s->getUid(),
				'assigned_at' => $s->getAssignedAt()?->format(\DateTime::ATOM)];
		}
		return $this->ok($out);
	}

	/** User directory search — app-admin only, feed for member/grant/seat pickers. */
	#[NoAdminRequired]
	public function directoryUsers(): JSONResponse
	{
		$user = $this->user();
		if (!$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
		$in = $this->params();
		$q = trim((string)($in['q'] ?? ''));
		$out = [];
		if (strlen($q) >= 2) {
			foreach ($this->userManager->search($q, 8) as $u) {
				$out[] = ['id' => $u->getUID(), 'label' => $u->getDisplayName() . ' (' . $u->getUID() . ')'];
			}
		}
		return $this->ok($out);
	}

	/** Group directory search — app-admin only, feed for the access-door picker. */
	#[NoAdminRequired]
	public function directoryGroups(): JSONResponse
	{
		$user = $this->user();
		if (!$this->door->isAppAdmin($user)) {
			throw new ForbiddenException('App admin required.');
		}
		$in = $this->params();
		$q = trim((string)($in['q'] ?? ''));
		$out = [];
		foreach ($this->groupManager->search($q, 8) as $g) {
			$out[] = ['id' => $g->getGID(), 'label' => $g->getDisplayName()];
		}
		return $this->ok($out);
	}

	private function serializeGrant($g): array
	{
		return [
			'id' => $g->getId(), 'nc_uid' => $g->getNcUid(), 'name' => $g->getName(),
			'firm' => $g->getFirm(), 'valid_from' => $g->getValidFrom()?->format('Y-m-d'),
			'valid_to' => $g->getValidTo()?->format('Y-m-d'),
			'scope' => array_values(array_filter(array_map('strval', json_decode((string)($g->getScopeJson() ?? ''), true) ?: []))),
			'revoked' => $g->getRevokedAt() !== null,
			'expired' => $g->getValidTo() !== null && $g->getValidTo()->format('Y-m-d') < (new \DateTimeImmutable('today'))->format('Y-m-d'),
		];
	}
}
