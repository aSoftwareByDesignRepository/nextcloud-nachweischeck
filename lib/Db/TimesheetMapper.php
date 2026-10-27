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
 * @extends QBMapper<Timesheet>
 */
class TimesheetMapper extends QBMapper
{
	public const TABLE = 'nw_timesheet';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Timesheet::class);
	}

	public function findById(int $id): ?Timesheet
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
	 * @return list<Timesheet>
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
			->orderBy('period', 'DESC')->addOrderBy('uid', 'ASC');
		return $this->findEntities($qb);
	}

	public function findFor(int $projectId, string $uid, string $period, ?int $apId): ?Timesheet
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('period', $qb->createNamedParameter($period)))
			->setMaxResults(1);
		if ($apId === null) {
			$qb->andWhere($qb->expr()->isNull('ap_id'));
		} else {
			$qb->andWhere($qb->expr()->eq('ap_id', $qb->createNamedParameter($apId)));
		}
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}}
