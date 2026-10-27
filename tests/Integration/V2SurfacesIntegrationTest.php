<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration coverage for the V2 surfaces against the real DB:
 * co-funder lifecycle (create → position binding → delete guard),
 * drawdown state machine bound to a real incoming payment, claim
 * interest computed through InterestCalculator act/360, readiness
 * checklist over real rows, and the DATEV/forecast derivations.
 */

namespace OCA\NachweisCheck\Tests\Integration;

use OCA\NachweisCheck\Db\Drawdown;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\ClaimService;
use OCA\NachweisCheck\Service\CofunderService;
use OCA\NachweisCheck\Service\DatevExportService;
use OCA\NachweisCheck\Service\DrawdownService;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\IncomingPaymentService;
use OCA\NachweisCheck\Service\ReadinessService;
use Test\TestCase;

class V2SurfacesIntegrationTest extends TestCase
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

	public function testCofunderPositionBindingAndDeleteGuard(): void
	{
		$uid = '__nw_it_v2__';
		$cof = \OCP\Server::get(CofunderService::class);
		$finplan = \OCP\Server::get(FinplanService::class);
		$p = $this->nwProject($uid);

		$c = $cof->create($p->getId(), 'Land Test', 'L-1', null, null, $uid);
		self::assertGreaterThan(0, $c->getId());

		// bind a position of the draft plan to the co-funder
		$draft = $finplan->draftFor($p->getId());
		$finplan->replacePositions($draft, [
			['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal', 'budget_minor' => 50000, 'cofunder_id' => $c->getId()],
		], $uid);
		$pos = $this->nwPositions($draft)[0];
		self::assertSame($c->getId(), $pos->getCofunderId());

		// referenced → delete refused
		$this->expectException(ConflictException::class);
		try {
			$cof->delete($p->getId(), $c->getId(), $uid);
		} finally {
			// unbind → delete allowed (verified via fresh read)
			$finplan->replacePositions($draft, [
				['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal', 'budget_minor' => 50000],
			], $uid);
			$cof->delete($p->getId(), $c->getId(), $uid);
			self::assertSame([], $cof->list($p->getId()));
		}
	}

	public function testForeignCofunderRejectedOnPlanWrite(): void
	{
		$uid = '__nw_it_v2__';
		$cof = \OCP\Server::get(CofunderService::class);
		$finplan = \OCP\Server::get(FinplanService::class);
		$p1 = $this->nwProject($uid);
		$p2 = $this->nwProject($uid);
		$foreign = $cof->create($p2->getId(), 'Other', null, null, null, $uid);

		$this->expectException(ValidationException::class);
		$finplan->replacePositions($finplan->draftFor($p1->getId()), [
			['code' => 'PK', 'title' => 'P', 'kind' => 'sach', 'budget_minor' => 1, 'cofunder_id' => $foreign->getId()],
		], $uid);
	}

	public function testDrawdownLifecycleBoundToRealPayment(): void
	{
		$uid = '__nw_it_v2__';
		$dds = \OCP\Server::get(DrawdownService::class);
		$ips = \OCP\Server::get(IncomingPaymentService::class);
		$p = $this->nwProject($uid);

		$d = $dds->create($p->getId(), '2026-03-01', 50000, 'AB-1', null, 'ext-dd-1', $uid);
		self::assertSame(Drawdown::STATE_REQUESTED, $d->getState());

		// duplicate external_ref refused at service AND db level
		try {
			$dds->create($p->getId(), '2026-03-02', 1, null, null, 'ext-dd-1', $uid);
			self::fail('duplicate external_ref accepted');
		} catch (ValidationException) {
		}

		$pay = $ips->create($p->getId(), '2026-03-15', 50000, 'AB-1', '', null, $uid);
		$d = $dds->markReceived($d, $pay->getId(), $uid);
		self::assertSame(Drawdown::STATE_RECEIVED, $d->getState());
		self::assertSame($pay->getId(), $d->getPaymentId());

		// decided drawdowns are terminal
		$this->expectException(ConflictException::class);
		$dds->reject($d, $uid);
	}

	public function testClaimInterestAndReadinessAndDatev(): void
	{
		$uid = '__nw_it_v2__';
		$claims = \OCP\Server::get(ClaimService::class);
		$ready = \OCP\Server::get(ReadinessService::class);
		$datev = \OCP\Server::get(DatevExportService::class);
		$p = $this->nwProject($uid);

		// interest claim computed server-side: 1000€ @5% for 360d = 50€
		$c = $claims->create($p->getId(), [
			'kind' => 'interest', 'title' => 'Zinsen', 'principal_minor' => 100000,
			'rate_bp' => 500, 'period_from' => '2026-01-01', 'period_to' => '2027-01-01',
			'occurred_date' => '2026-12-31',
		], $uid);
		self::assertSame(5000, $c->getAmountMinor());
		$claims->settle($c, $uid);

		// readiness sees missing plan as the blocker
		$report = $ready->checklist($p);
		self::assertFalse($report['ready']);
		self::assertSame('no_active_plan', $report['checks'][0]['id']);

		// DATEV export on empty project → header only
		$csv = $datev->buildCsv($p->getId(), '2026-01-01', '2026-12-31');
		self::assertStringStartsWith('Umsatz (ohne Soll/Haben-Kz)', $csv);
	}
}
