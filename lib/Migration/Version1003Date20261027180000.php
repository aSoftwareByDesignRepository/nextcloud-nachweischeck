<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Fourth schema step — V2 feature surfaces per spec §14.3/§12/BQ-03:
 *  - nw_cofunder: co-financing funders. Positions reference a cofunder
 *    (NULL = lead funder on nw_project) so the zahlenmaessiger Nachweis can
 *    render per-funder plan sections (multi-funder co-financing).
 *  - nw_position.cofunder_id: optional FK-style reference (validated at
 *    the service layer; no DB FK per family convention).
 *  - nw_drawdown: Mittelabruf history (NH-09) — every call for funds with
 *    its state; received drawdowns link to nw_incoming_payment so the
 *    Einnahmenseite and the Abruf-Log can never disagree silently.
 *  - nw_claim: Zinsen/Rueckforderung tracking (NH-06, ANBest-P Nr. 8) —
 *    interest on unused funds and recovery demands with principal, rate
 *    (basis points), period and computed amount (act/360 convention).
 */

namespace OCA\NachweisCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1003Date20261027180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('nw_position')) {
			$t = $schema->getTable('nw_position');
			if (!$t->hasColumn('cofunder_id')) {
				$t->addColumn('cofunder_id', 'bigint', ['notnull' => false]);
				$t->addIndex(['cofunder_id'], 'nw_pos_cof_idx');
			}
		}

		if (!$schema->hasTable('nw_cofunder')) {
			$t = $schema->createTable('nw_cofunder');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('name', 'string', ['length' => 200, 'notnull' => true]);
			$t->addColumn('ref', 'string', ['length' => 120, 'notnull' => false]);
			$t->addColumn('contact', 'string', ['length' => 200, 'notnull' => false]);
			$t->addColumn('note', 'string', ['length' => 1000, 'notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_cof_pk');
			$t->addIndex(['project_id'], 'nw_cof_p_idx');
		}

		if (!$schema->hasTable('nw_drawdown')) {
			$t = $schema->createTable('nw_drawdown');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('call_ref', 'string', ['length' => 120, 'notnull' => false]);
			$t->addColumn('requested_date', 'date', ['notnull' => true]);
			$t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'requested']);
			$t->addColumn('payment_id', 'bigint', ['notnull' => false]);
			$t->addColumn('note', 'string', ['length' => 1000, 'notnull' => false]);
			$t->addColumn('external_ref', 'string', ['length' => 190, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_ddown_pk');
			$t->addIndex(['project_id'], 'nw_ddown_p_idx');
			$t->addUniqueIndex(['project_id', 'external_ref'], 'nw_ddown_ext_uidx');
		}

		if (!$schema->hasTable('nw_claim')) {
			$t = $schema->createTable('nw_claim');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('kind', 'string', ['length' => 16, 'notnull' => true]);
			$t->addColumn('title', 'string', ['length' => 200, 'notnull' => true]);
			$t->addColumn('principal_minor', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('rate_bp', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('period_from', 'date', ['notnull' => false]);
			$t->addColumn('period_to', 'date', ['notnull' => false]);
			$t->addColumn('amount_minor', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'open']);
			$t->addColumn('occurred_date', 'date', ['notnull' => true]);
			$t->addColumn('note', 'string', ['length' => 1000, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_claim_pk');
			$t->addIndex(['project_id'], 'nw_claim_p_idx');
		}

		return $schema;
	}
}
