<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Formula-injection-safe CSV cell writer (api-security-patterns §7).
 * Quoting alone is not enough: a leading = + - @ TAB CR VT turns a
 * customer-controlled value into a spreadsheet formula — prefix a literal
 * apostrophe in that case. Delimited export additionally strips record and
 * field separators from every substituted value (pipe-delimited, DATEV).
 */

namespace OCA\NachweisCheck\Support;

final class CsvCell
{
	private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "\x0B"];

	private function __construct()
	{
	}

	public static function escape(mixed $value): string
	{
		$s = $value === null ? '' : (string)$value;
		if ($s !== '' && in_array($s[0], self::FORMULA_PREFIXES, true)) {
			$s = "'" . $s;
		}
		return $s;
	}

	/**
	 * For non-CSV delimited formats: remove any field/record separator that
	 * could forge extra columns or rows.
	 */
	public static function delimitedSafe(mixed $value, string $fieldSep = '|'): string
	{
		$s = $value === null ? '' : (string)$value;
		return str_replace([$fieldSep, "\r", "\n", "\x0B"], ' ', $s);
	}

	/**
	 * @param list<list<scalar|null>> $rows
	 */
	public static function buildCsv(array $rows, string $sep = ';'): string
	{
		$out = '';
		foreach ($rows as $row) {
			$line = [];
			foreach ($row as $cell) {
				$line[] = self::quote(self::escape($cell), $sep);
			}
			$out .= implode($sep, $line) . "\r\n";
		}
		return $out;
	}

	private static function quote(string $s, string $sep): string
	{
		if (strpbrk($s, $sep . "\"\r\n") !== false) {
			return '"' . str_replace('"', '""', $s) . '"';
		}
		return $s;
	}
}
