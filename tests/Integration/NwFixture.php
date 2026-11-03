<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared fixture for NachweisCheck integration tests: creates real projects
 * through the service layer (so every row is production-shaped) and purges
 * the full project subtree in tearDown — including the join tables that
 * carry no project_id (nw_ap_position, nw_position, nw_receipt_split,
 * nw_workreport, nw_auditor_read).
 */

namespace OCA\NachweisCheck\Tests\Integration;

use OCA\NachweisCheck\Db\FinplanVersion;
use OCA\NachweisCheck\Db\Project;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\ProjectService;
use OCA\NachweisCheck\Service\WorkPackageService;
use OCP\IDBConnection;

trait NwFixture
{
	private IDBConnection $nwDb;

	/** @var list<int> project ids created by this test — purged in tearDown */
	private array $nwProjects = [];

	private function nwInit(): void
	{
		$this->nwDb = \OCP\Server::get(IDBConnection::class);
		if (!$this->nwDb->tableExists('nw_project')) {
			self::markTestSkipped('NachweisCheck tables not present in this instance.');
		}
	}

	/** Create a project with a seeded draft plan v1 (template skeleton). */
	private function nwProject(string $uid, array $opts = []): Project
	{
		$p = \OCP\Server::get(ProjectService::class)->create(
			$uid,
			$opts['name'] ?? 'NW-IT ' . bin2hex(random_bytes(4)),
			$opts['funder_name'] ?? 'IT-Funder',
			$opts['funder_ref'] ?? 'FKZ-' . bin2hex(random_bytes(3)),
			$opts['bew_start'] ?? '2026-01-01',
			$opts['bew_end'] ?? '2027-12-31',
			$opts['template_key'] ?? 'anbest-p',
			$opts['financing_mode'] ?? 'ausgaben',
			'IT Fixture',
		);
		$this->nwProjects[] = $p->getId();
		return $p;
	}

	/** Replace the seeded skeleton budgets and activate the draft plan. */
	private function nwActivatePlan(Project $p, array $positions, string $actor): FinplanVersion
	{
		$finplan = \OCP\Server::get(FinplanService::class);
		$draft = $finplan->draftFor($p->getId());
		self::assertNotNull($draft, 'fixture expects a seeded draft plan');
		$finplan->replacePositions($draft, $positions, $actor);
		return $finplan->activate($draft, 0, $actor);
	}

	private function nwPositions(FinplanVersion $ver): array
	{
		return \OCP\Server::get(FinplanService::class)->positions($ver);
	}

	private function nwActivateProject(Project $p, string $actor): Project
	{
		return \OCP\Server::get(ProjectService::class)->activate($p, $actor);
	}

	private function nwAp(Project $p, string $code, array $positionIds, string $actor): \OCA\NachweisCheck\Db\WorkPackage
	{
		$aps = \OCP\Server::get(WorkPackageService::class);
		$ap = $aps->create($p->getId(), $code, 'AP ' . $code, null, '2026-01-01', '2026-12-31', $actor);
		$aps->setPositions($ap, $positionIds, $actor);
		return $ap;
	}

	private function nwPurge(): void
	{
		foreach ($this->nwProjects as $pid) {
			$apIds = $this->nwIds('nw_workpackage', $pid);
			$receiptIds = $this->nwIds('nw_receipt', $pid);
			$verIds = $this->nwIds('nw_finplan_ver', $pid);
			$grantIds = $this->nwIds('nw_auditor_grant', $pid);

			$posIds = [];
			foreach ($verIds as $vid) {
				$posIds = array_merge($posIds, $this->nwIdsBy('nw_position', 'plan_ver_id', $vid));
			}

			$this->nwDeleteIn('nw_ap_position', 'ap_id', $apIds);
			$this->nwDeleteIn('nw_ap_position', 'position_id', $posIds);
			$this->nwDeleteIn('nw_workreport', 'ap_id', $apIds);
			$this->nwDeleteIn('nw_receipt_split', 'receipt_id', $receiptIds);
			$this->nwDeleteIn('nw_receipt_split', 'position_id', $posIds);
			$this->nwDeleteIn('nw_auditor_read', 'grant_id', $grantIds);
			foreach ($verIds as $vid) {
				$this->nwDelete('nw_position', 'plan_ver_id', $vid);
			}

			foreach ([
				'nw_project_member', 'nw_finplan_ver', 'nw_workpackage',
				'nw_rate_history', 'nw_time_entry', 'nw_receipt', 'nw_deviation',
				'nw_reallocation', 'nw_incoming_payment', 'nw_carryover',
				'nw_timesheet', 'nw_nachweis', 'nw_audit_event',
				'nw_auditor_grant', 'nw_deadline', 'nw_import_batch',
				'nw_cofunder', 'nw_drawdown', 'nw_claim',
			] as $table) {
				$this->nwDelete($table, 'project_id', $pid);
			}
			$this->nwDelete('nw_project', 'id', $pid);
		}
		$this->nwProjects = [];
		$this->nwPurgeGlobalFixtures();
	}

	/**
	 * Global (non-project-scoped) tables: purge only fixture-marker rows.
	 * nw_license_state is the instance license singleton — a blanket delete
	 * would nuke the lab license, so rows go only when the actor column
	 * carries a fixture prefix (__nw_it_*, nw_e2e_*, nwt-*).
	 */
	private function nwPurgeGlobalFixtures(): void
	{
		foreach (['nw_mobile_seat' => 'uid', 'nw_license_state' => 'applied_by', 'nw_template' => 'tpl_key'] as $table => $col) {
			if (!$this->nwDb->tableExists($table)) {
				continue;
			}
			$qb = $this->nwDb->getQueryBuilder();
			$or = $qb->expr()->orX();
			foreach (['__nw\_it\_%', 'nw\_e2e\_%', 'nwt-%'] as $pattern) {
				$or->add($qb->expr()->like($col, $qb->createNamedParameter($pattern)));
			}
			$qb->delete($table)->where($or)->executeStatement();
		}
	}

	/** @return list<int> */
	private function nwIds(string $table, int $projectId): array
	{
		return $this->nwIdsBy($table, 'project_id', $projectId);
	}

	/** @return list<int> */
	private function nwIdsBy(string $table, string $col, int $val): array
	{
		$qb = $this->nwDb->getQueryBuilder();
		$qb->select('id')->from($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($val)));
		return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
	}

	private function nwDelete(string $table, string $col, int $val): void
	{
		$qb = $this->nwDb->getQueryBuilder();
		$qb->delete($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($val)));
		$qb->executeStatement();
	}

	/** @param list<int> $ids */
	private function nwDeleteIn(string $table, string $col, array $ids): void
	{
		foreach ($ids as $id) {
			$this->nwDelete($table, $col, $id);
		}
	}
}
