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
 * @extends QBMapper<Project>
 */
class ProjectMapper extends QBMapper
{
	public const TABLE = 'nw_project';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Project::class);
	}

	public function findById(int $id): ?Project
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
	 * @return list<Project>
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findForUid(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('p.*')->from($this->getTableName(), 'p')
			->leftJoin('p', 'nw_project_member', 'm', 'm.project_id = p.id')
			->where($qb->expr()->orX(
				$qb->expr()->eq('p.uid', $qb->createNamedParameter($uid)),
				$qb->expr()->eq('m.uid', $qb->createNamedParameter($uid)),
			))
			->groupBy('p.id')
			->orderBy('p.id', 'DESC');
		return $this->findEntities($qb);
	}}
