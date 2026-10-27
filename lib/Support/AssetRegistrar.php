<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Single source for the app's shell asset set (Check-family pattern).
 * Shared component: apps/_shared/check-shell (synced — do not edit in-app).
 *
 * The access-denied surface renders via middleware before the controller —
 * it must register the same styles or it renders unstyled.
 */

namespace OCA\NachweisCheck\Support;

use OCA\NachweisCheck\AppInfo\Application;
use OCP\Util;

final class AssetRegistrar
{
	/**
	 * Full shared stylesheet layer, in dependency order:
	 * tokens → chrome/layout → controls/patterns → surfaces → app overrides.
	 */
	public static function registerStyles(): void
	{
		// Make l10n/{lang}.json available to OC.L10N.translate() in page JS.
		Util::addTranslations(Application::APP_ID);
		$styles = [
			'common/tokens', 'common/shell-chrome', 'common/app-layout', 'common/navigation',
			'common/mobile-nav', 'common/form-controls', 'common/page-patterns',
			'common/notification-surfaces', 'common/dialogs', 'common/switch-field',
			'common/badges', 'common/field-errors', 'common/surfaces',
		];
		// view-components is an opt-in pack (filter pills, stat strips,
		// pagination, combobox) — present only in apps that synced it.
		// It must precede 'app' so app overrides keep winning.
		if (is_file(__DIR__ . '/../../css/common/view-components.css')) {
			$styles[] = 'common/view-components';
		}
		$styles[] = 'app';
		foreach ($styles as $style) {
			Util::addStyle(Application::APP_ID, $style);
		}
	}

	/**
	 * Shared script layer: toasts → field errors → app feedback →
	 * mobile nav → kernel (NW). Page scripts register separately.
	 */
	public static function registerScripts(): void
	{
		$scripts = ['common/toasts', 'common/field-errors', 'common/app-feedback', 'common/mobile-nav'];
		// focus-preservation ships only in apps that synced that pack —
		// it must load before the kernel, which installs it when present.
		if (is_file(__DIR__ . '/../../js/common/focus-preservation.js')) {
			$scripts[] = 'common/focus-preservation';
		}
		$scripts[] = 'app';
		foreach ($scripts as $script) {
			Util::addScript(Application::APP_ID, $script);
		}
	}

	/** Register the shell + a page script (js/pages/<name>.js). */
	public static function register(string $pageScript): void
	{
		self::registerStyles();
		self::registerScripts();
		if ($pageScript !== '') {
			Util::addScript(Application::APP_ID, 'pages/' . $pageScript);
		}
	}

	/**
	 * Guest-facing pages (PublicTemplateResponse) get no admin shell — but
	 * they still need the token/surface styles and the NW kernel (appBase,
	 * toast) or every public page script silently no-ops.
	 */
	public static function registerPublic(string $pageScript = ''): void
	{
		self::registerStyles();
		foreach (['common/toasts', 'common/field-errors', 'app'] as $script) {
			Util::addScript(Application::APP_ID, $script);
		}
		if ($pageScript !== '') {
			Util::addScript(Application::APP_ID, 'pages/' . $pageScript);
		}
	}
}
