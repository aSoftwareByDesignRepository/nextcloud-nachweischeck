<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getReceiptId()
 * @method void setReceiptId(int $v)
 * @method int getPositionId()
 * @method void setPositionId(int $v)
 * @method int|null getApId()
 * @method void setApId(int|null $v)
 * @method int getAmountMinor()
 * @method void setAmountMinor(int $v)
 * @method string|null getNote()
 * @method void setNote(string|null $v)
 */
class ReceiptSplit extends Entity
{
	protected int $receiptId = 0;
	protected int $positionId = 0;
	protected ?int $apId = null;
	protected int $amountMinor = 0;
	protected ?string $note = null;

	public function __construct()
	{
		$this->addType('receiptId', 'integer');
		$this->addType('positionId', 'integer');
		$this->addType('apId', 'integer');
		$this->addType('amountMinor', 'integer');
		$this->addType('note', 'string');
	}
}
