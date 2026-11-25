<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Support;

use OCA\NachweisCheck\Support\OdsBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Contract for the ODS office:generator meta: the default must stay a
 * brand token, never prose or a sibling app name (a copy sweep once left
 * a sentence-shaped default that would have shipped into meta.xml).
 */
final class OdsBuilderTest extends TestCase {
	private function metaXml(string $pkg): string {
		$tmp = tempnam(sys_get_temp_dir(), 'ods-test-');
		self::assertNotFalse($tmp);
		try {
			self::assertNotFalse(file_put_contents($tmp, $pkg));
			$zip = new \ZipArchive();
			self::assertTrue($zip->open($tmp));
			try {
				$meta = $zip->getFromName('meta.xml');
				self::assertNotFalse($meta, 'meta.xml missing from ODS package');
				return $meta;
			} finally {
				$zip->close();
			}
		} finally {
			@unlink($tmp);
		}
	}

	public function testDefaultGeneratorIsAppBrandToken(): void {
		$meta = $this->metaXml((new OdsBuilder())->build([
			['name' => 'Sheet', 'columns' => ['A'], 'rows' => [['x']]],
		]));
		self::assertStringContainsString('<meta:generator>NachweisCheck</meta:generator>', $meta);
	}

	public function testExplicitGeneratorPassesThrough(): void {
		$meta = $this->metaXml((new OdsBuilder())->build([
			['name' => 'Sheet', 'columns' => ['A'], 'rows' => [['x']]],
		], 'NachweisCheck Antrag'));
		self::assertStringContainsString('<meta:generator>NachweisCheck Antrag</meta:generator>', $meta);
	}
}
