<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Mittelabruf history row (NH-09). A drawdown starts life 'requested';
 * when the money arrives it is linked to the matching nw_incoming_payment
 * row via payment_id and becomes 'received' — or 'rejected' when the
 * funder declines. History only: no approval workflow inside the app.
 *
 * @method int getProjectId()
 * @method void setProjectId(int $v)
 * @method string|null getCallRef()
 * @method void setCallRef(string|null $v)
 * @method \DateTime getRequestedDate()
 * @method void setRequestedDate(\DateTime $v)
 * @method int getAmountMinor()
 * @method void setAmountMinor(int $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method int|null getPaymentId()
 * @method void setPaymentId(int|null $v)
 * @method string|null getNote()
 * @method void setNote(string|null $v)
 * @method string|null getExternalRef()
 * @method void setExternalRef(string|null $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Drawdown extends Entity
{
	public const STATE_REQUESTED = 'requested';
	public const STATE_RECEIVED = 'received';
	public const STATE_REJECTED = 'rejected';

	protected int $projectId = 0;
	protected ?string $callRef = null;
	protected ?\DateTime $requestedDate = null;
	protected int $amountMinor = 0;
	protected string $state = self::STATE_REQUESTED;
	protected ?int $paymentId = null;
	protected ?string $note = null;
	protected ?string $externalRef = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('callRef', 'string');
		$this->addType('requestedDate', 'datetime');
		$this->addType('amountMinor', 'integer');
		$this->addType('state', 'string');
		$this->addType('paymentId', 'integer');
		$this->addType('note', 'string');
		$this->addType('externalRef', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
