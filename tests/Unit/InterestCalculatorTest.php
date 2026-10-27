<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\InterestCalculator;
use Test\TestCase;

class InterestCalculatorTest extends TestCase
{
	public function testDays360FullMonths(): void
	{
		self::assertSame(30, InterestCalculator::days360('2026-01-01', '2026-02-01'));
		self::assertSame(90, InterestCalculator::days360('2026-01-01', '2026-04-01'));
		self::assertSame(360, InterestCalculator::days360('2026-01-01', '2027-01-01'));
	}

	public function testDays360PartialMonth(): void
	{
		self::assertSame(44, InterestCalculator::days360('2026-01-01', '2026-02-15'));
	}

	public function testDays360ClampsMonthEnd(): void
	{
		// 31st counts as the 30th under 30E/360.
		// 30E/360: start 31st clamps to 30th → Jan30→Feb30 (30d) + 1 = 31
		self::assertSame(31, InterestCalculator::days360('2026-01-31', '2026-03-01'));
		self::assertSame(29, InterestCalculator::days360('2024-01-30', '2024-02-29'));
	}

	public function testDays360ZeroOrInverted(): void
	{
		self::assertSame(0, InterestCalculator::days360('2026-02-01', '2026-02-01'));
		self::assertSame(0, InterestCalculator::days360('2026-03-01', '2026-02-01'));
	}

	public function testDays360InvalidDates(): void
	{
		self::assertSame(0, InterestCalculator::days360('garbage', '2026-02-01'));
		self::assertSame(0, InterestCalculator::days360('2026-02-01', ''));
	}

	public function testInterestMinorBasic(): void
	{
		// 100 000 ct = 1 000,00 € at 500 bp (5 %) for 360 days → 5 000 ct.
		self::assertSame(5000, InterestCalculator::interestMinor(100000, 500, 360));
		// half year → 2 500 ct.
		self::assertSame(2500, InterestCalculator::interestMinor(100000, 500, 180));
		// one month → 416.67 ct → 417 ct half-up.
		self::assertSame(417, InterestCalculator::interestMinor(100000, 500, 30));
	}

	public function testInterestMinorRoundingHalfUp(): void
	{
		// 100 ct × 100 bp × 1 d = 0.0278 ct → 0
		self::assertSame(0, InterestCalculator::interestMinor(100, 100, 1));
		// 10 000 ct × 1 bp × 900 d = 2.5 ct → 3 (half-up)
		self::assertSame(3, InterestCalculator::interestMinor(10000, 1, 900));
	}

	public function testInterestMinorNonPositiveInputs(): void
	{
		self::assertSame(0, InterestCalculator::interestMinor(0, 500, 30));
		self::assertSame(0, InterestCalculator::interestMinor(-100, 500, 30));
		self::assertSame(0, InterestCalculator::interestMinor(100000, 0, 30));
		self::assertSame(0, InterestCalculator::interestMinor(100000, -5, 30));
		self::assertSame(0, InterestCalculator::interestMinor(100000, 500, 0));
	}
}
