<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="nw-card nw-section" aria-labelledby="nw-xfer-export-h" id="nw-transfer-export-card">
	<header class="nw-section__header">
		<div>
			<h2 id="nw-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="nw-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="nw-callout nw-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="nw-transfer-export-form" class="nw-form" novalidate>
		<div class="nw-form-row">
			<label for="nw-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="nw-input-group">
				<input type="password" id="nw-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="nw-transfer-passphrase-hint nw-transfer-passphrase-strength">
				<button type="button" class="nw-btn nw-btn--ghost" id="nw-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="nw-btn nw-btn--secondary" id="nw-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="nw-form-row__hint" id="nw-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="nw-form-row__hint nw-transfer-strength" id="nw-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="nw-actions">
			<button type="submit" class="nw-btn nw-btn--primary" id="nw-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="nw-card nw-section" aria-labelledby="nw-xfer-import-h" id="nw-transfer-import-card">
	<header class="nw-section__header">
		<div>
			<h2 id="nw-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="nw-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="nw-transfer-last" id="nw-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php
			$transferMode = (string)($lastReceipt['mode'] ?? '');
			if ($transferMode === 'merge') {
				p($l->t('Merge'));
			} elseif ($transferMode === 'replace') {
				p($l->t('Replace'));
			} else {
				p($transferMode !== '' ? $transferMode : '—');
			}
		?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="nw-transfer-import-form" class="nw-form" novalidate>
		<fieldset class="nw-form-row nw-fieldset">
			<legend><?php p($l->t('Mode')); ?></legend>
			<label class="nw-radio">
				<input type="radio" name="mode" value="merge" checked>
				<span><strong><?php p($l->t('Merge')); ?></strong> — <?php p($l->t('add and update; nothing is deleted')); ?></span>
			</label>
			<label class="nw-radio nw-radio--danger">
				<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
				<span><strong><?php p($l->t('Replace')); ?></strong> — <?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
			</label>
			<p class="nw-form-row__hint" id="nw-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="nw-form-row">
			<label for="nw-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="nw-transfer-file" name="file" accept=".nctransfer,application/json" class="nw-file-input">
		</div>
		<div class="nw-form-row">
			<label for="nw-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="nw-input-group">
				<input type="password" id="nw-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="nw-btn nw-btn--ghost" id="nw-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="nw-transfer-import-status" class="nw-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="nw-transfer-report" class="nw-dialog nw-transfer-report" aria-labelledby="nw-transfer-report-h">
	<div class="nw-dialog__body">
		<h3 id="nw-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="nw-transfer-report-verdict" class="nw-transfer-report__verdict"></p>
		<p id="nw-transfer-report-file" class="nw-form-row__hint"></p>
		<div id="nw-transfer-report-groups"></div>
	</div>
	<footer class="nw-dialog__footer">
		<div id="nw-transfer-replace-confirm" class="nw-form-row" hidden>
			<label for="nw-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="nw-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="nw-actions">
			<button type="button" class="nw-btn nw-btn--secondary" id="nw-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="nw-btn nw-btn--primary" id="nw-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="nw-transfer-progress" class="nw-dialog" aria-labelledby="nw-transfer-progress-h" aria-live="polite">
	<div class="nw-dialog__body">
		<h3 id="nw-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="nw-transfer-progress-bar" max="100" value="0"></progress>
		<p id="nw-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="nw-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="nw-transfer-result-live" class="nw-visually-hidden" role="status" aria-live="polite"></div>
