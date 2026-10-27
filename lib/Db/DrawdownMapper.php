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
 * @extends QBMapper<Drawdown>
 */
class DrawdownMapper extends QBMapper
{
	public const TABLE = 'nw_drawdown';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Drawdown::class);
	}

	public function findById(int $id): ?Drawdown
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
	 * @return list<Drawdown>
	 */
	public function findByProject(int $projectId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->orderBy('requested_date', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @return list<Drawdown>
	 */
	public function findByProjectState(int $projectId, string $state): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->orderBy('requested_date', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByExternalRef(int $projectId, string $externalRef): ?Drawdown
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('external_ref', $qb->createNamedParameter($externalRef)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
