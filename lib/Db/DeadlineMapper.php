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
 * @extends QBMapper<Deadline>
 */
class DeadlineMapper extends QBMapper
{
	public const TABLE = 'nw_deadline';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Deadline::class);
	}

	public function findById(int $id): ?Deadline
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
	 * @return list<Deadline>
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
			->orderBy('due_date', 'ASC');
		return $this->findEntities($qb);
	}

	public function findDueUnnotified(string $ymd): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNull('notified_at'))
			->andWhere($qb->expr()->lte('due_date', $qb->createNamedParameter($ymd)))
			->orderBy('due_date', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByDedupeKey(string $key): ?Deadline
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($key)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}}
