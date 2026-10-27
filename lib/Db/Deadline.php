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
 * @method string getKind()
 * @method void setKind(string $v)
 * @method \DateTime getDueDate()
 * @method void setDueDate(\DateTime $v)
 * @method string getDedupeKey()
 * @method void setDedupeKey(string $v)
 * @method \DateTime|null getNotifiedAt()
 * @method void setNotifiedAt(\DateTime|null $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Deadline extends Entity
{
	protected int $projectId = 0;
	protected string $kind = '';
	protected ?\DateTime $dueDate = null;
	protected string $dedupeKey = '';
	protected ?\DateTime $notifiedAt = null;
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('kind', 'string');
		$this->addType('dueDate', 'datetime');
		$this->addType('dedupeKey', 'string');
		$this->addType('notifiedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
	}
}
