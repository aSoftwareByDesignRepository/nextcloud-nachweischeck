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
 * @method string|null getRoleKey()
 * @method void setRoleKey(string|null $v)
 * @method int getRateMinor()
 * @method void setRateMinor(int $v)
 * @method \DateTime getValidFrom()
 * @method void setValidFrom(\DateTime $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class RateHistory extends Entity
{
	protected int $projectId = 0;
	protected string $uid = '';
	protected ?string $roleKey = null;
	protected int $rateMinor = 0;
	protected ?\DateTime $validFrom = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('uid', 'string');
		$this->addType('roleKey', 'string');
		$this->addType('rateMinor', 'integer');
		$this->addType('validFrom', 'datetime');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
