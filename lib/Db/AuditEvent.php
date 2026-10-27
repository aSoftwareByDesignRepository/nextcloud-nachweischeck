<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getProjectId()
 * @method void setProjectId(int|null $v)
 * @method string getEntity()
 * @method void setEntity(string $v)
 * @method int getEntityId()
 * @method void setEntityId(int $v)
 * @method string getAction()
 * @method void setAction(string $v)
 * @method string getActor()
 * @method void setActor(string $v)
 * @method string|null getBeforeJson()
 * @method void setBeforeJson(string|null $v)
 * @method string|null getAfterJson()
 * @method void setAfterJson(string|null $v)
 * @method \DateTime getTs()
 * @method void setTs(\DateTime $v)
 */
class AuditEvent extends Entity
{
	protected ?int $projectId = null;
	protected string $entity = '';
	protected int $entityId = 0;
	protected string $action = '';
	protected string $actor = '';
	protected ?string $beforeJson = null;
	protected ?string $afterJson = null;
	protected ?\DateTime $ts = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('entity', 'string');
		$this->addType('entityId', 'integer');
		$this->addType('action', 'string');
		$this->addType('actor', 'string');
		$this->addType('beforeJson', 'string');
		$this->addType('afterJson', 'string');
		$this->addType('ts', 'datetime');
	}
}
