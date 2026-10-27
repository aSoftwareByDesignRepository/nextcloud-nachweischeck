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
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string getPreset()
 * @method void setPreset(string $v)
 * @method string|null getFilename()
 * @method void setFilename(string|null $v)
 * @method int getRowsOk()
 * @method void setRowsOk(int $v)
 * @method int getRowsFailed()
 * @method void setRowsFailed(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $v)
 */
class ImportBatch extends Entity
{
	protected int $projectId = 0;
	protected string $kind = '';
	protected string $preset = '';
	protected ?string $filename = null;
	protected int $rowsOk = 0;
	protected int $rowsFailed = 0;
	protected string $createdBy = '';
	protected ?\DateTime $createdAt = null;

	public function __construct()
	{
		$this->addType('projectId', 'integer');
		$this->addType('kind', 'string');
		$this->addType('preset', 'string');
		$this->addType('filename', 'string');
		$this->addType('rowsOk', 'integer');
		$this->addType('rowsFailed', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
