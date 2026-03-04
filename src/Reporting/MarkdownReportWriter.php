<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Reporting;

use Haakco\ParallelTestRunner\Contracts\TestRunReportWriterInterface;
use Haakco\ParallelTestRunner\Data\ReportContext;
use Override;

/**
 * Composite report writer that generates markdown-based reports.
 *
 * Delegates to PerformanceReportWriter, RuntimeBaselineWriter, and SkipAuditWriter.
 * Also generates a JSON run report via JsonRunReportWriter.
 */
final readonly class MarkdownReportWriter implements TestRunReportWriterInterface
{
    public function __construct(
        private JsonRunReportWriter $jsonWriter,
        private PerformanceReportWriter $performanceWriter,
        private RuntimeBaselineWriter $baselineWriter,
        private SkipAuditWriter $skipAuditWriter,
    ) {}

    #[Override]
    public function write(ReportContext $context): void
    {
        if (! config('parallel-test-runner.reports.enabled', true)) {
            return;
        }

        $this->jsonWriter->write($context);
        $this->performanceWriter->write($context);
        $this->baselineWriter->write($context);
        $this->skipAuditWriter->write($context);
    }
}
