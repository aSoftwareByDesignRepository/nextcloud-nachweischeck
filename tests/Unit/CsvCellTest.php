<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\CsvCell;
use Test\TestCase;

class CsvCellTest extends TestCase
{
	/**
	 * @dataProvider formulaPayloads
	 */
	public function testFormulaInjectionPrefixed(string $payload): void
	{
		$out = CsvCell::escape($payload);
		$this->assertStringStartsWith("'", $out);
	}

	public static function formulaPayloads(): array
	{
		return [
			['=1+1'], ['+43'], ['-99'], ['@x'], ["\t=1"], ["\r=1"], ["\x0B=1"],
		];
	}

	public function testNormalValuesUntouched(): void
	{
		$this->assertSame('Bäcker GmbH', CsvCell::escape('Bäcker GmbH'));
		$this->assertSame('', CsvCell::escape(null));
		$this->assertSame('123', CsvCell::escape(123));
	}

	public function testDelimitedSafeStripsSeparators(): void
	{
		$this->assertSame('a b c', CsvCell::delimitedSafe("a|b\nc"));
		$this->assertSame('a b', CsvCell::delimitedSafe("a\rb"));
	}

	public function testBuildCsvQuotesAndEscapes(): void
	{
		$csv = CsvCell::buildCsv([['a;b', 'x'], ['=cmd', 'q']]);
		$lines = explode("\r\n", trim($csv));
		$this->assertSame('"a;b";x', $lines[0]);
		$this->assertSame("'=cmd;q", $lines[1]);
	}
}
