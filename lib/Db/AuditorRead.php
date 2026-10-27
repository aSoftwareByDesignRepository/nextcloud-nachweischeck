<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getGrantId()
 * @method void setGrantId(int $v)
 * @method string getActor()
 * @method void setActor(string $v)
 * @method string|null getRoute()
 * @method void setRoute(string|null $v)
 * @method string|null getEntity()
 * @method void setEntity(string|null $v)
 * @method int|null getEntityId()
 * @method void setEntityId(int|null $v)
 * @method \DateTime getTs()
 * @method void setTs(\DateTime $v)
 */
class AuditorRead extends Entity
{
	protected int $grantId = 0;
	protected string $actor = '';
	protected ?string $route = null;
	protected ?string $entity = null;
	protected ?int $entityId = null;
	protected ?\DateTime $ts = null;

	public function __construct()
	{
		$this->addType('grantId', 'integer');
		$this->addType('actor', 'string');
		$this->addType('route', 'string');
		$this->addType('entity', 'string');
		$this->addType('entityId', 'integer');
		$this->addType('ts', 'datetime');
	}
}
