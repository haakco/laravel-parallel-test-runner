<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Haakco\ParallelTestRunner\Data\ParsedTestOutputData;

final class TestOutputParserService
{
    /**
     * Parse a line of PHPUnit output into structured test metrics.
     */
    public function parseLine(string $line): ParsedTestOutputData
    {
        $cleanLine = $this->stripAnsiCodes($line);

        if ($cleanLine === '' || str_starts_with($cleanLine, 'Progress:')) {
            return $this->emptyResult();
        }

        $tests = 0;
        $assertions = 0;
        $errors = 0;
        $failures = 0;
        $skipped = 0;
        $incomplete = 0;
        $risky = 0;
        $warnings = 0;
        $duration = 0.0;
        $success = true;

        // Parse PHPUnit dot progress output (lines containing only dots and status letters)
        if (preg_match('/^[.EFSIRW]+$/', trim($cleanLine))) {
            $errors = substr_count($cleanLine, 'E');
            $failures = substr_count($cleanLine, 'F');
            $skipped = substr_count($cleanLine, 'S');
            $incomplete = substr_count($cleanLine, 'I');
            $risky = substr_count($cleanLine, 'R');
            $warnings = substr_count($cleanLine, 'W');

            $dots = substr_count($cleanLine, '.');
            $tests = $dots + $errors + $failures + $skipped + $incomplete + $risky + $warnings;
            $success = $errors === 0 && $failures === 0;

            return new ParsedTestOutputData(
                tests: $tests,
                assertions: 0,
                errors: $errors,
                failures: $failures,
                skipped: $skipped,
                incomplete: $incomplete,
                risky: $risky,
                warnings: $warnings,
                duration: 0.0,
                success: $success,
            );
        }

        // Parse "OK (N tests, N assertions)" format
        if (preg_match('/OK\s*\((\d+)\s+tests?,\s*(\d+)\s+assertions?\)/', $cleanLine, $matches)) {
            $tests = (int) $matches[1];
            $assertions = (int) $matches[2];

            return new ParsedTestOutputData(
                tests: $tests,
                assertions: $assertions,
                errors: 0,
                failures: 0,
                skipped: 0,
                incomplete: 0,
                risky: 0,
                warnings: 0,
                duration: 0.0,
                success: true,
            );
        }

        // Parse "Tests: N, Assertions: N" summary line with optional counters
        if (preg_match('/Tests:\s*(\d+),\s*Assertions:\s*(\d+)/', $cleanLine, $matches)) {
            $tests = (int) $matches[1];
            $assertions = (int) $matches[2];

            if (preg_match('/Errors:\s*(\d+)/', $cleanLine, $m)) {
                $errors = (int) $m[1];
            }

            if (preg_match('/Failures:\s*(\d+)/', $cleanLine, $m)) {
                $failures = (int) $m[1];
            }

            if (preg_match('/Skipped:\s*(\d+)/', $cleanLine, $m)) {
                $skipped = (int) $m[1];
            }

            if (preg_match('/Incomplete:\s*(\d+)/', $cleanLine, $m)) {
                $incomplete = (int) $m[1];
            }

            if (preg_match('/Risky:\s*(\d+)/', $cleanLine, $m)) {
                $risky = (int) $m[1];
            }

            if (preg_match('/Warnings:\s*(\d+)/', $cleanLine, $m)) {
                $warnings = (int) $m[1];
            }

            $success = $errors === 0 && $failures === 0;

            return new ParsedTestOutputData(
                tests: $tests,
                assertions: $assertions,
                errors: $errors,
                failures: $failures,
                skipped: $skipped,
                incomplete: $incomplete,
                risky: $risky,
                warnings: $warnings,
                duration: 0.0,
                success: $success,
            );
        }

        // Parse "Time: MM:SS.mmm" or "Time: N.NNs"
        if (preg_match('/Time:\s*(\d+):(\d+)\.(\d+)/', $cleanLine, $matches)) {
            $duration = ((int) $matches[1]) * 60.0 + (int) $matches[2] + (int) $matches[3] / 1000.0;

            return new ParsedTestOutputData(
                tests: 0,
                assertions: 0,
                errors: 0,
                failures: 0,
                skipped: 0,
                incomplete: 0,
                risky: 0,
                warnings: 0,
                duration: $duration,
                success: true,
            );
        }

        return $this->emptyResult();
    }

    /**
     * Parse multiple lines of PHPUnit output and merge results.
     *
     * @param list<string> $lines
     */
    public function parseLines(array $lines): ParsedTestOutputData
    {
        $tests = 0;
        $assertions = 0;
        $errors = 0;
        $failures = 0;
        $skipped = 0;
        $incomplete = 0;
        $risky = 0;
        $warnings = 0;
        $duration = 0.0;

        $hasSummary = false;

        foreach ($lines as $line) {
            $parsed = $this->parseLine($line);

            if ($parsed->isEmpty()) {
                if ($parsed->duration > 0.0) {
                    $duration = $parsed->duration;
                }

                continue;
            }

            // Summary lines (Tests: N, Assertions: N) override dot counts
            if ($parsed->assertions > 0) {
                $tests = $parsed->tests;
                $assertions = $parsed->assertions;
                $errors = $parsed->errors;
                $failures = $parsed->failures;
                $skipped = $parsed->skipped;
                $incomplete = $parsed->incomplete;
                $risky = $parsed->risky;
                $warnings = $parsed->warnings;
                $hasSummary = true;
            } elseif (! $hasSummary) {
                // Accumulate dot output counts until we see a summary
                $tests += $parsed->tests;
                $errors += $parsed->errors;
                $failures += $parsed->failures;
                $skipped += $parsed->skipped;
                $incomplete += $parsed->incomplete;
                $risky += $parsed->risky;
                $warnings += $parsed->warnings;
            }

            if ($parsed->duration > 0.0) {
                $duration = $parsed->duration;
            }
        }

        $success = $errors === 0 && $failures === 0;

        return new ParsedTestOutputData(
            tests: $tests,
            assertions: $assertions,
            errors: $errors,
            failures: $failures,
            skipped: $skipped,
            incomplete: $incomplete,
            risky: $risky,
            warnings: $warnings,
            duration: $duration,
            success: $success,
        );
    }

    /**
     * Parse a full output string (multi-line) into structured test metrics.
     */
    public function parseOutput(string $output): ParsedTestOutputData
    {
        if (trim($output) === '') {
            return $this->emptyResult();
        }

        $lines = explode("\n", $output);

        return $this->parseLines($lines);
    }

    private function stripAnsiCodes(string $text): string
    {
        return trim((string) preg_replace('/\x1B\[[0-9;]*m/', '', $text));
    }

    private function emptyResult(): ParsedTestOutputData
    {
        return new ParsedTestOutputData(
            tests: 0,
            assertions: 0,
            errors: 0,
            failures: 0,
            skipped: 0,
            incomplete: 0,
            risky: 0,
            warnings: 0,
            duration: 0.0,
            success: true,
        );
    }
}
