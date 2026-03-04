<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts;

use Haakco\ParallelTestRunner\Data\ReportContext;

interface TestRunReportWriterInterface
{
    /**
     * Write a test run report.
     */
    public function write(ReportContext $context): void;
}
