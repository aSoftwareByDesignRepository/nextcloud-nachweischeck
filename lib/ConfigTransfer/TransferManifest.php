<?php

declare(strict_types=1);

/**
 * NachweisCheck transfer manifest — every appconfig key and every
 * nw_* table classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * The 'transfer' settings section lives in PageController::settingsSection()
 * — sections are enumerated by Support\SettingsCatalog::SECTIONS +
 * Chrome::sectionLabel()/icon().
 */

namespace OCA\NachweisCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — written atomically through
			// AccessDoorService::saveConfig (never raw setValueString; the
			// group leg mirrors into the NC platform restriction via
			// enableAppForGroups — a REAL platform mirror, NN-02 / azc #46) ──
			'nw_access_mode' => ['class' => 'mirrored', 'security' => true, 'section' => 'access'],
			'nw_access_users' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// Virtual mirrored key: the group allowlist exists ONLY in the
			// platform restriction (getAppRestriction) — this app keeps no
			// appconfig snapshot on purpose (api-security §8). It is never
			// exported (absent from appconfig) but a file that carries it is
			// applied through writeMirrored → saveConfig → enableAppForGroups.
			'nw_access_groups' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'nw_admin_uids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// ── license override — an explicit local admin decision bound to
			// THIS instance's licensing posture ('pro'|'free'|'' = auto from
			// the vendor-signed nw_license_state row). Never transferred.
			'nw_license_mode' => ['class' => 'env', 'section' => 'license'],
			// ── internal bookkeeping — never transferred ──
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'nw_gap_ping_<projectId>'  GapReminderJob monthly dedupe stamp
			//   'nw_export_'               tempnam() prefix, not appconfig
			//   'nachweischeck-upgrade-backup'  ILockingProvider lock name
			//   'notify_deadlines'         IUserConfig per-user pref, not appconfig
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ project root + funder templates ══
			// ics_token is a bearer credential for the public calendar feed —
			// redacted on export, NULL on insert, untouched on update
			// (secrets/pairing never travel plain).
			// (uid, name) alone is NOT unique in practice — same-named
			// projects recur (test/E2E data proved it); created_at
			// distinguishes genuinely distinct projects, so distinct rows
			// insert instead of collapsing onto one natural key.
			'nw_project' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['uid', 'name', 'created_at'],
				'uid_ref' => ['uid'],
				'secret_columns' => ['ics_token']],
			// template_key on nw_project resolves by tpl_key (a stable string
			// key, no id remap needed) — builtin templates always exist.
			'nw_template' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['tpl_key']],
			'nw_project_member' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'uid'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project'],
				'uid_ref' => ['uid']],

			// ══ financial plan structure ══
			'nw_finplan_ver' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'version'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project']],
			'nw_cofunder' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'name'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project']],
			'nw_position' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['plan_ver_id', 'code'],
				'fk' => ['plan_ver_id' => 'nw_finplan_ver', 'cofunder_id' => 'nw_cofunder'],
				'depends_on' => ['nw_finplan_ver', 'nw_cofunder']],
			'nw_workpackage' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'code'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project'],
				'uid_ref' => ['owner_uid'], 'uid_ref_nullable' => ['owner_uid']],
			// ap_id = nw_workpackage.id (Arbeitspaket) throughout the schema.
			'nw_ap_position' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['ap_id', 'position_id'],
				'fk' => ['ap_id' => 'nw_workpackage', 'position_id' => 'nw_position'],
				'depends_on' => ['nw_workpackage', 'nw_position']],
			'nw_reallocation' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'plan_ver_id', 'from_position_id', 'to_position_id', 'amount_minor', 'created_at'],
				'fk' => [
					'project_id' => 'nw_project',
					'plan_ver_id' => 'nw_finplan_ver',
					'from_position_id' => 'nw_position',
					'to_position_id' => 'nw_position',
				],
				'depends_on' => ['nw_project', 'nw_finplan_ver', 'nw_position'],
				'uid_ref' => ['uid']],

			// ══ time + receipt capture (user-scoped rows) ══
			'nw_rate_history' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'uid', 'role_key', 'valid_from'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project'],
				'uid_ref' => ['uid']],
			'nw_time_entry' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'ap_id', 'uid', 'entry_date', 'minutes'],
				'fk' => ['project_id' => 'nw_project', 'ap_id' => 'nw_workpackage'],
				'depends_on' => ['nw_project', 'nw_workpackage'],
				'uid_ref' => ['uid']],
			// file_id is a Nextcloud fileid — bound to the source instance's
			// file storage; NULL on insert, untouched on update.
			'nw_receipt' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'vendor', 'invoice_no', 'invoice_date', 'total_minor'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project'],
				'env_columns' => ['file_id']],
			'nw_receipt_split' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['receipt_id', 'position_id', 'ap_id', 'amount_minor'],
				'fk' => [
					'receipt_id' => 'nw_receipt',
					'position_id' => 'nw_position',
					'ap_id' => 'nw_workpackage',
				],
				'depends_on' => ['nw_receipt', 'nw_position', 'nw_workpackage']],
			'nw_timesheet' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'ap_id', 'uid', 'period'],
				'fk' => ['project_id' => 'nw_project', 'ap_id' => 'nw_workpackage'],
				'depends_on' => ['nw_project', 'nw_workpackage'],
				'uid_ref' => ['uid']],
			'nw_workreport' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['ap_id', 'period'],
				'fk' => ['ap_id' => 'nw_workpackage'], 'depends_on' => ['nw_workpackage'],
				'uid_ref' => ['author_uid']],

			// ══ money in / out + funder documents ══
			'nw_incoming_payment' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'received_date', 'amount_minor', 'call_ref'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project']],
			'nw_drawdown' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'requested_date', 'amount_minor', 'call_ref'],
				'fk' => ['project_id' => 'nw_project', 'payment_id' => 'nw_incoming_payment'],
				'depends_on' => ['nw_project', 'nw_incoming_payment']],
			'nw_carryover' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'from_year'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project']],
			'nw_claim' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'kind', 'title', 'occurred_date'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project']],
			// Immutable Nachweis documents of record — payload_json is a
			// self-contained snapshot (no refs to resolve); supersedes_id is
			// a self-reference resolved after the table loads.
			'nw_nachweis' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'version'],
				'fk' => ['project_id' => 'nw_project'],
				'fk_late' => ['supersedes_id' => 'nw_nachweis'],
				'depends_on' => ['nw_project']],
			// Auditor grants are project-scoped access grants to an NC uid —
			// a grant whose uid does not exist on the target is skipped.
			'nw_auditor_grant' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['project_id', 'nc_uid', 'valid_from', 'valid_to'],
				'fk' => ['project_id' => 'nw_project'], 'depends_on' => ['nw_project'],
				'uid_ref' => ['nc_uid']],

			// ══ never transferred ══
			// Polymorphic scope table: ref_id holds a nw_position.id or a
			// nw_workpackage.id depending on scope — a single-column engine
			// fk cannot remap that conditionally, and a verbatim numeric
			// ref_id would silently point at the WRONG row after id
			// remapping (azc polymorphic-scope precedent). No row-level hook
			// exists → never transfer.
			'nw_deviation' => ['class' => 'internal'],
			// License state is bound to this instance and the vendor
			// signature (payload_b64/signature_b64 never move); mobile seats
			// are entitlements of that license — instance-bound.
			'nw_license_state' => ['class' => 'internal'],
			'nw_mobile_seat' => ['class' => 'internal'],
			// Deadlines are recomputed from project data by the deadline
			// engine on the target — derived, never transferred.
			'nw_deadline' => ['class' => 'derived'],
			// Append-only audit / access / import trails — transferred state
			// must be re-audited on the target, not cloned.
			'nw_audit_event' => ['class' => 'log'],
			'nw_auditor_read' => ['class' => 'log'],
			'nw_import_batch' => ['class' => 'log'],
		]);
	}
}
