<?php

declare(strict_types=1);

namespace OCA\NachweisCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Chrome-literal l10n contract — intake 20261101T173222Z (raw_i18n_key_rendered):
 * PageController shipped raw English literals into the rendered chrome params
 * (pageHelp 'Select a project on the left…', roleLabel 'App admin'/'Member',
 * pageTitle 'NachweisCheck settings') so every locale painted English.
 *
 * Pin: every 'pageTitle'/'pageHelp'/'roleLabel' binding in PageController and
 * every $_['pageTitle'|'pageHelp'|'roleLabel'|'brandTitle'] assignment in
 * Chrome::apply may only carry a string literal inside a ->t(…) call (or '').
 * A reverted mutant ('roleLabel' => 'App admin') leaves a bare literal behind
 * and fails. Second leg: every ->t('…') msgid in both producers exists in all
 * four shipped catalogs (en, en_GB, de, de_DE).
 */
final class PageControllerChromeL10nTest extends TestCase
{
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Assert no bare string literal survives inside $expr once translated
	 * calls, array-index derefs and empty strings are stripped.
	 */
	private static function assertNoRawLiteral(string $expr, string $where): void
	{
		$e = preg_replace("/->t\\(\\s*'(?:[^'\\\\]|\\\\.)*'[^)]*\\)/", '', $expr) ?? $expr;
		$e = preg_replace("/\\[\\s*'(?:[^'\\\\]|\\\\.)*'\\s*\\]/", '', $e) ?? $e;
		$e = str_replace("''", '', $e);
		self::assertDoesNotMatchRegularExpression(
			"/'[^']*'/",
			$e,
			"{$where} carries a raw string literal outside ->t(…): " . trim($expr),
		);
	}

	public function testPageControllerChromeParamsRouteThroughL10n(): void
	{
		$src = (string)file_get_contents(self::LIB . '/Controller/PageController.php');
		self::assertStringContainsString('IL10N $l10n', $src, 'PageController lost its IL10N injection');

		preg_match_all(
			"/\\n\\t\\t\\t'(pageTitle|pageHelp|roleLabel)'\\s*=>\\s*(.*?)(?=\\n\\t\\t\\t'|\\n\\t\\t\\];)/s",
			$src,
			$matches,
			PREG_SET_ORDER,
		);
		self::assertCount(5, $matches, 'expected 5 chrome-param bindings (index: title/help/role; settings: title/role)');
		foreach ($matches as $m) {
			self::assertNoRawLiteral($m[2], "PageController '{$m[1]}' =>");
		}
	}

	public function testChromeApplyDefaultsRouteThroughL10n(): void
	{
		$src = (string)file_get_contents(self::LIB . '/Support/Chrome.php');
		preg_match_all(
			'/\\$_\\[\'(pageTitle|pageHelp|roleLabel|brandTitle)\'\\]\\s*=\\s*([^;]+);/',
			$src,
			$matches,
			PREG_SET_ORDER,
		);
		self::assertGreaterThanOrEqual(4, count($matches), 'expected the four chrome defaults in Chrome::apply');
		foreach ($matches as $m) {
			self::assertNoRawLiteral($m[2], "Chrome \$_['{$m[1]}'] =");
		}
	}

	public function testNavItemHintsRouteThroughL10n(): void
	{
		// 'hint' slots render enum/status chrome (nav project-state hint) —
		// never user data — so every binding must resolve through ->t(…).
		// A reverted mutant ('hint' => $p['state']) ships the raw enum.
		$src = (string)file_get_contents(self::LIB . '/Support/Chrome.php');
		preg_match_all("/'hint'\\s*=>\\s*(.+?),\\s*\\n/", $src, $matches, PREG_SET_ORDER);
		self::assertNotEmpty($matches, "no 'hint' bindings found — Chrome::apply drifted");
		foreach ($matches as $m) {
			self::assertStringContainsString(
				'->t(',
				$m[1],
				"Chrome 'hint' binding bypasses ->t(…): " . trim($m[1]),
			);
		}
	}

	public function testEveryChromeMsgidExistsInAllCatalogs(): void
	{
		$catalogs = [];
		foreach (['en', 'en_GB', 'de', 'de_DE'] as $locale) {
			$raw = json_decode(
				(string)file_get_contents(self::LIB . '/../l10n/' . $locale . '.json'),
				true,
				512,
				JSON_THROW_ON_ERROR,
			);
			$catalogs[$locale] = $raw['translations'] ?? $raw;
		}

		foreach ([
			self::LIB . '/Controller/PageController.php',
			self::LIB . '/Support/Chrome.php',
		] as $file) {
			$src = (string)file_get_contents($file);
			preg_match_all("/->t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
			$msgids = array_unique($m[1]);
			self::assertNotEmpty($msgids, basename($file) . ' produced no t() msgids — scan drifted');
			foreach ($msgids as $msgid) {
				foreach ($catalogs as $locale => $cat) {
					self::assertArrayHasKey(
						$msgid,
						$cat,
						basename($file) . " msgid '{$msgid}' missing from l10n/{$locale}.json",
					);
				}
			}
		}
	}
}
