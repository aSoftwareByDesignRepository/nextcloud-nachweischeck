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
 * @method int getVersion()
 * @method void setVersion(int $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method int|null getBaseVersion()
 * @method void setBaseVersion(int|null $v)
 * @method string|null getNote()
 * @method void setNote(string|null $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime|null getActivatedAt()
 * @method void setActivatedAt(\DateTime|null $v)
 */
class FinplanVersion extends Entity
{
	protected int $projectId = 0;
	protected int $version = 0;
	protected string $state = '';
	protected ?int $baseVersion = null;
	protected ?string $note = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $activatedAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('version', 'integer');
		$this->addType('state', 'string');
		$this->addType('baseVersion', 'integer');
		$this->addType('note', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('activatedAt', 'datetime');
	}
}
