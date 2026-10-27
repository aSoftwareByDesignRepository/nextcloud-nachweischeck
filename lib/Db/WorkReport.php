<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getApId()
 * @method void setApId(int $v)
 * @method string getPeriod()
 * @method void setPeriod(string $v)
 * @method string|null getSummary()
 * @method void setSummary(string|null $v)
 * @method string|null getOutcome()
 * @method void setOutcome(string|null $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method string getAuthorUid()
 * @method void setAuthorUid(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class WorkReport extends Entity
{
	protected int $apId = 0;
	protected string $period = '';
	protected ?string $summary = null;
	protected ?string $outcome = null;
	protected string $state = '';
	protected string $authorUid = '';
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct()
	{
		$this->addType('apId', 'integer');
		$this->addType('period', 'string');
		$this->addType('summary', 'string');
		$this->addType('outcome', 'string');
		$this->addType('state', 'string');
		$this->addType('authorUid', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
