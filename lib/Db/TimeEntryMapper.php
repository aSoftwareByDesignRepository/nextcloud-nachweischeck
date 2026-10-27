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
 * @extends QBMapper<TimeEntry>
 */
class TimeEntryMapper extends QBMapper
{
	public const TABLE = 'nw_time_entry';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, TimeEntry::class);
	}

	public function findById(int $id): ?TimeEntry
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
	 * @return list<TimeEntry>
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
			->where($qb->expr()->eq('ap_id', $qb->createNamedParameter($apId)))
			->orderBy('entry_date', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByProjectPeriod(int $projectId, string $fromYmd, string $toYmd): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->gte('entry_date', $qb->createNamedParameter($fromYmd)))
			->andWhere($qb->expr()->lte('entry_date', $qb->createNamedParameter($toYmd)))
			->orderBy('entry_date', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByUidPeriod(int $projectId, string $uid, string $fromYmd, string $toYmd, ?int $apId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->gte('entry_date', $qb->createNamedParameter($fromYmd)))
			->andWhere($qb->expr()->lte('entry_date', $qb->createNamedParameter($toYmd)))
			->orderBy('entry_date', 'ASC');
		if ($apId !== null) {
			$qb->andWhere($qb->expr()->eq('ap_id', $qb->createNamedParameter($apId)));
		}
		return $this->findEntities($qb);
	}

	public function findByExternalRef(int $projectId, string $externalRef): ?TimeEntry
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
	}}
