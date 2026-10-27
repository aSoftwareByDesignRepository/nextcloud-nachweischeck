<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
$section = (string)($_['settingsSection'] ?? 'access');
?>
<div id="nw-settings" class="nw-page-stack" data-nw-settings="<?php p($section); ?>">
	<div class="nw-empty-state" aria-busy="true">
		<p class="nw-empty-state__text"><?php p($l->t('Loading…')); ?></p>
	</div>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
