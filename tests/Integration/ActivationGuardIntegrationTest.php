<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Spec §10 integration: project activation guard — an AP with zero mapped
 * positions blocks activation with a named error (US-NW-01 AC-3), mapping
 * unblocks it, and activation recomputes the deadline set (MH-10).
 */

namespace OCA\NachweisCheck\Tests\Integration;

use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\DeadlineService;
use OCA\NachweisCheck\Service\ProjectService;
use OCA\NachweisCheck\Service\WorkPackageService;
use Test\TestCase;

class ActivationGuardIntegrationTest extends TestCase
{
	use NwFixture;

	protected function setUp(): void
	{
		parent::setUp();
		$this->nwInit();
	}

	protected function tearDown(): void
	{
		$this->nwPurge();
		parent::tearDown();
	}

	public function testUnmappedWorkPackageBlocksActivationWithNamedError(): void
	{
		$uid = '__nw_it_guard__';
		$p = $this->nwProject($uid);
		$ver = $this->nwActivatePlan($p, [
			['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal', 'budget_minor' => 100000],
		], $uid);

		// An AP exists but has NO position mapping → activation must refuse
		// and name the offending AP code.
		$aps = \OCP\Server::get(WorkPackageService::class);
		$aps->create($p->getId(), 'APX', 'Unmapped', null, '2026-01-01', '2026-06-30', $uid);

		try {
			$this->nwActivateProject($p, $uid);
			self::fail('activation must refuse unmapped APs');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('work_packages', $e->getFields());
			self::assertStringContainsString('APX', $e->getFields()['work_packages']);
		}
		self::assertSame('draft', $p->getState(), 'refused activation must not flip state');
	}

	public function testMappingUnblocksAndDeadlineSetMaterializes(): void
	{
		$uid = '__nw_it_guard2__';
		$p = $this->nwProject($uid);
		$ver = $this->nwActivatePlan($p, [
			['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal', 'budget_minor' => 100000],
		], $uid);
		$posIds = array_map(static fn($pos) => $pos->getId(), $this->nwPositions($ver));

		$ap = $this->nwAp($p, 'AP1', $posIds, $uid);
		self::assertSame([], \OCP\Server::get(WorkPackageService::class)->unmappedFor($p->getId()));

		$p = $this->nwActivateProject($p, $uid);
		self::assertSame('active', $p->getState());

		$kinds = array_map(
			static fn($d) => $d->getKind(),
			\OCP\Server::get(DeadlineService::class)->forProject($p->getId()),
		);
		self::assertContains('nachweis_frist', $kinds);
		self::assertContains('bew_end_warning', $kinds);
		self::assertContains('ap_end', $kinds);
		self::assertContains('zwischennachweis', $kinds); // 2026 year boundary inside 2026-2027 period
	}
}
