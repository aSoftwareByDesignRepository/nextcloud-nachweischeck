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
 * @method int getFromYear()
 * @method void setFromYear(int $v)
 * @method int getToYear()
 * @method void setToYear(int $v)
 * @method int getAmountMinor()
 * @method void setAmountMinor(int $v)
 * @method string|null getDocRef()
 * @method void setDocRef(string|null $v)
 * @method string|null getDecidedBy()
 * @method void setDecidedBy(string|null $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Carryover extends Entity
{
	protected int $projectId = 0;
	protected int $fromYear = 0;
	protected int $toYear = 0;
	protected int $amountMinor = 0;
	protected ?string $docRef = null;
	protected ?string $decidedBy = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('fromYear', 'integer');
		$this->addType('toYear', 'integer');
		$this->addType('amountMinor', 'integer');
		$this->addType('docRef', 'string');
		$this->addType('decidedBy', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
