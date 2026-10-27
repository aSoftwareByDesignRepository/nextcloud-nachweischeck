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
 * @method \DateTime getReceivedDate()
 * @method void setReceivedDate(\DateTime $v)
 * @method int getAmountMinor()
 * @method void setAmountMinor(int $v)
 * @method string|null getCallRef()
 * @method void setCallRef(string|null $v)
 * @method int getYearReceived()
 * @method void setYearReceived(int $v)
 * @method string|null getNote()
 * @method void setNote(string|null $v)
 * @method string|null getExternalRef()
 * @method void setExternalRef(string|null $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class IncomingPayment extends Entity
{
	protected int $projectId = 0;
	protected ?\DateTime $receivedDate = null;
	protected int $amountMinor = 0;
	protected ?string $callRef = null;
	protected int $yearReceived = 0;
	protected ?string $note = null;
	protected ?string $externalRef = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('receivedDate', 'datetime');
		$this->addType('amountMinor', 'integer');
		$this->addType('callRef', 'string');
		$this->addType('yearReceived', 'integer');
		$this->addType('note', 'string');
		$this->addType('externalRef', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
