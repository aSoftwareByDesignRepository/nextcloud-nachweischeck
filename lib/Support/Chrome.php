<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Page chrome assembly — single place that fills $_ with the keys
 * templates/common/page-start.php consumes (navGroups, settingsNav,
 * urls, clientHints, roleLabel). Controllers stay free of chrome plumbing.
 */

namespace OCA\NachweisCheck\Support;

use OCA\NachweisCheck\Service\AccessDoorService;
use OCP\IDateTimeZone;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Server;

final class Chrome
{
	private function __construct()
	{
	}

	private static function route(string $route, array $params = []): string
	{
		return Server::get(IURLGenerator::class)->linkToRoute($route, $params);
	}

	/**
	 * @param array<string,mixed> $_ template params (mutated in place)
	 * @param list<array{id:int,name:string,state:string}> $projects
	 * @param array{id:int,name:string,caps:array<string,bool>}|null $current
	 */
	public static function apply(array &$_, string $navId, array $projects = [], ?array $current = null): void
	{
		$r = self::route(...);
		$l10n = Server::get(IFactory::class)->get('nachweischeck');
		$_['pageId'] = (string)($_['pageId'] ?? $navId);
		$_['pageTitle'] = (string)($_['pageTitle'] ?? $l10n->t('NachweisCheck'));
		$_['pageHelp'] = (string)($_['pageHelp'] ?? '');
		$_['pageIcon'] = (string)($_['pageIcon'] ?? 'clipboard-check');
		$_['brandTitle'] = $l10n->t('NachweisCheck');
		$_['brandIcon'] = 'clipboard-check';
		$_['urls'] = ['home' => $r('nachweischeck.page.index')];
		$_['roleLabel'] = (string)($_['roleLabel'] ?? $l10n->t('Member'));

		$lang = $l10n->getLanguageCode();
		$tz = 'UTC';
		try {
			$tz = Server::get(IDateTimeZone::class)->getTimeZone()->getName();
		} catch (\Throwable) {
		}
		$_['clientHints'] = ['locale' => $lang, 'htmlLang' => $lang, 'timezone' => $tz];

		$groups = [];
		// The active project's view links are the primary navigation — they
		// come first so a long project list never buries them below the fold.
		if ($current !== null) {
			$caps = $current['caps'] ?? [];
			$base = $r('nachweischeck.page.index') . '?project=' . $current['id'] . '&view=';
			$views = [
				['id' => 'dashboard', 'label' => $l10n->t('Dashboard'), 'icon' => 'home'],
				['id' => 'finplan', 'label' => $l10n->t('Financing plan'), 'icon' => 'layout-grid'],
				['id' => 'workpackages', 'label' => $l10n->t('Work packages'), 'icon' => 'list'],
				['id' => 'time', 'label' => $l10n->t('Time & timesheets'), 'icon' => 'calendar-clock'],
				['id' => 'receipts', 'label' => $l10n->t('Receipts'), 'icon' => 'file-text'],
				['id' => 'reports', 'label' => $l10n->t('Work reports'), 'icon' => 'scroll-text'],
				['id' => 'deviations', 'label' => $l10n->t('Deviations'), 'icon' => 'alert-triangle'],
				['id' => 'nachweis', 'label' => $l10n->t('Usage reports'), 'icon' => 'file-check'],
				['id' => 'deadlines', 'label' => $l10n->t('Deadlines'), 'icon' => 'calendar'],
			];
			if (!empty($caps['can_finance'])) {
				$views[] = ['id' => 'finance', 'label' => $l10n->t('Finance'), 'icon' => 'activity'];
			}
			if (!empty($caps['can_admin'])) {
				$views[] = ['id' => 'team', 'label' => $l10n->t('Team & access'), 'icon' => 'users'];
			}
			if (!empty($caps['can_finance'])) {
				$views[] = ['id' => 'import', 'label' => $l10n->t('CSV import'), 'icon' => 'upload'];
			}
			foreach ($views as &$v) {
				$v['href'] = $base . $v['id'];
				$v['active'] = $navId === 'view-' . $v['id'];
			}
			unset($v);
			$groups[] = ['label' => $current['name'], 'items' => $views];
		}

		$items = [];
		foreach ($projects as $p) {
			$items[] = [
				'id' => 'proj-' . $p['id'],
				'label' => (string)$p['name'],
				'hint' => $l10n->t((string)$p['state']),
				'icon' => 'layout-grid',
				'href' => $r('nachweischeck.page.index') . '?project=' . $p['id'] . '&view=dashboard',
				'active' => $current !== null && $current['id'] === $p['id'],
			];
		}
		$items[] = [
			'id' => 'new-project',
			'label' => $l10n->t('New project'),
			'icon' => 'plus',
			'href' => $r('nachweischeck.page.index') . '?new=1',
			'active' => $navId === 'new-project',
		];
		$groups[] = ['label' => $l10n->t('Projects'), 'items' => $items];
		$_['navGroups'] = $groups;
		$_['footerPartial'] = dirname(__DIR__, 2) . '/templates/parts/feedback-nav-footer.php';

		try {
			$uid = (string)(Server::get(IUserSession::class)->getUser()?->getUID() ?? '');
			if ($uid !== '' && Server::get(AccessDoorService::class)->isAppAdmin(
				Server::get(IUserSession::class)->getUser())) {
				$items = [];
				foreach (SettingsCatalog::SECTIONS as $section) {
					$id = 'settings-' . $section;
					$items[] = [
						'id' => $id,
						'label' => self::sectionLabel($l10n, $section),
						'icon' => SettingsCatalog::icon($section),
						'href' => $r('nachweischeck.page.settingsSection', ['section' => $section]),
						'active' => $navId === $id,
					];
				}
				$_['settingsNav'] = $items;
			}
		} catch (\Throwable) {
		}
	}

	public static function sectionLabel($l10n, string $s): string
	{
		return match ($s) {
			'access' => $l10n->t('Access'),
			'templates' => $l10n->t('Funder templates'),
			'notifications' => $l10n->t('Notifications'),
			'license' => $l10n->t('License'),
			default => $s,
		};
	}
}
