<?php

declare(strict_types=1);

/**
 * PII-in-logs gate (threat model row "PII in Logs"): audit before/after
 * payloads must never carry salary-adjacent keys — rates, amounts tied to
 * a person, free-text reasons. This test scans the audited call sites in
 * lib/ for forbidden keys inside ->record(...) argument arrays. It is a
 * static gate, deliberately cheap: a new key added here breaks CI, not a
 * salary boundary.
 */

namespace OCA\NachweisCheck\Tests\Unit;

use Test\TestCase;

class AuditPiiGateTest extends TestCase
{
	/** Person-linked keys banned from every audit payload (salary/free text). */
	private const FORBIDDEN_KEYS = [
		'rate_minor', 'salary', 'salary_period', 'reason_text', 'note',
		'hourly_rate', 'wage',
	];

	/**
	 * Person-linked entities where even amount_minor is salary-derived —
	 * on payments/claims/carryovers etc. the amount IS the fiscal fact and
	 * belongs in the trail.
	 */
	private const PERSON_ENTITIES = ['time_entry', 'rate_history'];

	public function testAuditPayloadsCarryNoPiiKeys(): void
	{
		$root = dirname(__DIR__, 2) . '/lib';
		$hits = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
			if (!str_ends_with($file->getPathname(), '.php')) {
				continue;
			}
			$src = file_get_contents($file->getPathname());
			// Find every ->record( ... ) call and inspect the array args.
			foreach (preg_split('/->record\(/', $src) as $i => $chunk) {
				if ($i === 0) {
					continue;
				}
				$args = substr($chunk, 0, (int)strpos($chunk, ');') + 200);
				$keys = self::FORBIDDEN_KEYS;
				foreach (self::PERSON_ENTITIES as $entity) {
					if (str_contains($args, "'" . $entity . "'")) {
						$keys[] = 'amount_minor';
						break;
					}
				}
				foreach ($keys as $key) {
					if (preg_match('/[\'"]' . $key . '[\'"]/', $args)) {
						$hits[] = $file->getFilename() . " contains audited key '$key'";
					}
				}
			}
		}
		self::assertSame([], $hits, implode("\n", array_unique($hits)));
	}

	public function testActivitySubjectMapWhitelisted(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/lib/Activity/ActivityService.php');
		// Param builders must never mention these keys — belt & suspenders
		// for the feed surface (members see what their role allows).
		foreach (['reason_text', 'rate_minor', 'salary', 'note'] as $bad) {
			self::assertStringNotContainsString("'$bad'", $src);
		}
	}
}
