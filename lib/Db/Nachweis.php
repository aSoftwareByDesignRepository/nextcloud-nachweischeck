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
 * @method \DateTime getPeriodFrom()
 * @method void setPeriodFrom(\DateTime $v)
 * @method \DateTime getPeriodTo()
 * @method void setPeriodTo(\DateTime $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method int getVersion()
 * @method void setVersion(int $v)
 * @method string getManifestHash()
 * @method void setManifestHash(string $v)
 * @method string getPayloadJson()
 * @method void setPayloadJson(string $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method int|null getSupersedesId()
 * @method void setSupersedesId(int|null $v)
 * @method string|null getReason()
 * @method void setReason(string|null $v)
 * @method string getGeneratedBy()
 * @method void setGeneratedBy(string $v)
 * @method \DateTime getGeneratedAt()
 * @method void setGeneratedAt(\DateTime $v)
 */
class Nachweis extends Entity
{
	protected int $projectId = 0;
	protected ?\DateTime $periodFrom = null;
	protected ?\DateTime $periodTo = null;
	protected string $kind = '';
	protected int $version = 0;
	protected string $manifestHash = '';
	protected string $payloadJson = '';
	protected string $state = '';
	protected ?int $supersedesId = null;
	protected ?string $reason = null;
	protected string $generatedBy = '';
	protected ?\DateTime $generatedAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('periodFrom', 'datetime');
		$this->addType('periodTo', 'datetime');
		$this->addType('kind', 'string');
		$this->addType('version', 'integer');
		$this->addType('manifestHash', 'string');
		$this->addType('payloadJson', 'string');
		$this->addType('state', 'string');
		$this->addType('supersedesId', 'integer');
		$this->addType('reason', 'string');
		$this->addType('generatedBy', 'string');
		$this->addType('generatedAt', 'datetime');
	}
}
