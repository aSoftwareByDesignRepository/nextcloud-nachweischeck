<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Initial schema for NachweisCheck. All money columns are integer minor units
 * (bigint, cents) — never float. Rate capture on time entries is frozen at
 * booking time. Finalized Nachweise are immutable; corrections create a new
 * version row (enforced in the service layer, unique key here).
 */

namespace OCA\NachweisCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261027120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('nw_project')) {
			$t = $schema->createTable('nw_project');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', 'string', ['length' => 200, 'notnull' => true]);
			$t->addColumn('funder_name', 'string', ['length' => 200, 'notnull' => false]);
			$t->addColumn('funder_ref', 'string', ['length' => 120, 'notnull' => false]);
			$t->addColumn('bew_start', 'date', ['notnull' => false]);
			$t->addColumn('bew_end', 'date', ['notnull' => false]);
			$t->addColumn('template_key', 'string', ['length' => 64, 'notnull' => true, 'default' => 'anbest-p']);
			$t->addColumn('financing_mode', 'string', ['length' => 16, 'notnull' => true, 'default' => 'ausgaben']);
			$t->addColumn('retention_years', 'smallint', ['notnull' => true, 'default' => 10]);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_proj_pk');
			$t->addIndex(['uid'], 'nw_proj_uid_idx');
			$t->addIndex(['state'], 'nw_proj_state_idx');
		}

		if (!$schema->hasTable('nw_project_member')) {
			$t = $schema->createTable('nw_project_member');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('role', 'string', ['length' => 16, 'notnull' => true]);
			$t->addColumn('display_name', 'string', ['length' => 200, 'notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_pmember_pk');
			$t->addUniqueIndex(['project_id', 'uid'], 'nw_pmember_uidx');
			$t->addIndex(['uid'], 'nw_pmember_uid_idx');
		}

		if (!$schema->hasTable('nw_finplan_ver')) {
			$t = $schema->createTable('nw_finplan_ver');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('version', 'integer', ['notnull' => true]);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('base_version', 'integer', ['notnull' => false]);
			$t->addColumn('note', 'string', ['length' => 1000, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('activated_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'nw_fpver_pk');
			$t->addUniqueIndex(['project_id', 'version'], 'nw_fpver_uidx');
			$t->addIndex(['project_id', 'state'], 'nw_fpver_st_idx');
		}

		if (!$schema->hasTable('nw_position')) {
			$t = $schema->createTable('nw_position');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('plan_ver_id', 'bigint', ['notnull' => true]);
			$t->addColumn('code', 'string', ['length' => 40, 'notnull' => true]);
			$t->addColumn('title', 'string', ['length' => 200, 'notnull' => true]);
			$t->addColumn('kind', 'string', ['length' => 16, 'notnull' => true, 'default' => 'sach']);
			$t->addColumn('budget_minor', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('sort', 'integer', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'nw_pos_pk');
			$t->addUniqueIndex(['plan_ver_id', 'code'], 'nw_pos_code_uidx');
			$t->addIndex(['plan_ver_id', 'sort'], 'nw_pos_sort_idx');
		}

		if (!$schema->hasTable('nw_workpackage')) {
			$t = $schema->createTable('nw_workpackage');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('code', 'string', ['length' => 40, 'notnull' => true]);
			$t->addColumn('title', 'string', ['length' => 200, 'notnull' => true]);
			$t->addColumn('owner_uid', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('start_date', 'date', ['notnull' => false]);
			$t->addColumn('end_date', 'date', ['notnull' => false]);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'active']);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_wp_pk');
			$t->addUniqueIndex(['project_id', 'code'], 'nw_wp_code_uidx');
			$t->addIndex(['project_id', 'state'], 'nw_wp_state_idx');
		}

		if (!$schema->hasTable('nw_ap_position')) {
			$t = $schema->createTable('nw_ap_position');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('ap_id', 'bigint', ['notnull' => true]);
			$t->addColumn('position_id', 'bigint', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_appos_pk');
			$t->addUniqueIndex(['ap_id', 'position_id'], 'nw_appos_uidx');
			$t->addIndex(['position_id'], 'nw_appos_pos_idx');
		}

		if (!$schema->hasTable('nw_rate_history')) {
			$t = $schema->createTable('nw_rate_history');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('role_key', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('rate_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('valid_from', 'date', ['notnull' => true]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_rate_pk');
			$t->addIndex(['project_id', 'uid', 'valid_from'], 'nw_rate_lookup_idx');
		}

		if (!$schema->hasTable('nw_time_entry')) {
			$t = $schema->createTable('nw_time_entry');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('ap_id', 'bigint', ['notnull' => true]);
			$t->addColumn('uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('entry_date', 'date', ['notnull' => true]);
			$t->addColumn('minutes', 'integer', ['notnull' => true]);
			$t->addColumn('note', 'string', ['length' => 1000, 'notnull' => false]);
			$t->addColumn('rate_minor', 'bigint', ['notnull' => false]);
			$t->addColumn('amount_minor', 'bigint', ['notnull' => false]);
			$t->addColumn('external_ref', 'string', ['length' => 190, 'notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_time_pk');
			$t->addIndex(['project_id', 'entry_date'], 'nw_time_pdate_idx');
			$t->addIndex(['ap_id'], 'nw_time_ap_idx');
			$t->addIndex(['uid', 'entry_date'], 'nw_time_udate_idx');
			$t->addIndex(['project_id', 'external_ref'], 'nw_time_ext_idx');
		}

		if (!$schema->hasTable('nw_receipt')) {
			$t = $schema->createTable('nw_receipt');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('vendor', 'string', ['length' => 200, 'notnull' => true]);
			$t->addColumn('invoice_no', 'string', ['length' => 190, 'notnull' => false]);
			$t->addColumn('invoice_date', 'date', ['notnull' => true]);
			$t->addColumn('payment_date', 'date', ['notnull' => false]);
			$t->addColumn('currency', 'string', ['length' => 3, 'notnull' => true, 'default' => 'EUR']);
			$t->addColumn('fx_rate_milli', 'bigint', ['notnull' => false]);
			$t->addColumn('total_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('tax_minor', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('purpose', 'string', ['length' => 1000, 'notnull' => false]);
			$t->addColumn('file_id', 'bigint', ['notnull' => false]);
			$t->addColumn('kind', 'string', ['length' => 16, 'notnull' => true, 'default' => 'receipt']);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'open']);
			$t->addColumn('dup_flag', 'boolean', ['notnull' => false, 'default' => false]);
			$t->addColumn('external_ref', 'string', ['length' => 190, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_rcpt_pk');
			$t->addIndex(['project_id', 'invoice_date'], 'nw_rcpt_pdate_idx');
			$t->addIndex(['project_id', 'external_ref'], 'nw_rcpt_ext_idx');
			$t->addIndex(['file_id'], 'nw_rcpt_file_idx');
		}

		if (!$schema->hasTable('nw_receipt_split')) {
			$t = $schema->createTable('nw_receipt_split');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('receipt_id', 'bigint', ['notnull' => true]);
			$t->addColumn('position_id', 'bigint', ['notnull' => true]);
			$t->addColumn('ap_id', 'bigint', ['notnull' => false]);
			$t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('note', 'string', ['length' => 1000, 'notnull' => false]);
			$t->setPrimaryKey(['id'], 'nw_rsplit_pk');
			$t->addIndex(['receipt_id'], 'nw_rsplit_rcpt_idx');
			$t->addIndex(['position_id'], 'nw_rsplit_pos_idx');
		}

		if (!$schema->hasTable('nw_workreport')) {
			$t = $schema->createTable('nw_workreport');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('ap_id', 'bigint', ['notnull' => true]);
			$t->addColumn('period', 'string', ['length' => 7, 'notnull' => true]);
			$t->addColumn('summary', 'text', ['notnull' => false]);
			$t->addColumn('outcome', 'text', ['notnull' => false]);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('author_uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_wrep_pk');
			$t->addUniqueIndex(['ap_id', 'period'], 'nw_wrep_uidx');
		}

		if (!$schema->hasTable('nw_deviation')) {
			$t = $schema->createTable('nw_deviation');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('scope', 'string', ['length' => 8, 'notnull' => true]);
			$t->addColumn('ref_id', 'bigint', ['notnull' => true]);
			$t->addColumn('kind', 'string', ['length' => 16, 'notnull' => true]);
			$t->addColumn('reason_text', 'text', ['notnull' => true]);
			$t->addColumn('uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_dev_pk');
			$t->addIndex(['project_id'], 'nw_dev_proj_idx');
			$t->addIndex(['scope', 'ref_id'], 'nw_dev_ref_idx');
		}

		if (!$schema->hasTable('nw_reallocation')) {
			$t = $schema->createTable('nw_reallocation');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('plan_ver_id', 'bigint', ['notnull' => true]);
			$t->addColumn('from_position_id', 'bigint', ['notnull' => true]);
			$t->addColumn('to_position_id', 'bigint', ['notnull' => true]);
			$t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('reason_text', 'text', ['notnull' => true]);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$t->addColumn('approved_by', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('decided_at', 'datetime', ['notnull' => false]);
			$t->addColumn('uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_realloc_pk');
			$t->addIndex(['project_id', 'state'], 'nw_realloc_st_idx');
		}

		if (!$schema->hasTable('nw_incoming_payment')) {
			$t = $schema->createTable('nw_incoming_payment');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('received_date', 'date', ['notnull' => true]);
			$t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('call_ref', 'string', ['length' => 190, 'notnull' => false]);
			$t->addColumn('year_received', 'integer', ['notnull' => true]);
			$t->addColumn('note', 'string', ['length' => 1000, 'notnull' => false]);
			$t->addColumn('external_ref', 'string', ['length' => 190, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_inpay_pk');
			$t->addIndex(['project_id', 'received_date'], 'nw_inpay_pd_idx');
			$t->addIndex(['project_id', 'year_received'], 'nw_inpay_py_idx');
		}

		if (!$schema->hasTable('nw_carryover')) {
			$t = $schema->createTable('nw_carryover');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('from_year', 'integer', ['notnull' => true]);
			$t->addColumn('to_year', 'integer', ['notnull' => true]);
			$t->addColumn('amount_minor', 'bigint', ['notnull' => true]);
			$t->addColumn('doc_ref', 'string', ['length' => 190, 'notnull' => false]);
			$t->addColumn('decided_by', 'string', ['length' => 200, 'notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_carry_pk');
			$t->addUniqueIndex(['project_id', 'from_year'], 'nw_carry_uidx');
		}

		if (!$schema->hasTable('nw_timesheet')) {
			$t = $schema->createTable('nw_timesheet');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('ap_id', 'bigint', ['notnull' => false]);
			$t->addColumn('uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('period', 'string', ['length' => 7, 'notnull' => true]);
			$t->addColumn('minutes_total', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('submitted_at', 'datetime', ['notnull' => false]);
			$t->addColumn('signed_by', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('signed_at', 'datetime', ['notnull' => false]);
			$t->addColumn('reject_reason', 'string', ['length' => 1000, 'notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_tsheet_pk');
			$t->addIndex(['project_id', 'uid', 'period'], 'nw_tsheet_lu_idx');
			$t->addIndex(['project_id', 'state'], 'nw_tsheet_st_idx');
		}

		if (!$schema->hasTable('nw_nachweis')) {
			$t = $schema->createTable('nw_nachweis');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('period_from', 'date', ['notnull' => true]);
			$t->addColumn('period_to', 'date', ['notnull' => true]);
			$t->addColumn('kind', 'string', ['length' => 16, 'notnull' => true]);
			$t->addColumn('version', 'integer', ['notnull' => true]);
			$t->addColumn('manifest_hash', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('payload_json', 'text', ['notnull' => true]);
			$t->addColumn('state', 'string', ['length' => 16, 'notnull' => true, 'default' => 'final']);
			$t->addColumn('supersedes_id', 'bigint', ['notnull' => false]);
			$t->addColumn('reason', 'string', ['length' => 1000, 'notnull' => false]);
			$t->addColumn('generated_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('generated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_nw_pk');
			$t->addUniqueIndex(['project_id', 'version'], 'nw_nw_ver_uidx');
			$t->addIndex(['project_id', 'kind'], 'nw_nw_kind_idx');
		}

		if (!$schema->hasTable('nw_audit_event')) {
			$t = $schema->createTable('nw_audit_event');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => false]);
			$t->addColumn('entity', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('entity_id', 'bigint', ['notnull' => true]);
			$t->addColumn('action', 'string', ['length' => 32, 'notnull' => true]);
			$t->addColumn('actor', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('before_json', 'text', ['notnull' => false]);
			$t->addColumn('after_json', 'text', ['notnull' => false]);
			$t->addColumn('ts', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_audit_pk');
			$t->addIndex(['entity', 'entity_id'], 'nw_audit_ent_idx');
			$t->addIndex(['project_id', 'ts'], 'nw_audit_pts_idx');
		}

		if (!$schema->hasTable('nw_template')) {
			$t = $schema->createTable('nw_template');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('tpl_key', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', 'string', ['length' => 200, 'notnull' => true]);
			$t->addColumn('rule_flags', 'text', ['notnull' => false]);
			$t->addColumn('skeleton', 'text', ['notnull' => false]);
			$t->addColumn('source', 'string', ['length' => 16, 'notnull' => true, 'default' => 'installed']);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_tpl_pk');
			$t->addUniqueIndex(['tpl_key'], 'nw_tpl_key_uidx');
		}

		if (!$schema->hasTable('nw_auditor_grant')) {
			$t = $schema->createTable('nw_auditor_grant');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('nc_uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', 'string', ['length' => 200, 'notnull' => false]);
			$t->addColumn('firm', 'string', ['length' => 200, 'notnull' => false]);
			$t->addColumn('scope_json', 'text', ['notnull' => false]);
			$t->addColumn('valid_from', 'date', ['notnull' => true]);
			$t->addColumn('valid_to', 'date', ['notnull' => true]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_agrant_pk');
			$t->addIndex(['nc_uid'], 'nw_agrant_uid_idx');
			$t->addIndex(['project_id'], 'nw_agrant_p_idx');
		}

		if (!$schema->hasTable('nw_auditor_read')) {
			$t = $schema->createTable('nw_auditor_read');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('grant_id', 'bigint', ['notnull' => true]);
			$t->addColumn('actor', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('route', 'string', ['length' => 190, 'notnull' => false]);
			$t->addColumn('entity', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('entity_id', 'bigint', ['notnull' => false]);
			$t->addColumn('ts', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_aread_pk');
			$t->addIndex(['grant_id'], 'nw_aread_g_idx');
		}

		if (!$schema->hasTable('nw_deadline')) {
			$t = $schema->createTable('nw_deadline');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('kind', 'string', ['length' => 32, 'notnull' => true]);
			$t->addColumn('due_date', 'date', ['notnull' => true]);
			$t->addColumn('dedupe_key', 'string', ['length' => 190, 'notnull' => true]);
			$t->addColumn('notified_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_dline_pk');
			$t->addUniqueIndex(['dedupe_key'], 'nw_dline_dk_uidx');
			$t->addIndex(['due_date'], 'nw_dline_due_idx');
		}

		if (!$schema->hasTable('nw_import_batch')) {
			$t = $schema->createTable('nw_import_batch');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('project_id', 'bigint', ['notnull' => true]);
			$t->addColumn('kind', 'string', ['length' => 16, 'notnull' => true]);
			$t->addColumn('preset', 'string', ['length' => 64, 'notnull' => true, 'default' => 'generic']);
			$t->addColumn('filename', 'string', ['length' => 255, 'notnull' => false]);
			$t->addColumn('rows_ok', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('rows_failed', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_imp_pk');
			$t->addIndex(['project_id'], 'nw_imp_p_idx');
		}

		if (!$schema->hasTable('nw_mobile_seat')) {
			$t = $schema->createTable('nw_mobile_seat');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', 'string', ['length' => 64, 'notnull' => true]);
			$t->addColumn('assigned_at', 'datetime', ['notnull' => true]);
			$t->addColumn('assigned_by', 'string', ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'nw_mseat_pk');
			$t->addUniqueIndex(['uid'], 'nw_mseat_uidx');
		}

		return $schema;
	}
}
