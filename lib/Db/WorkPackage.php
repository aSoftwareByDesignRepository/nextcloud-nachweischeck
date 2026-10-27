<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getProjectId()
 * @method void setProjectId(int $v)
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method string|null getOwnerUid()
 * @method void setOwnerUid(string|null $v)
 * @method \DateTime|null getStartDate()
 * @method void setStartDate(\DateTime|null $v)
 * @method \DateTime|null getEndDate()
 * @method void setEndDate(\DateTime|null $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class WorkPackage extends Entity
{
	protected int $projectId = 0;
	protected string $code = '';
	protected string $title = '';
	protected ?string $ownerUid = null;
	protected ?\DateTime $startDate = null;
	protected ?\DateTime $endDate = null;
	protected string $state = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('code', 'string');
		$this->addType('title', 'string');
		$this->addType('ownerUid', 'string');
		$this->addType('startDate', 'datetime');
		$this->addType('endDate', 'datetime');
		$this->addType('state', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
