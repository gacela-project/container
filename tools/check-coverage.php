<?php

declare(strict_types=1);

/**
 * Fail the build when line coverage drops below a threshold.
 *
 * Usage: php tools/check-coverage.php <clover.xml> <min-percent>
 */

$cloverFile = $argv[1] ?? null;
$threshold = (float) ($argv[2] ?? 0);

if ($cloverFile === null || !is_file($cloverFile)) {
    fwrite(STDERR, "Coverage file not found: " . var_export($cloverFile, true) . "\n");
    exit(1);
}

$xml = simplexml_load_file($cloverFile);
if ($xml === false) {
    fwrite(STDERR, "Could not parse {$cloverFile}\n");
    exit(1);
}

$metrics = $xml->project->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "No <project><metrics> found in {$cloverFile}\n");
    exit(1);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, "Clover reports zero statements; refusing to pass.\n");
    exit(1);
}

$percent = $covered / $statements * 100;

printf("Line coverage: %.2f%% (%d/%d statements), threshold %.2f%%\n", $percent, $covered, $statements, $threshold);

if ($percent + 0.005 < $threshold) {
    fwrite(STDERR, sprintf("FAILED: coverage %.2f%% is below the %.2f%% threshold.\n", $percent, $threshold));
    fwrite(STDERR, "Add tests for the uncovered lines rather than lowering the threshold.\n");
    exit(1);
}

echo "OK\n";
