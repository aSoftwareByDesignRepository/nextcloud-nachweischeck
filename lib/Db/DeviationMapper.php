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
 * @extends QBMapper<Deviation>
 */
class DeviationMapper extends QBMapper
{
	public const TABLE = 'nw_deviation';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Deviation::class);
	}

	public function findById(int $id): ?Deviation
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
	 * @return list<Deviation>
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByProject(int $projectId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function findFor(string $scope, int $refId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->andWhere($qb->expr()->eq('ref_id', $qb->createNamedParameter($refId)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}}
