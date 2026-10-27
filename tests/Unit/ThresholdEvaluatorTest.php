<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\ThresholdEvaluator;
use Test\TestCase;

class ThresholdEvaluatorTest extends TestCase
{
	private const FLAGS = ['einzelansatz_20pct' => true, 'festbetrag' => false];

	public function testOkWithinAndBlocked(): void
	{
		$this->assertSame('ok', ThresholdEvaluator::evaluate(10000, 10000, self::FLAGS));
		$this->assertSame('within_20', ThresholdEvaluator::evaluate(10000, 10001, self::FLAGS));
		$this->assertSame('within_20', ThresholdEvaluator::evaluate(10000, 12000, self::FLAGS));
		$this->assertSame('blocked_over', ThresholdEvaluator::evaluate(10000, 12001, self::FLAGS));
	}

	public function testFestbetragDisablesRule(): void
	{
		$flags = ['einzelansatz_20pct' => true, 'festbetrag' => true];
		$this->assertSame('not_applicable', ThresholdEvaluator::evaluate(100, 999999, $flags));
	}

	public function testRuleFlagOff(): void
	{
		$flags = ['einzelansatz_20pct' => false];
		$this->assertSame('not_applicable', ThresholdEvaluator::evaluate(100, 999999, $flags));
	}

	public function testZeroBudgetWithSpendIsBlocked(): void
	{
		$this->assertSame('blocked_over', ThresholdEvaluator::evaluate(0, 1, self::FLAGS));
		$this->assertSame('ok', ThresholdEvaluator::evaluate(0, 0, self::FLAGS));
	}
}
