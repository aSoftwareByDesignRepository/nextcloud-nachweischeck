<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\GapCollector;
use Test\TestCase;

class GapCollectorTest extends TestCase
{
	public function testEveryIncompleteRecordListed(): void
	{
		$gaps = GapCollector::collect(
			[
				['id' => 1, 'vendor' => 'A', 'file_id' => null, 'invoice_no' => '', 'payment_date' => null],
				['id' => 2, 'vendor' => 'B', 'file_id' => 42, 'invoice_no' => 'R-1', 'payment_date' => '2026-02-01'],
			],
			[
				['id' => 5, 'uid' => 'u1', 'entry_date' => '2026-01-10', 'rate_minor' => null, 'amount_minor' => null],
				['id' => 6, 'uid' => 'u1', 'entry_date' => '2026-01-11', 'rate_minor' => 5000, 'amount_minor' => 500],
			],
		);
		$types = array_column($gaps, 'type');
		$this->assertContains('missing_attachment', $types);
		$this->assertContains('missing_invoice_no', $types);
		$this->assertContains('missing_payment_date', $types);
		$this->assertContains('unpriced_time', $types);
		// complete rows produce zero gaps
		$this->assertCount(4, $gaps); // 3 on receipt 1 + 1 on entry 5
	}
}
