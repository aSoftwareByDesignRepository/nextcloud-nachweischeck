<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getPlanVerId()
 * @method void setPlanVerId(int $v)
 * @method string getCode()
 * @method void setCode(string $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method int getBudgetMinor()
 * @method void setBudgetMinor(int $v)
 * @method int getSort()
 * @method void setSort(int $v)
 * @method int|null getCofunderId()
 * @method void setCofunderId(int|null $v)
 */
class Position extends Entity
{
	protected int $planVerId = 0;
	protected string $code = '';
	protected string $title = '';
	protected string $kind = '';
	protected int $budgetMinor = 0;
	protected int $sort = 0;
	protected ?int $cofunderId = null;

	public function __construct()
	{
		$this->addType('planVerId', 'integer');
		$this->addType('code', 'string');
		$this->addType('title', 'string');
		$this->addType('kind', 'string');
		$this->addType('budgetMinor', 'integer');
		$this->addType('sort', 'integer');
		$this->addType('cofunderId', 'integer');
	}
}
