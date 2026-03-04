<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Closure;
use Exception;
use Haakco\ParallelTestRunner\Data\Results\HangingTestsResultData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Illuminate\Support\Facades\Log;

final class HangingTestDetectorService
{
    /**
     * Find tests that hang by running each section with a short timeout.
     *
     * @param list<TestSectionData> $sections
     * @param Closure(list<string> $sectionNames): bool $runSections
     * @param Closure(int $timeout): void $setTimeout
     * @param Closure(int $originalTimeout): void $restoreTimeout
     * @param Closure(string $section, int $current, int $total, string $status): void|null $onProgress
     */
    public function findHangingTests(
        array $sections,
        Closure $runSections,
        Closure $setTimeout,
        Closure $restoreTimeout,
        int $shortTimeout = 10,
        ?Closure $onProgress = null
    ): HangingTestsResultData {
        $setTimeout($shortTimeout);

        $hangingSections = [];
        $passedSections = [];
        $totalTests = count($sections);
        $current = 0;

        foreach ($sections as $section) {
            $current++;

            try {
                $success = $runSections([$section->name]);

                if ($success) {
                    $status = 'OK';
                    $passedSections[] = $section->name;
                } else {
                    $status = 'HANGING';
                    $hangingSections[] = $section->name;
                }
            } catch (Exception $exception) {
                Log::warning('Test section check failed while finding hanging tests', [
                    'section' => $section->name,
                    'error' => $exception->getMessage(),
                    'timeout_threshold' => $shortTimeout,
                ]);

                if (str_contains($exception->getMessage(), 'timeout')) {
                    $status = 'HANGING';
                    $hangingSections[] = $section->name;
                } else {
                    $status = 'ERROR';
                }
            }

            if ($onProgress instanceof Closure) {
                $onProgress($section->name, $current, $totalTests, $status);
            }
        }

        $restoreTimeout($shortTimeout);

        return new HangingTestsResultData(
            found: $hangingSections !== [],
            hangingSections: $hangingSections,
            passedSections: $passedSections,
            threshold: $shortTimeout,
        );
    }
}
