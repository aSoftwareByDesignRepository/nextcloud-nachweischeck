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
 * @method string getUid()
 * @method void setUid(string $v)
 * @method string getRole()
 * @method void setRole(string $v)
 * @method string|null getDisplayName()
 * @method void setDisplayName(string|null $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class ProjectMember extends Entity
{
	protected int $projectId = 0;
	protected string $uid = '';
	protected string $role = '';
	protected ?string $displayName = null;
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('uid', 'string');
		$this->addType('role', 'string');
		$this->addType('displayName', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
