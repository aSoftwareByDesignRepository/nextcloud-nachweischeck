<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Spec §10 integration: export job over a synthetic dataset (GAC-1, GAC-7).
 * Builds a full project — plan, AP, rate-frozen time entry, split receipt,
 * incoming payment — generates the Nachweis and asserts: complete package
 * sections, manifest self-verify, gap accounting (unpriced entries and
 * missing attachments surface in "Fehlende Angaben", never dropped), and
 * immutable versioning (correction = new version with reason).
 */

namespace OCA\NachweisCheck\Tests\Integration;

use OCA\NachweisCheck\Exception\ConflictException;
use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Service\IncomingPaymentService;
use OCA\NachweisCheck\Service\NachweisService;
use OCA\NachweisCheck\Service\RateService;
use OCA\NachweisCheck\Service\ReceiptService;
use OCA\NachweisCheck\Service\TimeEntryService;
use OCP\IUserManager;
use Test\TestCase;

class NachweisExportIntegrationTest extends TestCase
{
	use NwFixture;

	private const RATE_UID = '__nw_it_export__';

	protected function setUp(): void
	{
		parent::setUp();
		$this->nwInit();
		// RateService::addRate enforces existence+enabled+membership — the
		// priced-entry leg needs a real live account, not a fixture string.
		$um = \OCP\Server::get(IUserManager::class);
		if ($um->userExists(self::RATE_UID)) {
			$um->get(self::RATE_UID)?->delete();
		}
		$um->createUser(self::RATE_UID, 'NwIt-Export-' . bin2hex(random_bytes(4)) . '!');
	}

	protected function tearDown(): void
	{
		try {
			\OCP\Server::get(IUserManager::class)->get(self::RATE_UID)?->delete();
		} catch (\Throwable) {
		}
		$this->nwPurge();
		parent::tearDown();
	}

	public function testPackageCompletenessGapsAndImmutableVersioning(): void
	{
		$uid = self::RATE_UID;
		$p = $this->nwProject($uid);
		$ver = $this->nwActivatePlan($p, [
			['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal', 'budget_minor' => 10000000],
			['code' => 'SK', 'title' => 'Sachkosten', 'kind' => 'sach', 'budget_minor' => 500000],
		], $uid);
		[$posPk, $posSk] = $this->nwPositions($ver);
		$ap = $this->nwAp($p, 'AP1', [$posPk->getId(), $posSk->getId()], $uid);
		$this->nwActivateProject($p, $uid);

		// Priced time entry: 90 min @ 60,00 €/h → 90,00 € frozen at insert.
		\OCP\Server::get(RateService::class)->addRate($p->getId(), $uid, 'dev', 6000, '2026-01-01', $uid);
		$entry = \OCP\Server::get(TimeEntryService::class)->create(
			$p->getId(), $ap->getId(), $uid, '2026-03-10', 90, 'konzept', null, $uid);
		self::assertSame(6000, $entry->getRateMinor());
		self::assertSame(9000, $entry->getAmountMinor());

		// Unpriced entry (no rate for this uid) → must land in the gaps, not vanish.
		$ghost = \OCP\Server::get(TimeEntryService::class)->create(
			$p->getId(), $ap->getId(), '__nw_it_norate__', '2026-03-11', 30, 'unpriced', null, $uid);
		self::assertNull($ghost->getRateMinor());

		// Receipt, split across both positions, WITHOUT file attachment → gap.
		\OCP\Server::get(ReceiptService::class)->create(
			$p->getId(), 'IT-Vendor GmbH', 'R-1', '2026-03-15', '2026-03-20',
			'EUR', null, 1000, 190, 'Material', null, 'receipt',
			[
				['position_id' => $posPk->getId(), 'amount_minor' => 700],
				['position_id' => $posSk->getId(), 'amount_minor' => 300],
			],
			null, $uid);

		// Incoming grant rate → Einnahmen side.
		\OCP\Server::get(IncomingPaymentService::class)->create(
			$p->getId(), '2026-02-01', 2500000, 'ABRUF-1', 'rate 1', null, $uid);

		$svc = \OCP\Server::get(NachweisService::class);
		$p = \OCP\Server::get(\OCA\NachweisCheck\Service\ProjectService::class)->getOrFail($p->getId());
		$n = $svc->generate($p, 'final', '2026-01-01', '2026-12-31', $uid);

		self::assertTrue($svc->verify($n), 'manifest hash must re-verify');
		$payload = json_decode($n->getPayloadJson(), true);
		self::assertIsArray($payload);

		// Einnahmen: the grant rate is there
		self::assertCount(1, $payload['einnahmen']);
		self::assertSame(2500000, $payload['einnahmen'][0]['amount_minor']);

		// Ausgaben: receipt splits + personnel share mapped per plan position.
		// Personnel costs split EVENLY across the AP's mapped positions
		// (documented allocation rule): 9000 priced → 4500 per position.
		$ausgaben = array_column($payload['ausgaben'], null, 'code');
		self::assertSame(500000, $ausgaben['SK']['budget_minor']);
		self::assertSame(700 + 4500, $ausgaben['PK']['used_minor']); // split + share
		self::assertSame(300 + 4500, $ausgaben['SK']['used_minor']); // split + share

		// Belegliste carries ANBest-P 6.4 fields
		self::assertCount(1, $payload['belegliste']);
		$beleg = $payload['belegliste'][0];
		self::assertSame('2026-03-15', $beleg['invoice_date']);
		self::assertSame('2026-03-20', $beleg['payment_date']);
		self::assertFalse($beleg['has_attachment']);

		// Gaps: attachment missing + unpriced time entry — both named, none dropped
		$gapKinds = array_column($payload['fehlende_angaben'], 'type');
		self::assertContains('missing_attachment', $gapKinds);
		self::assertContains('unpriced_time', $gapKinds);

		// Immutability: correction requires a new version with reason
		try {
			$svc->generate($p, 'final', '2026-01-01', '2026-12-31', $uid, $n->getId(), '');
			self::fail('supersede without reason must refuse');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('reason', $e->getFields());
		}
		$n2 = $svc->generate($p, 'final', '2026-01-01', '2026-12-31', $uid, $n->getId(), 'Korrektur Zahlungsdatum');
		self::assertSame(2, $n2->getVersion());
		self::assertSame('superseded', $svc->getOrFail($p->getId(), $n->getId())->getState());
		self::assertTrue($svc->verify($n2));

		// Double-supersede refused
		try {
			$svc->generate($p, 'final', '2026-01-01', '2026-12-31', $uid, $n->getId(), 'again');
			self::fail('superseding a superseded version must refuse');
		} catch (ConflictException $e) {
			self::assertSame('ALREADY_SUPERSEDED', $e->getErrorCode());
		}
	}

	public function testZwischennachweisScopesPaymentsToReceivedYear(): void
	{
		$uid = '__nw_it_zw__';
		$p = $this->nwProject($uid);
		$ver = $this->nwActivatePlan($p, [
			['code' => 'PK', 'title' => 'Personal', 'kind' => 'personal', 'budget_minor' => 1],
		], $uid);
		$this->nwActivateProject($p, $uid);

		$payments = \OCP\Server::get(IncomingPaymentService::class);
		$payments->create($p->getId(), '2026-05-01', 1000, 'Y26', 'rate', null, $uid);
		$payments->create($p->getId(), '2027-05-01', 2000, 'Y27', 'rate', null, $uid);

		$svc = \OCP\Server::get(NachweisService::class);
		$p = \OCP\Server::get(\OCA\NachweisCheck\Service\ProjectService::class)->getOrFail($p->getId());
		// Zwischennachweis for year 2026 only reports the 2026 receipts (Nr. 6.1/6.6)
		$n = $svc->generate($p, 'zwischen', '2026-01-01', '2026-12-31', $uid);
		$payload = json_decode($n->getPayloadJson(), true);
		self::assertCount(1, $payload['einnahmen']);
		self::assertSame(1000, $payload['einnahmen'][0]['amount_minor']);
	}
}
