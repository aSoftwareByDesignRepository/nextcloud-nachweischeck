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
 * @extends QBMapper<ApPosition>
 */
class ApPositionMapper extends QBMapper
{
	public const TABLE = 'nw_ap_position';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ApPosition::class);
	}

	public function findById(int $id): ?ApPosition
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
	 * @return list<ApPosition>
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByAp(int $apId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('ap_id', $qb->createNamedParameter($apId)));
		return $this->findEntities($qb);
	}

	public function deleteByAp(int $apId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('ap_id', $qb->createNamedParameter($apId)));
		$qb->executeStatement();
	}}
