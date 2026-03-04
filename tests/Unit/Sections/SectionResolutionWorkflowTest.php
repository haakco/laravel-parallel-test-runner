<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Sections;

use Haakco\ParallelTestRunner\Contracts\SectionResolverInterface;
use Haakco\ParallelTestRunner\Data\SectionResolutionContext;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Sections\SectionResolutionWorkflow;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class SectionResolutionWorkflowTest extends TestCase
{
    private function makeSections(int $count): array
    {
        $sections = [];
        for ($i = 1; $i <= $count; $i++) {
            $files = array_map(
                static fn(int $j): string => "tests/File{$j}Test.php",
                range(1, $i),
            );

            $sections[] = new TestSectionData(
                name: "Section{$i}",
                type: 'directory',
                path: "tests/Section{$i}",
                files: $files,
                fileCount: count($files),
            );
        }

        return $sections;
    }

    private function makeContext(
        array $sections = [],
        ?string $filter = null,
        ?int $splitTotal = null,
        ?int $splitGroup = null,
    ): SectionResolutionContext {
        return new SectionResolutionContext(
            scanPaths: ['tests/Unit'],
            forceSplitDirectories: [],
            individual: false,
            sections: $sections,
            tests: [],
            filter: $filter,
            testSuite: null,
            splitTotal: $splitTotal,
            splitGroup: $splitGroup,
            additionalSuites: [],
            extraOptions: [],
        );
    }

    private function makeWorkflow(array $resolvedSections): SectionResolutionWorkflow
    {
        $resolver = $this->createStub(SectionResolverInterface::class);
        $resolver->method('resolve')->willReturn($resolvedSections);

        return new SectionResolutionWorkflow($resolver);
    }

    public function test_list_sections_returns_all_resolved(): void
    {
        $sections = $this->makeSections(3);
        $workflow = $this->makeWorkflow($sections);
        $context = $this->makeContext();

        $result = $workflow->listSections($context);

        $this->assertCount(3, $result);
    }

    public function test_sections_for_run_returns_all_without_filters(): void
    {
        $sections = $this->makeSections(4);
        $workflow = $this->makeWorkflow($sections);
        $context = $this->makeContext();

        $result = $workflow->sectionsForRun($context);

        $this->assertCount(4, $result);
    }

    public function test_sections_for_run_filters_by_section_name(): void
    {
        $sections = $this->makeSections(4);
        $workflow = $this->makeWorkflow($sections);
        $context = $this->makeContext(sections: ['Section2']);

        $result = $workflow->sectionsForRun($context);

        $this->assertCount(1, $result);
        $this->assertSame('Section2', $result[0]->name);
    }

    public function test_sections_for_run_filters_by_prefix(): void
    {
        $testSections = [
            new TestSectionData(name: 'Unit/Models', type: 'directory', path: 'tests/Unit/Models', files: ['a.php'], fileCount: 1),
            new TestSectionData(name: 'Unit/Services', type: 'directory', path: 'tests/Unit/Services', files: ['b.php'], fileCount: 1),
            new TestSectionData(name: 'Feature/Api', type: 'directory', path: 'tests/Feature/Api', files: ['c.php'], fileCount: 1),
        ];
        $workflow = $this->makeWorkflow($testSections);
        $context = $this->makeContext(sections: ['Unit']);

        $result = $workflow->sectionsForRun($context);

        $names = array_map(static fn(TestSectionData $s): string => $s->name, $result);
        $this->assertContains('Unit/Models', $names);
        $this->assertContains('Unit/Services', $names);
        $this->assertNotContains('Feature/Api', $names);
    }

    public function test_sections_for_run_applies_text_filter(): void
    {
        $testSections = [
            new TestSectionData(name: 'Unit/Models', type: 'directory', path: 'tests/Unit/Models', files: ['UserTest.php'], fileCount: 1),
            new TestSectionData(name: 'Feature/Api', type: 'directory', path: 'tests/Feature/Api', files: ['LoginTest.php'], fileCount: 1),
        ];
        $workflow = $this->makeWorkflow($testSections);
        $context = $this->makeContext(filter: 'Models');

        $result = $workflow->sectionsForRun($context);

        $this->assertCount(1, $result);
        $this->assertSame('Unit/Models', $result[0]->name);
    }

    public function test_sections_for_run_filter_falls_back_to_all_when_no_match(): void
    {
        $sections = $this->makeSections(2);
        $workflow = $this->makeWorkflow($sections);
        $context = $this->makeContext(filter: 'NonexistentXYZ');

        $result = $workflow->sectionsForRun($context);

        $this->assertCount(2, $result);
    }

    public function test_split_group_filtering(): void
    {
        // 6 sections, split into 3 groups, request group 2
        $sections = $this->makeSections(6);
        $workflow = $this->makeWorkflow($sections);
        $context = $this->makeContext(splitTotal: 3, splitGroup: 2);

        $result = $workflow->sectionsForRun($context);

        // Should return a subset of the 6 sections
        $this->assertNotEmpty($result);
        $this->assertLessThan(6, count($result));
    }

    public function test_split_group_returns_empty_for_invalid_group(): void
    {
        $sections = $this->makeSections(3);
        $workflow = $this->makeWorkflow($sections);
        $context = $this->makeContext(splitTotal: 2, splitGroup: 5);

        $result = $workflow->sectionsForRun($context);

        $this->assertSame([], $result);
    }

    public function test_get_split_groups_distributes_by_weight(): void
    {
        // Section1: 1 file, Section2: 2 files, Section3: 3 files, Section4: 4 files
        $sections = $this->makeSections(4);
        $workflow = $this->makeWorkflow($sections);

        $groups = $workflow->getSplitGroups($sections, 2);

        $this->assertCount(2, $groups);

        // Each group should have sections
        $this->assertNotEmpty($groups[0]);
        $this->assertNotEmpty($groups[1]);

        // Total sections across groups should equal input
        $totalSections = count($groups[0]) + count($groups[1]);
        $this->assertSame(4, $totalSections);
    }

    public function test_get_split_groups_balances_weights(): void
    {
        // Sections with weights 10, 8, 6, 4, 2 (total 30)
        // LPT into 2 groups: group0 gets [10,4,2]=16, group1 gets [8,6]=14
        $sections = [];
        foreach ([10, 8, 6, 4, 2] as $i => $fileCount) {
            $files = array_map(
                static fn(int $j): string => "tests/File{$j}Test.php",
                range(1, $fileCount),
            );

            $sections[] = new TestSectionData(
                name: "Section" . ($i + 1),
                type: 'directory',
                path: "tests/Section" . ($i + 1),
                files: $files,
                fileCount: $fileCount,
            );
        }

        $workflow = $this->makeWorkflow($sections);
        $groups = $workflow->getSplitGroups($sections, 2);

        $group0Weight = array_sum(array_map(static fn(TestSectionData $s): int => $s->fileCount, $groups[0]));
        $group1Weight = array_sum(array_map(static fn(TestSectionData $s): int => $s->fileCount, $groups[1]));

        // Groups should be balanced (difference < largest section weight)
        $this->assertLessThanOrEqual(10, abs($group0Weight - $group1Weight));
    }

    public function test_empty_sections_returns_empty(): void
    {
        $workflow = $this->makeWorkflow([]);
        $context = $this->makeContext();

        $result = $workflow->sectionsForRun($context);

        $this->assertSame([], $result);
    }
}
