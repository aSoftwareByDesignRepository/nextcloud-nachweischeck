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
 * @method int getApId()
 * @method void setApId(int $v)
 * @method string getUid()
 * @method void setUid(string $v)
 * @method \DateTime getEntryDate()
 * @method void setEntryDate(\DateTime $v)
 * @method int getMinutes()
 * @method void setMinutes(int $v)
 * @method string|null getNote()
 * @method void setNote(string|null $v)
 * @method int|null getRateMinor()
 * @method void setRateMinor(int|null $v)
 * @method int|null getAmountMinor()
 * @method void setAmountMinor(int|null $v)
 * @method string|null getExternalRef()
 * @method void setExternalRef(string|null $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class TimeEntry extends Entity
{
	protected int $projectId = 0;
	protected int $apId = 0;
	protected string $uid = '';
	protected ?\DateTime $entryDate = null;
	protected int $minutes = 0;
	protected ?string $note = null;
	protected ?int $rateMinor = null;
	protected ?int $amountMinor = null;
	protected ?string $externalRef = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('apId', 'integer');
		$this->addType('uid', 'string');
		$this->addType('entryDate', 'datetime');
		$this->addType('minutes', 'integer');
		$this->addType('note', 'string');
		$this->addType('rateMinor', 'integer');
		$this->addType('amountMinor', 'integer');
		$this->addType('externalRef', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
