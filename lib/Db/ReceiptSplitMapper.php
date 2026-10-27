<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NachweisCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ReceiptSplit>
 */
class ReceiptSplitMapper extends QBMapper
{
	public const TABLE = 'nw_receipt_split';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ReceiptSplit::class);
	}

	public function findById(int $id): ?ReceiptSplit
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<ReceiptSplit>
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByReceipt(int $receiptId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('receipt_id', $qb->createNamedParameter($receiptId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByReceipt(int $receiptId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('receipt_id', $qb->createNamedParameter($receiptId)));
		$qb->executeStatement();
	}

	/** position_id => sum(amount_minor) over a receipt id list. */
	public function sumByPositions(array $receiptIds): array
	{
		if ($receiptIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('position_id')
			->selectAlias($qb->func()->sum('amount_minor'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->in('receipt_id', $qb->createNamedParameter($receiptIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
			->groupBy('position_id');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(int)$row['position_id']] = (int)$row['total'];
		}
		$res->closeCursor();
		return $out;
	}}
