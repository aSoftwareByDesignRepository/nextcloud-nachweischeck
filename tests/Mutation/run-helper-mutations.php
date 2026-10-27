<?php
// Mutation runner: applies targeted source mutations to Support/ helpers,
// reruns phpunit, and reports kill/survive per mutant.
declare(strict_types=1);

$root = dirname(__DIR__, 2);

// Mutants documented as EQUIVALENT (cannot be killed by any test because
// they produce identical observable behavior on the typed domain):
//  - Money::ratioMilli `$used < 0`→`<= 0`: used===0 returns 0 through both
//    paths (early return vs. computed q=0, r=0 → 0). Guard only excludes
//    negatives.
//  - SplitValidator `$sum !== $total`→`!=`: both operands are `int` typed —
//    !== and != coincide for same-type integers.
//  - DeadlineCalculator `$yearEnd >= $end`→`>`: the loop bound y < endYear
//    makes $yearEnd (Dec-31 of y) strictly < $end (inside year endYear ≥ y+1)
//    — the coincide branch is unreachable by construction.
$equivalent = [
	'lib/Support/Money.php :: ratio sign guard',
	'lib/Support/SplitValidator.php :: invariant strictness',
	'lib/Support/DeadlineCalculator.php :: boundary coincide check',
];

$targets = [
	'lib/Support/ThresholdEvaluator.php' => [
		['$remainder * 5 > $budgetMinor', '$remainder * 5 >= $budgetMinor', 'boundary >= vs >'],
		['$usedMinor <= $budgetMinor', '$usedMinor < $budgetMinor', 'ok boundary <= vs <'],
		['self::STATE_BLOCKED_OVER', 'self::STATE_WITHIN_20', 'state swap'],
	],
	'lib/Support/Money.php' => [
		['intdiv($minutes * $rateMinorPerHour + 30, 60)', 'intdiv($minutes * $rateMinorPerHour, 60)', 'drop round-half-up'],
		['$used < 0', '$used <= 0', 'ratio sign guard'],
	],
	'lib/Support/SplitValidator.php' => [
		['$sum !== $totalMinor', '$sum != $totalMinor', 'invariant strictness'],
		['$amount <= 0', '$amount < 0', 'zero split guard'],
	],
	'lib/Support/RateResolver.php' => [
		['$vf <= $dateYmd', '$vf < $dateYmd', 'inclusive valid_from'],
		['$vf >= $bestDate', '$vf > $bestDate', 'latest-wins tiebreak'],
	],
	'lib/Support/CsvCell.php' => [
		["in_array(\$s[0], self::FORMULA_PREFIXES, true)", "in_array(\$s[0], ['='], true)", 'prefix list shrink'],
	],
	'lib/Support/GapCollector.php' => [
		['empty($r[\'file_id\'])', '!empty($r[\'file_id\'])', 'attachment gap invert'],
		['$t[\'rate_minor\'] === null', '$t[\'rate_minor\'] !== null', 'unpriced invert'],
	],
	'lib/Support/DeadlineCalculator.php' => [
		['$yearEnd >= $end', '$yearEnd > $end', 'boundary coincide check'],
	],
];

$killed = 0;
$survived = [];
$equivOk = [];
$claimsViolated = [];
foreach ($targets as $file => $mutants) {
	$path = "$root/$file";
	$orig = file_get_contents($path);
	foreach ($mutants as [$from, $to, $name]) {
		$key = "$file :: $name";
		$isEquiv = in_array($key, $equivalent, true);
		if (!str_contains($orig, $from)) {
			if ($isEquiv) {
				$claimsViolated[] = $key . ' (pattern gone — claim stale)';
			} else {
				$survived[] = "$key (PATTERN NOT FOUND)";
			}
			continue;
		}
		file_put_contents($path, str_replace($from, $to, $orig));
		exec("cd $root && ./vendor/bin/phpunit --testsuite unit 2>&1", $out, $code);
		file_put_contents($path, $orig);
		if ($code === 0) {
			if ($isEquiv) {
				$equivOk[] = $key;
			} else {
				$survived[] = $key;
			}
		} else {
			if ($isEquiv) {
				// A killed "equivalent" mutant disproves the claim — fail loudly.
				$claimsViolated[] = $key;
			}
			$killed++;
		}
		$out = [];
	}
}
echo "MUTANTS KILLED: $killed\n";
echo "MUTANTS EQUIVALENT (documented, survived as expected): " . count($equivOk) . "\n";
foreach ($equivOk as $s) {
	echo "  EQUIVALENT: $s\n";
}
foreach ($claimsViolated as $s) {
	echo "  EQUIVALENT-CLAIM-VIOLATED: $s\n";
}
echo "MUTANTS SURVIVED: " . count($survived) . "\n";
foreach ($survived as $s) {
	echo "  SURVIVOR: $s\n";
}
exit(count($survived) === 0 && count($claimsViolated) === 0 ? 0 : 1);
