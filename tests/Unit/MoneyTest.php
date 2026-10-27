<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\Money;
use Test\TestCase;

class MoneyTest extends TestCase
{
	public function testMulRateRoundHalfUp(): void
	{
		// 45min * 5000ct/h = 3750ct
		$this->assertSame(3750, Money::mulRate(45, 5000));
		// 1min * 5000ct/h = 83.33 -> 83
		$this->assertSame(83, Money::mulRate(1, 5000));
		// rounding boundary: 3min * 1000ct/h = 50ct exactly
		$this->assertSame(50, Money::mulRate(3, 1000));
	}

	public function testMulRateRejectsNegative(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		Money::mulRate(-1, 1000);
	}

	public function testFormatMinor(): void
	{
		$this->assertSame('1.234,56', Money::formatMinor(123456));
		$this->assertSame('0,05', Money::formatMinor(5));
		$this->assertSame('-10,00', Money::formatMinor(-1000));
		$this->assertSame('0,00', Money::formatMinor(0));
	}

	public function testRatioMilli(): void
	{
		$this->assertSame(1000, Money::ratioMilli(100, 100));
		$this->assertSame(1200, Money::ratioMilli(120, 100));
		$this->assertSame(PHP_INT_MAX, Money::ratioMilli(1, 0));
		$this->assertSame(0, Money::ratioMilli(0, 0));
		$this->assertSame(0, Money::ratioMilli(-5, 100));
		// huge values: no float, no overflow
		$this->assertSame(500, Money::ratioMilli(9_000_000_000_000_000, 18_000_000_000_000_000));
	}

	public function testApplyFx(): void
	{
		// 10000ct / 1.080 = 9259.26 -> 9259
		$this->assertSame(9259, Money::applyFx(10000, 1080));
	}

	public function testMulRateRoundsHalfUpAtHalfCent(): void
	{
		// 3min * 1010ct/h = 50.5ct -> 51 (round-half-up, not truncate)
		$this->assertSame(51, Money::mulRate(3, 1010));
		// 1min * 30ct/h = 0.5 -> 1
		$this->assertSame(1, Money::mulRate(1, 30));
	}

}
