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

        foreach ($this->lineParsers() as $parser) {
            $result = $parser($cleanLine);

            if ($result !== null) {
                return $result;
            }
        }

        return $this->emptyResult();
    }

    /**
     * @return list<callable(string): ?ParsedTestOutputData>
     */
    private function lineParsers(): array
    {
        return [
            $this->parseProgressDots(...),
            $this->parseOkSummary(...),
            $this->parseTestSummary(...),
            $this->parseDuration(...),
        ];
    }

    private function parseProgressDots(string $line): ?ParsedTestOutputData
    {
        if (! preg_match('/^[.EFSIRW]+$/', trim($line))) {
            return null;
        }

        $errors = substr_count($line, 'E');
        $failures = substr_count($line, 'F');
        $skipped = substr_count($line, 'S');
        $incomplete = substr_count($line, 'I');
        $risky = substr_count($line, 'R');
        $warnings = substr_count($line, 'W');
        $tests = substr_count($line, '.') + $errors + $failures + $skipped + $incomplete + $risky + $warnings;

        return $this->parsedResult(
            tests: $tests,
            errors: $errors,
            failures: $failures,
            skipped: $skipped,
            incomplete: $incomplete,
            risky: $risky,
            warnings: $warnings,
        );
    }

    private function parseOkSummary(string $line): ?ParsedTestOutputData
    {
        if (! preg_match('/OK\s*\((\d+)\s+tests?,\s*(\d+)\s+assertions?\)/', $line, $matches)) {
            return null;
        }

        return $this->parsedResult(
            tests: (int) $matches[1],
            assertions: (int) $matches[2],
        );
    }

    private function parseTestSummary(string $line): ?ParsedTestOutputData
    {
        if (! preg_match('/Tests:\s*(\d+),\s*Assertions:\s*(\d+)/', $line, $matches)) {
            return null;
        }

        return $this->parsedResult(
            tests: (int) $matches[1],
            assertions: (int) $matches[2],
            errors: $this->extractCounter($line, 'Errors'),
            failures: $this->extractCounter($line, 'Failures'),
            skipped: $this->extractCounter($line, 'Skipped'),
            incomplete: $this->extractCounter($line, 'Incomplete'),
            risky: $this->extractCounter($line, 'Risky'),
            warnings: $this->extractCounter($line, 'Warnings'),
        );
    }

    private function parseDuration(string $line): ?ParsedTestOutputData
    {
        if (! preg_match('/Time:\s*(\d+):(\d+)\.(\d+)/', $line, $matches)) {
            return null;
        }

        return $this->parsedResult(
            duration: ((int) $matches[1]) * 60.0 + (int) $matches[2] + (int) $matches[3] / 1000.0,
        );
    }

    private function extractCounter(string $line, string $label): int
    {
        if (! preg_match("/{$label}:\s*(\d+)/", $line, $matches)) {
            return 0;
        }

        return (int) $matches[1];
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

    private function parsedResult(
        int $tests = 0,
        int $assertions = 0,
        int $errors = 0,
        int $failures = 0,
        int $skipped = 0,
        int $incomplete = 0,
        int $risky = 0,
        int $warnings = 0,
        float $duration = 0.0,
    ): ParsedTestOutputData {
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
            success: $errors === 0 && $failures === 0,
        );
    }

    private function emptyResult(): ParsedTestOutputData
    {
        return $this->parsedResult();
    }
}
