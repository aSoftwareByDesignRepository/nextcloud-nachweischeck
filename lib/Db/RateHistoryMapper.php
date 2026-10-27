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
 * @extends QBMapper<RateHistory>
 */
class RateHistoryMapper extends QBMapper
{
	public const TABLE = 'nw_rate_history';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, RateHistory::class);
	}

	public function findById(int $id): ?RateHistory
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
	 * @return list<RateHistory>
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<array{valid_from:string, rate_minor:int}> */
	public function historyFor(int $projectId, string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('valid_from', 'rate_minor')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->orderBy('valid_from', 'ASC')->addOrderBy('id', 'ASC');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$vf = $row['valid_from'];
			$out[] = [
				'valid_from' => $vf instanceof \DateTimeInterface ? $vf->format('Y-m-d') : (string)$vf,
				'rate_minor' => (int)$row['rate_minor'],
			];
		}
		$res->closeCursor();
		return $out;
	}

	public function findByProjectUid(int $projectId, string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->orderBy('valid_from', 'ASC');
		return $this->findEntities($qb);
	}}
