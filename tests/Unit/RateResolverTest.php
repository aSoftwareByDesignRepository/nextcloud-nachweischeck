<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\RateResolver;
use Test\TestCase;

class RateResolverTest extends TestCase
{
	public function testLatestEffectiveRateWins(): void
	{
		$history = [
			['valid_from' => '2026-01-01', 'rate_minor' => 5000],
			['valid_from' => '2026-06-01', 'rate_minor' => 6000],
		];
		$this->assertSame(5000, RateResolver::resolve($history, '2026-03-15'));
		$this->assertSame(6000, RateResolver::resolve($history, '2026-06-01'));
		$this->assertSame(6000, RateResolver::resolve($history, '2026-12-31'));
	}

	public function testNoApplicableRateReturnsNull(): void
	{
		$this->assertNull(RateResolver::resolve([], '2026-01-01'));
		$this->assertNull(RateResolver::resolve(
			[['valid_from' => '2026-06-01', 'rate_minor' => 5000]], '2026-01-01'));
	}

	public function testDuplicateValidFromLatestRowWins(): void
	{
		// two rows same valid_from — the later-listed (newest insert) wins
		$history = [
			['valid_from' => '2026-01-01', 'rate_minor' => 5000],
			['valid_from' => '2026-01-01', 'rate_minor' => 5500],
		];
		$this->assertSame(5500, RateResolver::resolve($history, '2026-02-01'));
	}

}
