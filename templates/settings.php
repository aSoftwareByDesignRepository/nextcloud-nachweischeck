<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
$section = (string)($_['settingsSection'] ?? 'access');
?>
<?php if ($section === 'transfer'): ?>
	<!-- Server-rendered synced page (apps/_shared/config-transfer) — the
	     settings SPA renderer does not run on this section. -->
	<div class="nw-page-stack">
		<?php include __DIR__ . '/parts/settings/transfer.php'; ?>
	</div>
<?php else: ?>
	<div id="nw-settings" class="nw-page-stack" data-nw-settings="<?php p($section); ?>">
		<div class="nw-empty-state" aria-busy="true">
			<p class="nw-empty-state__text"><?php p($l->t('Loading…')); ?></p>
		</div>
	</div>
<?php endif; ?>
<?php include __DIR__ . '/common/page-end.php'; ?>
