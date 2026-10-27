<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Third schema step: promote external_ref idempotency from a checked hint
 * to a hard unique key on both write-side tables (I-03).
 *
 * Rationale: the service-layer findByExternalRef() pre-check is a
 * check-then-insert race — two concurrent imports/bookings carrying the
 * same external_ref could both pass it. The unique index is the only
 * correct enforcement; the services convert a violation into the
 * documented ValidationException (or an idempotent skip during CSV
 * import). NULL external_refs do not collide (MariaDB + PostgreSQL both
 * treat NULLs as distinct).
 */

namespace OCA\NachweisCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1002Date20261027140000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('nw_time_entry')) {
			$t = $schema->getTable('nw_time_entry');
			if ($t->hasIndex('nw_time_ext_idx')) {
				$t->dropIndex('nw_time_ext_idx');
			}
			if (!$t->hasIndex('nw_time_ext_uidx')) {
				$t->addUniqueIndex(['project_id', 'external_ref'], 'nw_time_ext_uidx');
			}
		}

		if ($schema->hasTable('nw_receipt')) {
			$t = $schema->getTable('nw_receipt');
			if ($t->hasIndex('nw_rcpt_ext_idx')) {
				$t->dropIndex('nw_rcpt_ext_idx');
			}
			if (!$t->hasIndex('nw_rcpt_ext_uidx')) {
				$t->addUniqueIndex(['project_id', 'external_ref'], 'nw_rcpt_ext_uidx');
			}
		}

		return $schema;
	}
}
