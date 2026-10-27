<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Spec §10 integration: auditor grant lifecycle (US-NW-06, V1-10).
 * activeFor() is the single authority the auditor surface consults:
 * valid window enforced both ends, revoke effective immediately, one live
 * grant per (project, uid), every read lands in nw_auditor_read.
 */

namespace OCA\NachweisCheck\Tests\Integration;

use OCA\NachweisCheck\Db\AuditorReadMapper;
use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\AuditorReadLogger;
use OCA\NachweisCheck\Service\AuditorService;
use Test\TestCase;

class AuditorGrantIntegrationTest extends TestCase
{
	use NwFixture;

	private AuditorService $auditors;

	protected function setUp(): void
	{
		parent::setUp();
		$this->nwInit();
		$this->auditors = \OCP\Server::get(AuditorService::class);
	}

	protected function tearDown(): void
	{
		$this->nwPurge();
		parent::tearDown();
	}

	public function testGrantExpiryAndRevokeCloseTheSeat(): void
	{
		$p = $this->nwProject('__nw_it_aud_owner__');
		$auditor = '__nw_it_aud1__';
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		$nextMonth = (new \DateTimeImmutable('today +30 days'))->format('Y-m-d');

		$g = $this->auditors->grant($p->getId(), $auditor, 'Audit Or', 'Kanzlei', $today, $nextMonth, ['belege'], 'admin');
		self::assertNotNull($this->auditors->activeGrant($p->getId(), $auditor));

		// A different uid sees nothing — grant is account-bound.
		self::assertNull($this->auditors->activeGrant($p->getId(), '__nw_it_aud_other__'));
		// A different project sees nothing — grant is scope-bound.
		$p2 = $this->nwProject('__nw_it_aud_owner__');
		self::assertNull($this->auditors->activeGrant($p2->getId(), $auditor));

		// Second live grant for the same seat → refused (one seat per auditor).
		try {
			$this->auditors->grant($p->getId(), $auditor, 'Audit Or', 'Kanzlei', $today, $nextMonth, null, 'admin');
			self::fail('duplicate live grant must refuse');
		} catch (ConflictException $e) {
			self::assertSame('GRANT_EXISTS', $e->getErrorCode());
		}

		$this->auditors->revoke($g, 'admin');
		self::assertNull($this->auditors->activeGrant($p->getId(), $auditor), 'revoked grant must close immediately');
	}

	public function testExpiredAndNotYetValidGrantsAreInactive(): void
	{
		$p = $this->nwProject('__nw_it_aud_owner__');
		$yesterday = (new \DateTimeImmutable('today -1 day'))->format('Y-m-d');
		$tomorrow = (new \DateTimeImmutable('today +1 day'))->format('Y-m-d');
		$nextWeek = (new \DateTimeImmutable('today +7 days'))->format('Y-m-d');

		$this->auditors->grant($p->getId(), '__nw_it_aud_exp__', 'E', 'F', '2020-01-01', $yesterday, null, 'admin');
		self::assertNull($this->auditors->activeGrant($p->getId(), '__nw_it_aud_exp__'), 'expired grant must be inactive');

		$this->auditors->grant($p->getId(), '__nw_it_aud_future__', 'F', 'F', $tomorrow, $nextWeek, null, 'admin');
		self::assertNull($this->auditors->activeGrant($p->getId(), '__nw_it_aud_future__'), 'future grant must not be active yet');
	}

	public function testInvalidGrantDatesAreValidationErrors(): void
	{
		$p = $this->nwProject('__nw_it_aud_owner__');
		try {
			$this->auditors->grant($p->getId(), 'u', 'N', 'F', '2026-12-31', '2026-01-01', null, 'admin');
			self::fail('inverted validity must refuse');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('valid_to', $e->getFields());
		}
		try {
			$this->auditors->grant($p->getId(), '', 'N', 'F', '2026-01-01', '2026-12-31', null, 'admin');
			self::fail('missing account must refuse');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('nc_uid', $e->getFields());
		}
	}

	public function testEveryReadIsLoggedAgainstTheGrant(): void
	{
		$p = $this->nwProject('__nw_it_aud_owner__');
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		$nextMonth = (new \DateTimeImmutable('today +30 days'))->format('Y-m-d');
		$g = $this->auditors->grant($p->getId(), '__nw_it_aud_log__', 'L', 'F', $today, $nextMonth, null, 'admin');

		$logger = \OCP\Server::get(AuditorReadLogger::class);
		$logger->log($g, 'auditor:belege', 'project', $p->getId());
		$logger->log($g, 'auditor:soll_ist', 'project', $p->getId());

		$reads = \OCP\Server::get(AuditorReadMapper::class)->findByGrant($g->getId());
		self::assertCount(2, $reads);
		self::assertSame('auditor:belege', $reads[0]->getRoute());
		self::assertSame('auditor:soll_ist', $reads[1]->getRoute());
	}
}
