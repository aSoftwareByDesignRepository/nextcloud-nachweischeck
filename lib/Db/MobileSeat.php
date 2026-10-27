<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUid()
 * @method void setUid(string $v)
 * @method \DateTime getAssignedAt()
 * @method void setAssignedAt(\DateTime $v)
 * @method string getAssignedBy()
 * @method void setAssignedBy(string $v)
 */
class MobileSeat extends Entity
{
	protected string $uid = '';
	protected ?\DateTime $assignedAt = null;
	protected string $assignedBy = '';

	public function __construct()
	{
		$this->addType('uid', 'string');
		$this->addType('assignedAt', 'datetime');
		$this->addType('assignedBy', 'string');
	}
}
