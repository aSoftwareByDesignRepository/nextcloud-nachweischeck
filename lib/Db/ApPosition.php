<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getApId()
 * @method void setApId(int $v)
 * @method int getPositionId()
 * @method void setPositionId(int $v)
 */
class ApPosition extends Entity
{
	protected int $apId = 0;
	protected int $positionId = 0;

	public function __construct()
	{
		$this->addType('apId', 'integer');
		$this->addType('positionId', 'integer');
	}
}
