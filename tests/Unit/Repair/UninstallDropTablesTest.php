<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Repair;

use OCA\NachweisCheck\Repair\SchemaTableCatalog;
use OCA\NachweisCheck\Repair\UninstallDropTables;
use PHPUnit\Framework\TestCase;

final class UninstallDropTablesTest extends TestCase
{
	public function testExpectedTablesAreSubsetOfDropList(): void
	{
		foreach (SchemaTableCatalog::EXPECTED_TABLES as $table) {
			self::assertContains($table, UninstallDropTables::TABLES,
				"expected table {$table} missing from uninstall drop list");
		}
	}

	public function testDropListCoversAllMigrationTables(): void
	{
		$migration = file_get_contents(
			dirname(__DIR__, 3) . '/lib/Migration/Version1000Date20261027120000.php');
		preg_match_all("/createTable\\('([^']+)'\\)/", $migration, $m);
		self::assertNotEmpty($m[1]);
		foreach ($m[1] as $table) {
			self::assertContains($table, UninstallDropTables::TABLES,
				"migration table {$table} missing from uninstall drop list");
		}
	}

	public function testRepairStepNameIsDescriptive(): void
	{
		$step = new UninstallDropTables(
			$this->createMock(\OCP\IDBConnection::class),
			$this->createMock(\OCP\IConfig::class),
			$this->createMock(\OCP\Files\IRootFolder::class),
		);
		self::assertStringContainsString('nachweischeck', strtolower($step->getName()));
		self::assertStringContainsString('uninstall', strtolower($step->getName()));
	}
}
