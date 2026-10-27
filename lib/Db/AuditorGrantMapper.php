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
 * @extends QBMapper<AuditorGrant>
 */
class AuditorGrantMapper extends QBMapper
{
	public const TABLE = 'nw_auditor_grant';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, AuditorGrant::class);
	}

	public function findById(int $id): ?AuditorGrant
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
	 * @return list<AuditorGrant>
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function activeFor(int $projectId, string $uid): ?AuditorGrant
	{
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('nc_uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->gte('valid_to', $qb->createNamedParameter($today)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByUid(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('nc_uid', $qb->createNamedParameter($uid)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function findByProject(int $projectId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}}
