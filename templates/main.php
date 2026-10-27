<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var array $_ */
/** @var \OCP\IL10N $l */
if (($_['appAllowed'] ?? false) === false) {
	$_['pageTitle'] = $l->t('Access restricted');
	$_['pageIcon'] = 'lock';
	$_['navGroups'] = [];
	$_['hideScopeStrip'] = true;
	include __DIR__ . '/common/page-start.php';
	?>
	<div class="nw-empty-state nw-card">
		<p class="nw-empty-state__title"><?php p($l->t('Access restricted')); ?></p>
		<p class="nw-empty-state__text"><?php p($l->t('Your account is not allowed to use NachweisCheck. Ask an administrator to grant access.')); ?></p>
	</div>
	<?php
	include __DIR__ . '/common/page-end.php';
	return;
}
include __DIR__ . '/common/page-start.php';
?>
<div id="nw-main" class="nw-page-stack" data-nw-view-host>
	<div class="nw-empty-state" aria-busy="true">
		<p class="nw-empty-state__text"><?php p($l->t('Loading…')); ?></p>
	</div>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
