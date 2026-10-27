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
 * @method int getPlanVerId()
 * @method void setPlanVerId(int $v)
 * @method int getFromPositionId()
 * @method void setFromPositionId(int $v)
 * @method int getToPositionId()
 * @method void setToPositionId(int $v)
 * @method int getAmountMinor()
 * @method void setAmountMinor(int $v)
 * @method string getReasonText()
 * @method void setReasonText(string $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method string|null getApprovedBy()
 * @method void setApprovedBy(string|null $v)
 * @method \DateTime|null getDecidedAt()
 * @method void setDecidedAt(\DateTime|null $v)
 * @method string getUid()
 * @method void setUid(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Reallocation extends Entity
{
	protected int $projectId = 0;
	protected int $planVerId = 0;
	protected int $fromPositionId = 0;
	protected int $toPositionId = 0;
	protected int $amountMinor = 0;
	protected string $reasonText = '';
	protected string $state = '';
	protected ?string $approvedBy = null;
	protected ?\DateTime $decidedAt = null;
	protected string $uid = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('planVerId', 'integer');
		$this->addType('fromPositionId', 'integer');
		$this->addType('toPositionId', 'integer');
		$this->addType('amountMinor', 'integer');
		$this->addType('reasonText', 'string');
		$this->addType('state', 'string');
		$this->addType('approvedBy', 'string');
		$this->addType('decidedAt', 'datetime');
		$this->addType('uid', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
