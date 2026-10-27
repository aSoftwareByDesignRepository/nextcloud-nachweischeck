<?php
/**
 * NachweisCheck page chrome — design-system Check-family shell.
 * Shared component: apps/_shared/check-shell (synced — do not edit in-app).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 *
 * Expected params:
 *   pageId, pageTitle, pageHelp, pageIcon (IconCatalog name),
 *   roleLabel (scope-strip badge), urls.home (breadcrumb root),
 *   navGroups (list of {label, items}), settingsNav (optional),
 *   clientHints {locale, htmlLang, timezone}, hideScopeStrip (optional)
 */

use OCA\NachweisCheck\Service\IconCatalog;

$pageId = (string)($_['pageId'] ?? 'home');
$pageTitle = (string)($_['pageTitle'] ?? $l->t('NachweisCheck'));
$pageHelp = (string)($_['pageHelp'] ?? '');
$pageIcon = (string)($_['pageIcon'] ?? 'home');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Member'));
$appTitle = (string)($_['brandTitle'] ?? $l->t('NachweisCheck'));
$urls = $_['urls'] ?? [];
$clientHints = $_['clientHints'] ?? ['locale' => 'en-US', 'htmlLang' => 'en-US', 'timezone' => 'UTC'];
$htmlLang = (string)($clientHints['htmlLang'] ?? 'en-US');
$locale = (string)($clientHints['locale'] ?? $htmlLang);
$timezone = (string)($clientHints['timezone'] ?? 'UTC');
$settingsSection = (string)($_['settingsSection'] ?? '');
$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$homeUrl = (string)($urls['home'] ?? '#');

include __DIR__ . '/navigation.php';
?>
<div id="app-content" class="nw-app nw-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-locale="<?php p($locale); ?>"
	data-nw-page="<?php p($pageId); ?>"
	data-nw-timezone="<?php p($timezone); ?>"
	data-nw-settings-section="<?php p($settingsSection); ?>"
	data-nw-urls="<?php print_unescaped($urlsJson); ?>">
	<a class="nw-skip-link" href="#nw-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="nw-live-region" class="nw-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="nw-alert-region" class="nw-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="nw-shell">
		<!-- div, not header: an unscoped <header> outside sectioning content
		     registers a second banner landmark (duplicate banner with NC
		     #header). -->
		<div class="nw-page-header" aria-labelledby="nw-page-title">
			<div class="nw-page-header__top">
				<?php require __DIR__ . '/nav-toggle.php'; ?>
				<nav class="nw-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
					<ol class="nw-breadcrumb__list">
						<li class="nw-breadcrumb__item">
							<a class="nw-breadcrumb__link" href="<?php p($homeUrl); ?>"><?php p($appTitle); ?></a>
						</li>
						<li class="nw-breadcrumb__item nw-breadcrumb__item--current" aria-current="page">
							<span class="nw-breadcrumb__current"><?php p($pageTitle); ?></span>
						</li>
					</ol>
				</nav>
			</div>
			<div class="nw-page-header__main">
				<div class="nw-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($pageIcon, 'nw-page-header__icon-svg')); ?>
				</div>
				<div class="nw-page-header__text">
					<h1 id="nw-page-title" class="nw-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="nw-page-header__lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="nw-page-actions" class="nw-page-header__actions">
					<?php foreach (($_['pageActions'] ?? []) as $action): ?>
						<?php
						$aClass = 'nw-btn ' . (!empty($action['danger']) ? 'nw-btn--danger' : (!empty($action['primary']) ? 'nw-btn--primary' : 'nw-btn--secondary'));
						?>
						<?php if (!empty($action['href'])): ?>
							<a class="<?php p($aClass); ?>" href="<?php p($action['href']); ?>"
								<?php if (!empty($action['id'])): ?>id="<?php p($action['id']); ?>"<?php endif; ?>>
								<?php if (!empty($action['icon'])): ?><?php print_unescaped(IconCatalog::render((string)$action['icon'])); ?><?php endif; ?>
								<?php p($action['label']); ?>
							</a>
						<?php else: ?>
							<button type="button" class="<?php p($aClass); ?>"
								<?php if (!empty($action['id'])): ?>id="<?php p($action['id']); ?>"<?php endif; ?>>
								<?php if (!empty($action['icon'])): ?><?php print_unescaped(IconCatalog::render((string)$action['icon'])); ?><?php endif; ?>
								<?php p($action['label']); ?>
							</button>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php if (empty($_['hideScopeStrip'])): ?>
			<div class="nw-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="nw-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="nw-badge nw-badge--neutral nw-scope-strip__badge"><?php p($roleLabel); ?></span>
				<span class="nw-scope-strip__sep" aria-hidden="true">·</span>
				<span class="nw-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="nw-scope-strip__value"><?php p($timezone); ?></span>
			</div>
			<?php endif; ?>
		</div>
		<main id="nw-main-content" class="nw-main" tabindex="-1" aria-labelledby="nw-page-title">
