<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Settings pages catalog — one page per topic (family settings standard).
 */

namespace OCA\NachweisCheck\Support;

final class SettingsCatalog
{
	public const SECTIONS = ['access', 'templates', 'notifications', 'license', 'transfer'];

	public static function isSection(string $s): bool
	{
		return in_array($s, self::SECTIONS, true);
	}

	public static function icon(string $s): string
	{
		return match ($s) {
			'access' => 'lock',
			'templates' => 'layout-grid',
			'notifications' => 'bell',
			'license' => 'shield',
			'transfer' => 'upload',
			default => 'settings',
		};
	}
}
