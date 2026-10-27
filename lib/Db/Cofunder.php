<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Co-financing funder (BQ-03 multi-funder support). Positions carry an
 * optional cofunder_id; NULL means the lead funder stored on nw_project.
 *
 * @method int getProjectId()
 * @method void setProjectId(int $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getRef()
 * @method void setRef(string|null $v)
 * @method string|null getContact()
 * @method void setContact(string|null $v)
 * @method string|null getNote()
 * @method void setNote(string|null $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class Cofunder extends Entity
{
	protected int $projectId = 0;
	protected string $name = '';
	protected ?string $ref = null;
	protected ?string $contact = null;
	protected ?string $note = null;
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('name', 'string');
		$this->addType('ref', 'string');
		$this->addType('contact', 'string');
		$this->addType('note', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
