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
 * @method string getNcUid()
 * @method void setNcUid(string $v)
 * @method string|null getName()
 * @method void setName(string|null $v)
 * @method string|null getFirm()
 * @method void setFirm(string|null $v)
 * @method string|null getScopeJson()
 * @method void setScopeJson(string|null $v)
 * @method \DateTime getValidFrom()
 * @method void setValidFrom(\DateTime $v)
 * @method \DateTime getValidTo()
 * @method void setValidTo(\DateTime $v)
 * @method \DateTime|null getRevokedAt()
 * @method void setRevokedAt(\DateTime|null $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class AuditorGrant extends Entity
{
	protected int $projectId = 0;
	protected string $ncUid = '';
	protected ?string $name = null;
	protected ?string $firm = null;
	protected ?string $scopeJson = null;
	protected ?\DateTime $validFrom = null;
	protected ?\DateTime $validTo = null;
	protected ?\DateTime $revokedAt = null;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('ncUid', 'string');
		$this->addType('name', 'string');
		$this->addType('firm', 'string');
		$this->addType('scopeJson', 'string');
		$this->addType('validFrom', 'datetime');
		$this->addType('validTo', 'datetime');
		$this->addType('revokedAt', 'datetime');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
