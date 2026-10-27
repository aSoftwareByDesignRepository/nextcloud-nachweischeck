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
 * @extends QBMapper<Receipt>
 */
class ReceiptMapper extends QBMapper
{
	public const TABLE = 'nw_receipt';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Receipt::class);
	}

	public function findById(int $id): ?Receipt
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
	 * @return list<Receipt>
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
			->orderBy('invoice_date', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByProjectPeriod(int $projectId, string $fromYmd, string $toYmd): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->gte('invoice_date', $qb->createNamedParameter($fromYmd)))
			->andWhere($qb->expr()->lte('invoice_date', $qb->createNamedParameter($toYmd)))
			->orderBy('invoice_date', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function findByExternalRef(int $projectId, string $externalRef): ?Receipt
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

	/** Duplicate heuristic: same vendor+invoice_date+total+invoice_no. */
	public function findDuplicate(int $projectId, string $vendor, string $invoiceDateYmd, int $totalMinor, ?string $invoiceNo, ?int $excludeId = null): ?Receipt
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('vendor', $qb->createNamedParameter($vendor)))
			->andWhere($qb->expr()->eq('invoice_date', $qb->createNamedParameter($invoiceDateYmd)))
			->andWhere($qb->expr()->eq('total_minor', $qb->createNamedParameter($totalMinor)))
			->setMaxResults(1);
		if ($invoiceNo === null || $invoiceNo === '') {
			$qb->andWhere($qb->expr()->isNull('invoice_no'));
		} else {
			$qb->andWhere($qb->expr()->eq('invoice_no', $qb->createNamedParameter($invoiceNo)));
		}
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId)));
		}
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}}
