<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Safety net when migrations were marked complete without creating every
 * table (partial install, restored backup, manual DB edits). Runs on fresh
 * install and on every upgrade via info.xml repair-steps.
 */

namespace OCA\NachweisCheck\Repair;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Server;

final class EnsureNachweisCheckSchema implements IRepairStep
{
	public const APP_ID = 'nachweischeck';

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IAppConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure NachweisCheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteKey(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missingBefore = $this->missingTables();
		if ($missingBefore === []) {
			$output->info('NachweisCheck: all ' . count(SchemaTableCatalog::EXPECTED_TABLES) . ' tables are present.');
			return;
		}

		$output->info(sprintf(
			'NachweisCheck: %d table(s) missing (%s); running pending migrations.',
			count($missingBefore),
			implode(', ', $missingBefore),
		));

		$migrationService = new MigrationService(
			self::APP_ID,
			Server::get(Connection::class),
		);
		$migrationService->migrate('latest', false);

		$missingAfter = $this->missingTables();
		if ($missingAfter === []) {
			$output->info('NachweisCheck: schema repair completed; all tables are now present.');
			return;
		}

		throw new \RuntimeException(sprintf(
			'NachweisCheck schema is still incomplete after migrate("latest"). Missing: %s. '
			. 'Run `occ upgrade` or re-enable the app and check the log.',
			implode(', ', $missingAfter),
		));
	}

	/** @return list<string> */
	private function missingTables(): array
	{
		$missing = [];
		foreach (SchemaTableCatalog::EXPECTED_TABLES as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}
