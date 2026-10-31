<?php

declare(strict_types=1);

/**
 * Fiscal-core mutation run: applies source-level mutants to the money-
 * critical support classes and re-runs the unit suite against each. A
 * mutant that survives means the tests cannot see the broken behavior —
 * the suite fails the run, not the mutant.
 *
 * Classes: ThresholdEvaluator, InterestCalculator, RateResolver, CsvCell,
 * Money::mulRate rounding. Extends run-helper-mutations.php coverage.
 */

$app = dirname(__DIR__, 2);
$mutants = [
	'ThresholdEvaluator: 20% boundary strictness (>)' => [
		'file' => 'lib/Support/ThresholdEvaluator.php',
		'find' => '$over20 = $remainder > $budgetMinor || $remainder * 5 > $budgetMinor;',
		'repl' => '$over20 = $remainder > $budgetMinor || $remainder * 5 >= $budgetMinor;',
	],
	'ThresholdEvaluator: festbetrag flag ignored' => [
		'file' => 'lib/Support/ThresholdEvaluator.php',
		'find' => "if (!(\$ruleFlags['einzelansatz_20pct'] ?? true) || (\$ruleFlags['festbetrag'] ?? false)) {",
		'repl' => "if (!(\$ruleFlags['einzelansatz_20pct'] ?? true)) {",
	],
	'ThresholdEvaluator: unbudgeted spend not blocked' => [
		'file' => 'lib/Support/ThresholdEvaluator.php',
		'find' => 'return self::STATE_BLOCKED_OVER; // spend on unbudgeted position',
		'repl' => 'return self::STATE_WITHIN_20; // spend on unbudgeted position',
	],
	'InterestCalculator: act/360 no half-up rounding' => [
		'file' => 'lib/Support/InterestCalculator.php',
		'find' => 'return intdiv($numerator + intdiv($denominator, 2), $denominator);',
		'repl' => 'return intdiv($numerator, $denominator);',
	],
	'InterestCalculator: 31st day not clamped' => [
		'file' => 'lib/Support/InterestCalculator.php',
		'find' => '$d2 = min($d2, 30);',
		'repl' => '',
	],
	'RateResolver: picks oldest not newest valid rate' => [
		'file' => 'lib/Support/RateResolver.php',
		'find' => 'if ($vf <= $dateYmd && ($bestDate === null || $vf >= $bestDate)) {',
		'repl' => 'if ($vf <= $dateYmd && ($bestDate === null || $vf <= $bestDate)) {',
	],
	'RateResolver: future rates leak into past bookings' => [
		'file' => 'lib/Support/RateResolver.php',
		'find' => 'if ($vf <= $dateYmd && ($bestDate === null || $vf >= $bestDate)) {',
		'repl' => 'if ($bestDate === null || $vf >= $bestDate) {',
	],
	'CsvCell: formula injection unguarded' => [
		'file' => 'lib/Support/CsvCell.php',
		'find' => "if (\$s !== '' && in_array(\$s[0], self::FORMULA_PREFIXES, true)) {",
		'repl' => 'if (false) {',
	],
	'CsvCell: delimited separator not stripped' => [
		'file' => 'lib/Support/CsvCell.php',
		'find' => 'return str_replace([$fieldSep, "\r", "\n", "\x0B"], \' \', $s);',
		'repl' => 'return $s;',
	],
	'Money: half-up rounding lost' => [
		'file' => 'lib/Support/Money.php',
		'find' => 'return intdiv($minutes * $rateMinorPerHour + 30, 60);',
		'repl' => 'return intdiv($minutes * $rateMinorPerHour, 60);',
	],
];

$failures = [];
foreach ($mutants as $name => $m) {
	$path = $app . '/' . $m['file'];
	$src = file_get_contents($path);
	if (!str_contains($src, $m['find'])) {
		$failures[] = "$name — NEEDLE NOT FOUND in {$m['file']}";
		continue;
	}
	file_put_contents($path, str_replace($m['find'], $m['repl'], $src));
	$out = [];
	$code = 0;
	exec('cd ' . escapeshellarg($app) . ' && php vendor/bin/phpunit --testsuite unit 2>&1', $out, $code);
	file_put_contents($path, $src); // always restore
	if ($code === 0) {
		$failures[] = "$name — SURVIVED (suite stayed green)";
	} else {
		echo "killed: $name\n";
	}
}

if ($failures !== []) {
	echo "\nSURVIVED/ERROR MUTANTS:\n  " . implode("\n  ", $failures) . "\n";
	exit(1);
}
echo "\nAll fiscal-core mutations killed.\n";
