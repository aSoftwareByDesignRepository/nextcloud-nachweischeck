<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Exception\ValidationException;
use OCA\NachweisCheck\Support\SplitValidator;
use Test\TestCase;

class SplitValidatorTest extends TestCase
{
	public function testValidSplitsPass(): void
	{
		SplitValidator::validate(12000, [
			['position_id' => 1, 'amount_minor' => 7000],
			['position_id' => 2, 'amount_minor' => 5000],
		]);
		$this->assertTrue(true);
	}

	public function testSumMismatchRejected(): void
	{
		$this->expectException(ValidationException::class);
		SplitValidator::validate(12000, [
			['position_id' => 1, 'amount_minor' => 7000],
		]);
	}

	public function testZeroAndNegativeSplitsRejected(): void
	{
		$this->expectException(ValidationException::class);
		SplitValidator::validate(10000, [
			['position_id' => 1, 'amount_minor' => 0],
			['position_id' => 1, 'amount_minor' => 10000],
		]);
	}

	public function testNoSplitsRejected(): void
	{
		$this->expectException(ValidationException::class);
		SplitValidator::validate(10000, []);
	}

	public function testFloatAmountRejected(): void
	{
		$this->expectException(ValidationException::class);
		SplitValidator::validate(10000, [
			['position_id' => 1, 'amount_minor' => 2.9],
			['position_id' => 1, 'amount_minor' => 9997],
		]);
	}

	public function testNonPositiveTotalRejected(): void
	{
		$this->expectException(ValidationException::class);
		SplitValidator::validate(0, [['position_id' => 1, 'amount_minor' => 1]]);
	}
}
