<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUid()
 * @method void setUid(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getFunderName()
 * @method void setFunderName(string|null $v)
 * @method string|null getFunderRef()
 * @method void setFunderRef(string|null $v)
 * @method \DateTime|null getBewStart()
 * @method void setBewStart(\DateTime|null $v)
 * @method \DateTime|null getBewEnd()
 * @method void setBewEnd(\DateTime|null $v)
 * @method string getTemplateKey()
 * @method void setTemplateKey(string $v)
 * @method string getFinancingMode()
 * @method void setFinancingMode(string $v)
 * @method int getRetentionYears()
 * @method void setRetentionYears(int $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $v)
 * @method string|null getIcsToken()
 * @method void setIcsToken(string|null $v)
 */
class Project extends Entity
{
	protected string $uid = '';
	protected string $name = '';
	protected ?string $funderName = null;
	protected ?string $funderRef = null;
	protected ?\DateTime $bewStart = null;
	protected ?\DateTime $bewEnd = null;
	protected string $templateKey = '';
	protected string $financingMode = '';
	protected int $retentionYears = 0;
	protected string $state = '';
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;
	protected ?string $icsToken = null;

	public function __construct()
	{
		$this->addType('uid', 'string');
		$this->addType('name', 'string');
		$this->addType('funderName', 'string');
		$this->addType('funderRef', 'string');
		$this->addType('bewStart', 'datetime');
		$this->addType('bewEnd', 'datetime');
		$this->addType('templateKey', 'string');
		$this->addType('financingMode', 'string');
		$this->addType('retentionYears', 'integer');
		$this->addType('state', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('icsToken', 'string');
	}
}
