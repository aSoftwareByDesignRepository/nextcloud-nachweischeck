<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
$section = (string)($_['settingsSection'] ?? 'access');
?>
<?php if ($section === 'license'): ?>
	<!-- Server-rendered shared license page (apps/_shared/license-page) —
	     the settings SPA renderer does not run on this section. -->
	<div class="nw-page-stack">
		<?php include __DIR__ . '/parts/settings/license.php'; ?>
	</div>
<?php elseif ($section === 'transfer'): ?>
	<!-- Server-rendered synced page (apps/_shared/config-transfer) — the
	     settings SPA renderer does not run on this section. -->
	<div class="nw-page-stack">
		<?php include __DIR__ . '/parts/settings/transfer.php'; ?>
	</div>
<?php elseif ($section === 'agent'): ?>
	<!-- Server-rendered synced page (apps/_shared/agent-auth) — the
	     settings SPA renderer does not run on this section. -->
	<?php $agentAccess = is_array($agentAccess ?? null) ? $agentAccess : ($_['agentAccess'] ?? null); ?>
	<div class="nw-page-stack">
		<?php include __DIR__ . '/parts/settings/agent.php'; ?>
	</div>
<?php else: ?>
	<div id="nw-settings" class="nw-page-stack" data-nw-settings="<?php p($section); ?>">
		<div class="nw-empty-state" aria-busy="true">
			<p class="nw-empty-state__text"><?php p($l->t('Loading…')); ?></p>
		</div>
	</div>
<?php endif; ?>
<?php include __DIR__ . '/common/page-end.php'; ?>
