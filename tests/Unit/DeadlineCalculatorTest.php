<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit;

use OCA\NachweisCheck\Support\DeadlineCalculator;
use Test\TestCase;

class DeadlineCalculatorTest extends TestCase
{
	private const FLAGS = [
		'final_months' => 6, 'zwischen_enabled' => true,
		'zwischen_months' => 2, 'bew_end_warn_days' => 30,
	];

	public function testFinalAndWarningAndZwischen(): void
	{
		$out = DeadlineCalculator::forProject(1, '2026-01-01', '2027-06-30', self::FLAGS, []);
		$byKind = [];
		foreach ($out as $d) {
			$byKind[$d['kind']][] = $d;
		}
		// final: end of month 6 months after 2027-06-30 -> 2027-12-31
		$this->assertSame('2027-12-31', $byKind['nachweis_frist'][0]['due_date']);
		// warning 30d before end
		$this->assertSame('2027-05-31', $byKind['bew_end_warning'][0]['due_date']);
		// one zwischen for 2026 year boundary: due end of Feb 2027
		$this->assertCount(1, $byKind['zwischennachweis']);
		$this->assertSame('2027-02-28', $byKind['zwischennachweis'][0]['due_date']);
	}

	public function testDedupeKeysStable(): void
	{
		$a = DeadlineCalculator::forProject(7, '2026-01-01', '2026-12-31', self::FLAGS, []);
		$b = DeadlineCalculator::forProject(7, '2026-01-01', '2026-12-31', self::FLAGS, []);
		$this->assertSame(array_column($a, 'dedupe_key'), array_column($b, 'dedupe_key'));
	}

	public function testNoEndNoDeadlines(): void
	{
		$this->assertSame([], DeadlineCalculator::forProject(1, '2026-01-01', null, self::FLAGS, []));
	}

	public function testApEndsIncluded(): void
	{
		$out = DeadlineCalculator::forProject(1, '2026-01-01', '2026-12-31', self::FLAGS,
			[['id' => 9, 'end_date' => '2026-08-15']]);
		$kinds = array_column($out, 'kind');
		$this->assertContains('ap_end', $kinds);
	}

	public function testZwischenSkippedWhenYearEndEqualsBewEnd(): void
	{
		$out = DeadlineCalculator::forProject(1, '2026-01-01', '2026-12-31', self::FLAGS, []);
		$kinds = array_column($out, 'kind');
		$this->assertNotContains('zwischennachweis', $kinds);
		$this->assertContains('nachweis_frist', $kinds);
	}

}
