<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Services\TestOutputParserService;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class TestOutputParserServiceTest extends TestCase
{
    private TestOutputParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TestOutputParserService();
    }

    public function test_parses_passing_dot_output(): void
    {
        $result = $this->parser->parseLine('.....');

        $this->assertSame(5, $result->tests);
        $this->assertSame(0, $result->errors);
        $this->assertSame(0, $result->failures);
        $this->assertTrue($result->success);
    }

    public function test_parses_failing_output(): void
    {
        $result = $this->parser->parseLine('..F.E.');

        $this->assertSame(6, $result->tests);
        $this->assertSame(1, $result->errors);
        $this->assertSame(1, $result->failures);
        $this->assertFalse($result->success);
    }

    public function test_parses_skipped_output(): void
    {
        $result = $this->parser->parseLine('..S.I.');

        $this->assertSame(6, $result->tests);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(1, $result->incomplete);
        $this->assertTrue($result->success);
    }

    public function test_parses_risky_test(): void
    {
        $result = $this->parser->parseLine('..R.');

        $this->assertSame(4, $result->tests);
        $this->assertSame(1, $result->risky);
        $this->assertTrue($result->success);
    }

    public function test_parses_empty_output(): void
    {
        $result = $this->parser->parseLine('');

        $this->assertTrue($result->isEmpty());
        $this->assertTrue($result->success);
    }

    public function test_parses_ok_summary_line(): void
    {
        $result = $this->parser->parseLine('OK (29 tests, 158 assertions)');

        $this->assertSame(29, $result->tests);
        $this->assertSame(158, $result->assertions);
        $this->assertTrue($result->success);
    }

    public function test_parses_tests_assertions_summary(): void
    {
        $result = $this->parser->parseLine('Tests: 42, Assertions: 100, Failures: 2, Errors: 1');

        $this->assertSame(42, $result->tests);
        $this->assertSame(100, $result->assertions);
        $this->assertSame(2, $result->failures);
        $this->assertSame(1, $result->errors);
        $this->assertFalse($result->success);
    }

    public function test_parses_summary_with_skipped(): void
    {
        $result = $this->parser->parseLine('Tests: 50, Assertions: 200, Skipped: 3, Incomplete: 1');

        $this->assertSame(50, $result->tests);
        $this->assertSame(200, $result->assertions);
        $this->assertSame(3, $result->skipped);
        $this->assertSame(1, $result->incomplete);
        $this->assertTrue($result->success);
    }

    public function test_strips_ansi_codes(): void
    {
        $result = $this->parser->parseLine("\033[32m.....\033[0m");

        $this->assertSame(5, $result->tests);
        $this->assertTrue($result->success);
    }

    public function test_parse_output_combines_multiple_lines(): void
    {
        $output = ".....\n..F..\nOK (10 tests, 20 assertions)";

        $result = $this->parser->parseOutput($output);

        $this->assertSame(10, $result->tests);
        $this->assertSame(20, $result->assertions);
    }

    public function test_parse_output_empty_string(): void
    {
        $result = $this->parser->parseOutput('');

        $this->assertTrue($result->isEmpty());
    }

    public function test_parse_output_summary_overrides_dots(): void
    {
        $output = implode("\n", [
            '..F..',
            'Tests: 5, Assertions: 10, Failures: 1',
        ]);

        $result = $this->parser->parseOutput($output);

        $this->assertSame(5, $result->tests);
        $this->assertSame(10, $result->assertions);
        $this->assertSame(1, $result->failures);
        $this->assertFalse($result->success);
    }

    public function test_ignores_progress_lines(): void
    {
        $result = $this->parser->parseLine('Progress: 50%');

        $this->assertTrue($result->isEmpty());
    }
}
