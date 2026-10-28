<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var array $_ */
/** @var \OCP\IL10N $l */

use OCA\NachweisCheck\Service\IconCatalog;

if (($_['appAllowed'] ?? false) === false) {
	// Access door: minimal shell + alert + one recovery CTA (Bachus
	// no-dead-ends). Deliberately NOT the full page-start chrome — a nav
	// sidebar of links the door refuses is a dead end, and the duplicated
	// breadcrumb/header/empty-state pile read as broken chrome.
	?>
	<div id="app-content" class="nw-app nw-app--access-denied">
		<a class="nw-skip-link" href="#nw-denied-main"><?php p($l->t('Skip to main content')); ?></a>
		<div class="nw-denied">
			<main id="nw-denied-main" class="nw-card" role="alert" aria-labelledby="nw-denied-title" tabindex="-1">
				<div class="nw-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render('lock', 'nw-page-header__icon-svg')); ?>
				</div>
				<h1 id="nw-denied-title" class="nw-denied__title"><?php p($l->t('Access restricted')); ?></h1>
				<p class="nw-denied__text"><?php p($l->t('Your account is not allowed to use NachweisCheck. Ask an administrator to grant access.')); ?></p>
				<p class="nw-denied__hint"><?php p($l->t('If you believe this is a mistake, contact your Nextcloud administrator.')); ?></p>
				<a class="nw-btn nw-btn--primary" href="<?php p((string)($_['homeUrl'] ?? '/')); ?>">
					<?php p($l->t('Back to Nextcloud')); ?>
				</a>
			</main>
		</div>
	</div>
	<?php
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
