<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Append-only audit trail on every fiscal-relevant mutation (MH-06).
 * Records actor, entity, action and before/after JSON. No service exposes
 * update/delete on this table — there is intentionally no AuditService::delete.
 *
 * PII discipline: callers pass already-shaped arrays; salary fields are only
 * reachable by finance/admin when read back (permission-filtered endpoint).
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\AuditEvent;
use OCA\NachweisCheck\Db\AuditEventMapper;

class AuditService
{
	public function __construct(
		private readonly AuditEventMapper $mapper,
	) {
	}

	public function record(
		?int $projectId,
		string $entity,
		int $entityId,
		string $action,
		string $actor,
		?array $before = null,
		?array $after = null,
	): void {
		$e = new AuditEvent();
		$e->setProjectId($projectId);
		$e->setEntity($entity);
		// AuditEvent::setEntityId marks the field unconditionally — see the
		// entity for why (entityId=0 was dropped by the magic-setter
		// unchanged-skip → SQLSTATE 1364 on INSERT).
		$e->setEntityId($entityId);
		$e->setAction($action);
		$e->setActor($actor);
		$e->setBeforeJson($before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$e->setAfterJson($after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$e->setTs(new \DateTime());
		$this->mapper->insert($e);
	}
}
