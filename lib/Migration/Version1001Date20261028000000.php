<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Second schema step:
 *  - nw_receipt.salary_period (YYYY-MM) links a Gehaltsnachweis to the rate
 *    period it evidences (MH-14: auditors demand salary docs alongside
 *    timesheets, tied to the rate that produced the personnel cost).
 *  - nw_project.ics_token authenticates the per-project deadline ICS feed
 *    (I-08) without a session; rotatable by project admins.
 */

namespace OCA\NachweisCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1001Date20261028000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('nw_receipt')) {
			$t = $schema->getTable('nw_receipt');
			if (!$t->hasColumn('salary_period')) {
				$t->addColumn('salary_period', 'string', ['length' => 7, 'notnull' => false]);
			}
			if (!$t->hasIndex('nw_rcpt_salper_idx')) {
				$t->addIndex(['project_id', 'salary_period'], 'nw_rcpt_salper_idx');
			}
		}

		if ($schema->hasTable('nw_project')) {
			$t = $schema->getTable('nw_project');
			if (!$t->hasColumn('ics_token')) {
				$t->addColumn('ics_token', 'string', ['length' => 64, 'notnull' => false]);
			}
		}

		return $schema;
	}
}
