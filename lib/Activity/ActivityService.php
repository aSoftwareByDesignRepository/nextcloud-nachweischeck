<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Activity-Events (I-10): fiscal mutations that matter to the whole team are
 * published to the NC Activity feed — from AuditService::record, the single
 * choke point every fiscal write already passes through. Nothing here leaks
 * PII: subjects carry project name + structural facts only — never rates,
 * reasons, amounts of salary kind, or user-supplied free text.
 *
 * Emission failures must never break a fiscal write: publish is wrapped and
 * logged, not rethrown.
 */

namespace OCA\NachweisCheck\Activity;

use OCA\NachweisCheck\Db\ProjectMapper;
use OCA\NachweisCheck\Db\ProjectMemberMapper;
use OCA\NachweisCheck\Service\PermissionService;
use OCP\Activity\IManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class ActivityService
{
	private const APP_ID = 'nachweischeck';
	private const EVENT_TYPE = 'nw_fiscal';

	/**
	 * Audited action → [activity subject, audience roles|null, param builder].
	 * null audience = every project member; a role list = finance-only facts
	 * (deviation reasons, auditor names, reallocation decisions — see NN-05
	 * and §87 BetrVG: what members may not read, they may not be notified of).
	 */
	private const ACTIONS = [
		'project/activate'        => ['project_activated',    null,                                        'projectOnly'],
		'finplan_version/activate'=> ['finplan_activated',    null,                                        'finplan'],
		'nachweis/generate'       => ['nachweis_generated',   null,                                        'nachweis'],
		'deviation/create'        => ['deviation_created',    [PermissionService::ROLE_ADMIN, PermissionService::ROLE_FINANCE], 'deviation'],
		'auditor_grant/grant'     => ['auditor_granted',      [PermissionService::ROLE_ADMIN, PermissionService::ROLE_FINANCE], 'auditor'],
		'auditor_grant/revoke'    => ['auditor_revoked',      [PermissionService::ROLE_ADMIN, PermissionService::ROLE_FINANCE], 'auditor'],
		'reallocation/approve'    => ['reallocation_decided', [PermissionService::ROLE_ADMIN, PermissionService::ROLE_FINANCE], 'reallocation'],
		'reallocation/reject'     => ['reallocation_decided', [PermissionService::ROLE_ADMIN, PermissionService::ROLE_FINANCE], 'reallocation'],
		'reallocation/request'    => ['reallocation_requested',[PermissionService::ROLE_ADMIN, PermissionService::ROLE_FINANCE], 'reallocation'],
		'carryover/create'        => ['carryover_created',    [PermissionService::ROLE_ADMIN, PermissionService::ROLE_FINANCE], 'projectOnly'],
		'project/state:closing'   => ['project_state',        null,                                        'projectState'],
		'project/state:closed'    => ['project_state',        null,                                        'projectState'],
	];

	public function __construct(
		private readonly IManager $activity,
		private readonly ProjectMemberMapper $members,
		private readonly ProjectMapper $projects,
		private readonly IURLGenerator $url,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Called from AuditService::record for whitelisted entity/action pairs.
	 * projectId null (app-level events like access-door changes) → no feed.
	 */
	public function onAuditEvent(?int $projectId, string $entity, int $entityId, string $action, string $actor, ?array $after): void
	{
		if ($projectId === null) {
			return;
		}
		$spec = self::ACTIONS[$entity . '/' . $action] ?? null;
		if ($spec === null) {
			return;
		}
		[$subject, $roles, $paramBuilder] = $spec;
		$projectName = $this->projects->findById($projectId)?->getName() ?? ('#' . $projectId);
		$params = $this->{$paramBuilder}($projectName, $after ?? [], $action);
		try {
			foreach ($this->members->findByProject($projectId) as $m) {
				if ($roles !== null && !in_array($m->getRole(), $roles, true)) {
					continue;
				}
				$this->emit($m->getUid(), $actor, $subject, $params, $entity, $entityId, $projectId);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('NachweisCheck activity publish failed', [
				'app' => self::APP_ID, 'entity' => $entity, 'entity_id' => $entityId,
				'exception' => $e,
			]);
		}
	}

	private function emit(string $uid, string $actor, string $subject, array $params, string $entity, int $entityId, int $projectId): void
	{
		$event = $this->activity->generateEvent();
		$event->setApp(self::APP_ID)
			->setType(self::EVENT_TYPE)
			->setAffectedUser($uid)
			->setAuthor($actor)
			->setSubject($subject, $params)
			->setObject($entity, $entityId)
			->setLink($this->url->linkToRouteAbsolute(self::APP_ID . '.page.index') . '?project=' . $projectId);
		$this->activity->publish($event);
	}

	// ---- subject parameter builders (whitelisted, PII-free) -----------------

	private function projectOnly(string $project, array $after, string $action): array
	{
		return [$project];
	}

	private function projectState(string $project, array $after, string $action): array
	{
		return [$project, (string)($after['state'] ?? '')];
	}

	private function finplan(string $project, array $after, string $action): array
	{
		return [$project, (string)($after['version'] ?? '')];
	}

	private function nachweis(string $project, array $after, string $action): array
	{
		return [$project, (string)($after['kind'] ?? ''), (string)($after['version'] ?? '')];
	}

	private function deviation(string $project, array $after, string $action): array
	{
		// scope + kind only — never the reason text.
		return [$project, (string)($after['scope'] ?? ''), (string)($after['kind'] ?? '')];
	}

	private function auditor(string $project, array $after, string $action): array
	{
		return [$project, (string)($after['nc_uid'] ?? '')];
	}

	private function reallocation(string $project, array $after, string $action): array
	{
		return [$project, $action];
	}
}
