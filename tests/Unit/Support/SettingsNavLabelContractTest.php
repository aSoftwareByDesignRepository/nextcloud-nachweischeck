<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Support;

use OCA\NachweisCheck\Support\Chrome;
use OCA\NachweisCheck\Support\SettingsCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Every registered settings section must resolve to a translated nav label —
 * never fall through to the raw section key (regression: 'license' was added
 * to SettingsCatalog::SECTIONS without a sectionLabel() arm and rendered
 * lowercase "license" in the app-wide settings nav for app admins).
 */
final class SettingsNavLabelContractTest extends TestCase
{
	public function testEverySectionHasATranslatedNavLabel(): void
	{
		$l10n = new class {
			/** @return string */
			public function t(string $msg): string
			{
				return 'T[' . $msg . ']';
			}
		};
		$m = new \ReflectionMethod(Chrome::class, 'sectionLabel');
		$m->setAccessible(true);
		foreach (SettingsCatalog::SECTIONS as $section) {
			$label = $m->invoke(null, $l10n, $section);
			self::assertNotSame($section, $label, "section '$section' renders its raw key as the nav label");
			self::assertStringStartsWith('T[', $label, "section '$section' did not go through l10n");
		}
	}
}
