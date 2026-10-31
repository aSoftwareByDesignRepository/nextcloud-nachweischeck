<?php

declare(strict_types=1);

/**
 * I-02 contract test: every /api/v1 route declared in appinfo/routes.php
 * must appear in docs/openapi-v1.yaml — and the yaml must parse. A route
 * that ships undocumented is a contract violation; a broken yaml is worse.
 */

namespace OCA\NachweisCheck\Tests\Unit;

use Test\TestCase;

class OpenApiContractTest extends TestCase
{
	private const PAGE_ROUTES = ['/', '/settings', '/settings/{section}'];
	private const NON_API_ROUTES = ['/projects/{projectId}/calendar.ics'];

	private static function routes(): array
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		preg_match_all("/'url'\\s*=>\\s*'([^']+)'/", $src, $m);
		return array_unique($m[1]);
	}

	private static function yaml(): array
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi-v1.yaml');
		// Cheap structural check first: paths must live under 'paths:' at
		// top level — a stray 'components'-level path silently vanishes.
		preg_match('/^paths:\n((?:  \\S.*\n|    .*\n| *#.*\n|\s*\n)*)/m', $src, $pm);
		preg_match_all('/^  (\\/\S+):/m', $pm[1] ?? '', $mm);
		return [$src, array_unique($mm[1])];
	}

	public function testYamlParses(): void
	{
		[$src] = self::yaml();
		self::assertStringNotContainsString("\t", $src);
		self::assertStringContainsString('openapi: 3.0.3', $src);
		self::assertMatchesRegularExpression('/^paths:/m', $src);
		self::assertMatchesRegularExpression('/^components:/m', $src);
		// 'paths:' must end before 'components:' — a stray path key nested
		// under components is invisible to consumers (the 0.3.0 bug class).
		$pathsPos = strpos($src, "\npaths:");
		$compPos = strpos($src, "\ncomponents:");
		self::assertIsInt($pathsPos);
		self::assertIsInt($compPos);
		self::assertLessThan($compPos, $pathsPos);
		// A '{' inside a flow mapping value was the original break —
		// guard: no unquoted { inside any summary.
		foreach (explode("\n", $src) as $i => $line) {
			if (preg_match('/summary: [^\'",\n]*\{/', $line)) {
				self::fail('Unquoted brace in summary at line ' . ($i + 1));
			}
		}
	}

	public function testEveryApiRouteDocumented(): void
	{
		[, $docPaths] = self::yaml();
		$norm = static fn(string $u): string => preg_replace('/\{[^}]+\}/', '{X}', $u);
		$docNorm = array_map($norm, $docPaths);
		$missing = [];
		foreach (self::routes() as $u) {
			$api = str_replace('/api/v1', '', $u);
			if (in_array($api, self::PAGE_ROUTES, true) || in_array($api, self::NON_API_ROUTES, true)) {
				continue;
			}
			if (!in_array($norm($api), $docNorm, true)) {
				$missing[] = $api;
			}
		}
		self::assertSame([], $missing, 'Undocumented API routes: ' . implode(', ', $missing));
	}
}
