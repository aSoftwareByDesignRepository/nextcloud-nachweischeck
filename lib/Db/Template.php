<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getTplKey()
 * @method void setTplKey(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getRuleFlags()
 * @method void setRuleFlags(string|null $v)
 * @method string|null getSkeleton()
 * @method void setSkeleton(string|null $v)
 * @method string getSource()
 * @method void setSource(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Template extends Entity
{
	protected string $tplKey = '';
	protected string $name = '';
	protected ?string $ruleFlags = null;
	protected ?string $skeleton = null;
	protected string $source = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('tplKey', 'string');
		$this->addType('name', 'string');
		$this->addType('ruleFlags', 'string');
		$this->addType('skeleton', 'string');
		$this->addType('source', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
