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
 * @extends QBMapper<FinplanVersion>
 */
class FinplanVersionMapper extends QBMapper
{
	public const TABLE = 'nw_finplan_ver';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, FinplanVersion::class);
	}

	public function findById(int $id): ?FinplanVersion
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
	 * @return list<FinplanVersion>
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
			->orderBy('version', 'ASC');
		return $this->findEntities($qb);
	}

	public function activeFor(int $projectId): ?FinplanVersion
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter('active')))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function maxVersion(int $projectId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('version'))->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)));
		$res = $qb->executeQuery();
		$v = $res->fetchOne();
		$res->closeCursor();
		return (int)($v ?: 0);
	}}
