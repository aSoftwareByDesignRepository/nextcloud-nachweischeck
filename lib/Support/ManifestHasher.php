<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Deterministic manifest hash for a generated Nachweis (ADR-05/06).
 * Canonical JSON (recursive ksort, unescaped unicode/slashes) → SHA-256.
 * Any tampering with any included row changes the hash.
 */

namespace OCA\NachweisCheck\Support;

final class ManifestHasher
{
	private function __construct()
	{
	}

	public static function hash(array $payload): string
	{
		$canonical = self::canonicalize($payload);
		return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private static function canonicalize(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		// list vs assoc: keep list order (meaningful), sort assoc keys
		if ($value !== [] && array_keys($value) === range(0, count($value) - 1)) {
			return array_map([self::class, 'canonicalize'], $value);
		}
		ksort($value);
		return array_map([self::class, 'canonicalize'], $value);
	}
}
