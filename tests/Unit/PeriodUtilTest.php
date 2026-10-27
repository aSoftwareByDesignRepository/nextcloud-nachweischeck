<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\PeriodUtil;
use Test\TestCase;

class PeriodUtilTest extends TestCase
{
	public function testValidYmd(): void
	{
		$this->assertTrue(PeriodUtil::isValidYmd('2026-02-28'));
		$this->assertFalse(PeriodUtil::isValidYmd('2026-02-30'));
		$this->assertFalse(PeriodUtil::isValidYmd('2026-13-01'));
		$this->assertFalse(PeriodUtil::isValidYmd('15.01.2026'));
	}

	public function testPeriod(): void
	{
		$this->assertTrue(PeriodUtil::validPeriod('2026-02'));
		$this->assertFalse(PeriodUtil::validPeriod('2026-13'));
		$this->assertSame('2026-02-01', PeriodUtil::periodFirstDay('2026-02'));
		$this->assertSame('2026-02-28', PeriodUtil::periodLastDay('2026-02'));
		$this->assertSame('2024-02-29', PeriodUtil::periodLastDay('2024-02'));
	}
}
