<?php

declare(strict_types=1);

/**
 * NFR scale probe: the export payload build over a large receipt set must
 * stay linear-ish and complete — every gap surfaces, nothing silently
 * dropped. Runs in the integration suite (real DB). Not a wall-clock gate
 * (hardware varies); it asserts correctness at scale and reports timings.
 */

namespace OCA\NachweisCheck\Tests\Integration;

use OCA\NachweisCheck\Db\Receipt;
use OCA\NachweisCheck\Db\ReceiptMapper;
use OCA\NachweisCheck\Db\ReceiptSplit;
use OCA\NachweisCheck\Db\ReceiptSplitMapper;
use OCA\NachweisCheck\Service\FinplanService;
use OCA\NachweisCheck\Service\NachweisService;
use Test\TestCase;

class ExportScaleTest extends TestCase
{
	use NwFixture;

	public function testPayloadBuildOverTwoThousandReceipts(): void
	{
		$this->nwInit();
		$p = $this->nwProject('nw_it_admin');
		$ver = $this->nwActivatePlan($p, [['code' => 'PK', 'title' => 'PK', 'kind' => 'personal', 'budget_minor' => 10000000]], 'nw_it_admin');

		$receipts = \OCP\Server::get(ReceiptMapper::class);
		$splits = \OCP\Server::get(ReceiptSplitMapper::class);
		$pos = \OCP\Server::get(FinplanService::class)->positions($ver)[0];

		$t0 = microtime(true);
		for ($i = 0; $i < 2000; $i++) {
			$r = new Receipt();
			$r->setProjectId($p->getId());
			$r->setVendor('Vendor ' . ($i % 40));
			$r->setInvoiceNo('INV-' . $i);
			$r->setInvoiceDate(new \DateTime('2026-03-01'));
			$r->setPaymentDate(new \DateTime('2026-03-05'));
			$r->setCurrency('EUR');
			$r->setTotalMinor(1000 + $i);
			$r->setTaxMinor(190);
			$r->setKind('receipt');
			$r->setState('open');
			$r->setCreatedBy('nw_it_admin');
			$r->setCreatedAt(new \DateTime());
			$r->setUpdatedAt(new \DateTime());
			$r = $receipts->insert($r);
			$s = new ReceiptSplit();
			$s->setReceiptId($r->getId());
			$s->setPositionId($pos->getId());
			$s->setAmountMinor(1000 + $i);
			$splits->insert($s);
		}
		$t1 = microtime(true);

		$payload = \OCP\Server::get(NachweisService::class)->buildPayload($p, 'final', '2026-01-01', '2026-12-31');
		$t2 = microtime(true);

		self::assertCount(2000, $payload['belegliste']);
		$expected = 0;
		for ($i = 0; $i < 2000; $i++) {
			$expected += 1000 + $i;
		}
		$used = array_sum(array_map(static fn($a) => $a['used_minor'], $payload['ausgaben']));
		self::assertSame($expected, $used, 'sum drift over 2k receipts — the exact-minor-units invariant broke at scale');
		// Every Beleg lacks an attachment + payment-date is set → 2000 attachment gaps, none silently dropped (GAC-7).
		$attachGaps = array_filter($payload['fehlende_angaben'], static fn($g) => $g['type'] === 'missing_attachment');
		self::assertCount(2000, $attachGaps);

		fwrite(STDERR, sprintf("\n[scale] insert 2k receipts+splits: %.2fs | buildPayload: %.2fs\n", $t1 - $t0, $t2 - $t1));
	}
}
