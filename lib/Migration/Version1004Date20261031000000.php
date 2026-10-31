<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Fifth schema step — NWC2 license state (I-07 / ADR-08):
 *  - nw_license_state: singleton row holding the applied NWC2 license
 *    (customer, validity window, mobile-seat entitlement and the raw
 *    payload/signature pair so the mobile bootstrap can re-emit the
 *    signed envelope for official companion clients).
 */

namespace OCA\NachweisCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1004Date20261031000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('nw_license_state')) {
			$t = $schema->createTable('nw_license_state');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('issued_at', 'string', ['length' => 10, 'notnull' => true]);
			$t->addColumn('valid_until', 'string', ['length' => 10, 'notnull' => true]);
			$t->addColumn('mobile_seats', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('payload_b64', 'text', ['notnull' => true]);
			$t->addColumn('signature_b64', 'text', ['notnull' => true]);
			$t->addColumn('applied_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('applied_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_lic_pk');
		}

		return $schema;
	}
}
