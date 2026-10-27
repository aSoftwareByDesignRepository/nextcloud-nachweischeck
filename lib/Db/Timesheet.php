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
 * @method int|null getApId()
 * @method void setApId(int|null $v)
 * @method string getUid()
 * @method void setUid(string $v)
 * @method string getPeriod()
 * @method void setPeriod(string $v)
 * @method int getMinutesTotal()
 * @method void setMinutesTotal(int $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method \DateTime|null getSubmittedAt()
 * @method void setSubmittedAt(\DateTime|null $v)
 * @method string|null getSignedBy()
 * @method void setSignedBy(string|null $v)
 * @method \DateTime|null getSignedAt()
 * @method void setSignedAt(\DateTime|null $v)
 * @method string|null getRejectReason()
 * @method void setRejectReason(string|null $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 */
class Timesheet extends Entity
{
	protected int $projectId = 0;
	protected ?int $apId = null;
	protected string $uid = '';
	protected string $period = '';
	protected int $minutesTotal = 0;
	protected string $state = '';
	protected ?\DateTime $submittedAt = null;
	protected ?string $signedBy = null;
	protected ?\DateTime $signedAt = null;
	protected ?string $rejectReason = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('apId', 'integer');
		$this->addType('uid', 'string');
		$this->addType('period', 'string');
		$this->addType('minutesTotal', 'integer');
		$this->addType('state', 'string');
		$this->addType('submittedAt', 'datetime');
		$this->addType('signedBy', 'string');
		$this->addType('signedAt', 'datetime');
		$this->addType('rejectReason', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
