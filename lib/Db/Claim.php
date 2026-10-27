<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Zinsen-/Rueckforderungs-Tracking (NH-06, ANBest-P Nr. 8).
 * kind 'interest': interest accrued on unused grant funds —
 *   principal_minor × rate_bp basis points × days(act/360) between
 *   period_from and period_to, computed into amount_minor.
 * kind 'recovery': a funder's Rueckforderung — amount_minor is the
 *   demanded sum; principal stays the referenced overpaid amount.
 *
 * @method int getProjectId()
 * @method void setProjectId(int $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method int getPrincipalMinor()
 * @method void setPrincipalMinor(int $v)
 * @method int getRateBp()
 * @method void setRateBp(int $v)
 * @method \DateTime|null getPeriodFrom()
 * @method void setPeriodFrom(\DateTime|null $v)
 * @method \DateTime|null getPeriodTo()
 * @method void setPeriodTo(\DateTime|null $v)
 * @method int getAmountMinor()
 * @method void setAmountMinor(int $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method \DateTime getOccurredDate()
 * @method void setOccurredDate(\DateTime $v)
 * @method string|null getNote()
 * @method void setNote(string|null $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Claim extends Entity
{
	public const KIND_INTEREST = 'interest';
	public const KIND_RECOVERY = 'recovery';

	public const STATE_OPEN = 'open';
	public const STATE_SETTLED = 'settled';

	protected int $projectId = 0;
	protected string $kind = self::KIND_RECOVERY;
	protected string $title = '';
	protected int $principalMinor = 0;
	protected int $rateBp = 0;
	protected ?\DateTime $periodFrom = null;
	protected ?\DateTime $periodTo = null;
	protected int $amountMinor = 0;
	protected string $state = self::STATE_OPEN;
	protected ?\DateTime $occurredDate = null;
	protected ?string $note = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('kind', 'string');
		$this->addType('title', 'string');
		$this->addType('principalMinor', 'integer');
		$this->addType('rateBp', 'integer');
		$this->addType('periodFrom', 'datetime');
		$this->addType('periodTo', 'datetime');
		$this->addType('amountMinor', 'integer');
		$this->addType('state', 'string');
		$this->addType('occurredDate', 'datetime');
		$this->addType('note', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
