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
 * @method string getScope()
 * @method void setScope(string $v)
 * @method int getRefId()
 * @method void setRefId(int $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string getReasonText()
 * @method void setReasonText(string $v)
 * @method string getUid()
 * @method void setUid(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Deviation extends Entity
{
	protected int $projectId = 0;
	protected string $scope = '';
	protected int $refId = 0;
	protected string $kind = '';
	protected string $reasonText = '';
	protected string $uid = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('scope', 'string');
		$this->addType('refId', 'integer');
		$this->addType('kind', 'string');
		$this->addType('reasonText', 'string');
		$this->addType('uid', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
