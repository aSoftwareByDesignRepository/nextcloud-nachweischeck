<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Every auditor read is logged (US-NW-06 AC-1). Sequenced writes are cheap
 * enough to be synchronous — the audit property matters more than the
 * millisecond.
 */

namespace OCA\NachweisCheck\Service;

use OCA\NachweisCheck\Db\AuditorGrant;
use OCA\NachweisCheck\Db\AuditorRead;
use OCA\NachweisCheck\Db\AuditorReadMapper;

class AuditorReadLogger
{
	public function __construct(
		private readonly AuditorReadMapper $mapper,
	) {
	}

	public function log(AuditorGrant $grant, string $route, string $entity = '', ?int $entityId = null): void
	{
		$r = new AuditorRead();
		$r->setGrantId($grant->getId());
		$r->setActor($grant->getNcUid());
		$r->setRoute($route);
		$r->setEntity($entity);
		$r->setEntityId($entityId);
		$r->setTs(new \DateTime());
		$this->mapper->insert($r);
	}
}
