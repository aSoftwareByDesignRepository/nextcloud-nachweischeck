<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after nachweischeck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=nachweischeck
 */
namespace OCA\NachweisCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'nw_ap_position',
		'nw_audit_event',
		'nw_auditor_grant',
		'nw_auditor_read',
		'nw_carryover',
		'nw_claim',
		'nw_cofunder',
		'nw_deadline',
		'nw_deviation',
		'nw_drawdown',
		'nw_finplan_ver',
		'nw_import_batch',
		'nw_incoming_payment',
		'nw_mobile_seat',
		'nw_nachweis',
		'nw_position',
		'nw_project',
		'nw_project_member',
		'nw_rate_history',
		'nw_reallocation',
		'nw_receipt',
		'nw_receipt_split',
		'nw_template',
		'nw_time_entry',
		'nw_timesheet',
		'nw_workpackage',
		'nw_workreport',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [

	];
}
