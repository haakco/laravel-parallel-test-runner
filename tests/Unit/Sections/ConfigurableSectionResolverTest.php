<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Sections;

use Haakco\ParallelTestRunner\Data\SectionResolutionContext;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Sections\ConfigurableSectionResolver;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Override;

final class ConfigurableSectionResolverTest extends TestCase
{
    private string $fixtureTestsPath;

    private ConfigurableSectionResolver $resolver;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureTestsPath = realpath(__DIR__ . '/../../Fixtures/laravel-app/tests');
        $this->resolver = new ConfigurableSectionResolver();
    }

    public function test_discovers_sections_from_scan_paths(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [
                $this->fixtureTestsPath . '/Unit',
                $this->fixtureTestsPath . '/Feature',
            ],
            forceSplitDirectories: [],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ['max_files_per_section' => 10],
        );

        $sections = $this->resolver->resolve($context);

        $this->assertNotEmpty($sections);

        $names = array_map(static fn(TestSectionData $s): string => $s->name, $sections);
        $this->assertContains('Feature/Api', $names);
        $this->assertContains('Unit/Models', $names);
    }

    public function test_discovers_immediate_test_files_with_subdirectories(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [$this->fixtureTestsPath . '/Unit'],
            forceSplitDirectories: [],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ['max_files_per_section' => 10],
        );

        $sections = $this->resolver->resolve($context);
        $names = array_map(static fn(TestSectionData $s): string => $s->name, $sections);

        // Unit has subdirectories (Models) AND immediate files (ExampleUnitTest.php)
        // So immediate files get split as individual files
        $this->assertContains('Unit/ExampleUnitTest.php', $names);
        $this->assertContains('Unit/Models', $names);
    }

    public function test_discovers_test_files_that_only_contain_abstract_class_text_in_heredocs(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [$this->fixtureTestsPath . "/Unit"],
            forceSplitDirectories: [],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ["max_files_per_section" => 10],
        );

        $sections = $this->resolver->resolve($context);

        $allFiles = [];
        foreach ($sections as $section) {
            foreach ($section->files as $file) {
                $allFiles[] = basename($file);
            }
        }

        $this->assertContains("HeredocFixtureTest.php", $allFiles);
    }

    public function test_excludes_abstract_test_classes(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [$this->fixtureTestsPath . '/Unit'],
            forceSplitDirectories: [],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ['max_files_per_section' => 10],
        );

        $sections = $this->resolver->resolve($context);

        $allFiles = [];
        foreach ($sections as $section) {
            foreach ($section->files as $file) {
                $allFiles[] = basename($file);
            }
        }

        $this->assertNotContains('AbstractModelTest.php', $allFiles);
    }

    public function test_individual_mode_splits_all_files(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [$this->fixtureTestsPath . '/Unit'],
            forceSplitDirectories: [],
            individual: true,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ['max_files_per_section' => 10],
        );

        $sections = $this->resolver->resolve($context);

        foreach ($sections as $section) {
            $this->assertSame('file', $section->type);
            $this->assertSame(1, $section->fileCount);
        }
    }

    public function test_force_split_directories(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [$this->fixtureTestsPath . '/Unit'],
            forceSplitDirectories: ['Unit/Models'],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ['max_files_per_section' => 10],
        );

        $sections = $this->resolver->resolve($context);
        $names = array_map(static fn(TestSectionData $s): string => $s->name, $sections);

        // Models should be force-split into individual files
        $this->assertContains('Unit/Models/UserModelTest.php', $names);
        $this->assertContains('Unit/Models/OrderModelTest.php', $names);
        $this->assertNotContains('Unit/Models', $names);
    }

    public function test_max_files_per_section_triggers_split(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [$this->fixtureTestsPath . '/Unit'],
            forceSplitDirectories: [],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ['max_files_per_section' => 1],
        );

        $sections = $this->resolver->resolve($context);

        // With max 1 file per section, all leaf directories should split
        foreach ($sections as $section) {
            $this->assertSame('file', $section->type);
            $this->assertSame(1, $section->fileCount);
        }
    }

    public function test_skips_nonexistent_scan_paths(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: ['/nonexistent/path'],
            forceSplitDirectories: [],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: [],
        );

        $sections = $this->resolver->resolve($context);

        $this->assertSame([], $sections);
    }

    public function test_sections_sorted_alphabetically(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [
                $this->fixtureTestsPath . '/Unit',
                $this->fixtureTestsPath . '/Feature',
            ],
            forceSplitDirectories: [],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ['max_files_per_section' => 10],
        );

        $sections = $this->resolver->resolve($context);
        $names = array_map(static fn(TestSectionData $s): string => $s->name, $sections);

        $sorted = $names;
        sort($sorted);

        $this->assertSame($sorted, $names);
    }

    public function test_caches_results_for_same_context(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [$this->fixtureTestsPath . '/Unit'],
            forceSplitDirectories: [],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ['max_files_per_section' => 10],
        );

        $first = $this->resolver->resolve($context);
        $second = $this->resolver->resolve($context);

        $this->assertSame($first, $second);
    }

    public function test_filters_sections_to_explicit_test_files(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: [
                $this->fixtureTestsPath . '/Unit',
                $this->fixtureTestsPath . '/Feature',
            ],
            forceSplitDirectories: [],
            individual: true,
            sections: [],
            tests: [$this->fixtureTestsPath . '/Unit/Models/UserModelTest.php'],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: ['max_files_per_section' => 10],
        );

        $sections = $this->resolver->resolve($context);

        $this->assertCount(1, $sections);
        $this->assertSame('Unit/Models/UserModelTest.php', $sections[0]->name);
        $this->assertSame($this->fixtureTestsPath . '/Unit/Models/UserModelTest.php', $sections[0]->path);
    }

    public function test_does_not_treat_heredoc_fixture_text_as_an_abstract_test_class(): void
    {
        $tempDirectory = sys_get_temp_dir() . '/parallel-test-runner-' . uniqid('', true);
        mkdir($tempDirectory, 0777, true);

        $testFilePath = $tempDirectory . '/FixtureTextTest.php';
        file_put_contents($testFilePath, <<<'PHP_WRAP'
        <?php
        
        declare(strict_types=1);
        
        namespace Haakco\ParallelTestRunner\Tests\Fixtures;
        
        final class FixtureTextTest extends \PHPUnit\Framework\TestCase
        {
            public function test_fixture_text(): void
            {
                $fixture = <<<'INNER'
        abstract class ParentConstants
        {
        }
        INNER;
        
                self::assertNotSame('', $fixture);
            }
        }
        PHP_WRAP);

        try {
            $context = new SectionResolutionContext(
                scanPaths: [$tempDirectory],
                forceSplitDirectories: [],
                individual: false,
                sections: [],
                tests: [],
                filter: null,
                testSuite: null,
                splitTotal: null,
                splitGroup: null,
                additionalSuites: [],
                extraOptions: ['max_files_per_section' => 10],
            );

            $sections = $this->resolver->resolve($context);

            $this->assertCount(1, $sections);
            $this->assertSame(basename($tempDirectory), $sections[0]->name);
            $this->assertSame([$testFilePath], $sections[0]->files);
        } finally {
            unlink($testFilePath);
            rmdir($tempDirectory);
        }
    }

    public function test_does_not_skip_a_file_that_contains_an_abstract_helper_and_a_concrete_test(): void
    {
        $tempDirectory = sys_get_temp_dir() . '/parallel-test-runner-' . uniqid('', true);
        mkdir($tempDirectory, 0777, true);

        $testFilePath = $tempDirectory . '/ConcreteTest.php';
        file_put_contents($testFilePath, <<<'PHP_WRAP'
        <?php
        
        declare(strict_types=1);
        
        namespace Haakco\ParallelTestRunner\Tests\Fixtures;
        
        abstract class HelperTestCase extends \PHPUnit\Framework\TestCase
        {
        }
        
        final class ConcreteTest extends HelperTestCase
        {
            public function test_concrete_case(): void
            {
                self::assertTrue(true);
            }
        }
        PHP_WRAP);

        try {
            $context = new SectionResolutionContext(
                scanPaths: [$tempDirectory],
                forceSplitDirectories: [],
                individual: false,
                sections: [],
                tests: [],
                filter: null,
                testSuite: null,
                splitTotal: null,
                splitGroup: null,
                additionalSuites: [],
                extraOptions: ['max_files_per_section' => 10],
            );

            $sections = $this->resolver->resolve($context);

            $this->assertCount(1, $sections);
            $this->assertSame(basename($tempDirectory), $sections[0]->name);
            $this->assertSame([$testFilePath], $sections[0]->files);
        } finally {
            unlink($testFilePath);
            rmdir($tempDirectory);
        }
    }
}
