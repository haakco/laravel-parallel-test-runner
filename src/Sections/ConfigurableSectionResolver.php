<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Sections;

use FilesystemIterator;
use Haakco\ParallelTestRunner\Contracts\SectionResolverInterface;
use Haakco\ParallelTestRunner\Data\SectionResolutionContext;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Illuminate\Support\Facades\File;
use Override;

final class ConfigurableSectionResolver implements SectionResolverInterface
{
    /** @var array<string, list<TestSectionData>> */
    private array $cache = [];

    /**
     * @return list<TestSectionData>
     */
    #[Override]
    public function resolve(SectionResolutionContext $context): array
    {
        $cacheKey = $this->buildCacheKey($context);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $maxFilesPerSection = (int) ($context->extraOptions['max_files_per_section']
            ?? config('parallel-test-runner.sections.max_files_per_section', 10));

        $sections = [];

        foreach ($context->scanPaths as $scanPath) {
            $absolutePath = $this->resolveAbsolutePath($scanPath);

            if (! File::exists($absolutePath)) {
                continue;
            }

            $prefix = basename($scanPath);

            $this->discoverDirectoryTests(
                basePath: $absolutePath,
                prefix: $prefix,
                sections: $sections,
                individual: $context->individual,
                maxFilesPerSection: $maxFilesPerSection,
                forceSplitDirectories: $context->forceSplitDirectories,
            );
        }

        if ($context->tests !== []) {
            $sections = $this->filterByExplicitTests($sections, $context->tests);
        }

        usort($sections, static fn(TestSectionData $a, TestSectionData $b): int => strcmp($a->name, $b->name));

        $this->cache[$cacheKey] = $sections;

        return $sections;
    }

    /**
     * @param list<TestSectionData> $sections
     * @param list<string> $forceSplitDirectories
     */
    private function discoverDirectoryTests(
        string $basePath,
        string $prefix,
        array &$sections,
        bool $individual,
        int $maxFilesPerSection,
        array $forceSplitDirectories,
    ): void {
        $directories = File::directories($basePath);
        $immediateTestFiles = $this->getImmediateTestFiles($basePath);

        $hasSubdirectories = $directories !== [];
        $shouldForceSplit = in_array($prefix, $forceSplitDirectories, true);

        if (! $hasSubdirectories && $immediateTestFiles !== []) {
            $this->appendDirectorySection(
                sections: $sections,
                prefix: $prefix,
                basePath: $basePath,
                immediateFiles: $immediateTestFiles,
                individual: $individual,
                maxFilesPerSection: $maxFilesPerSection,
                shouldForceSplit: $shouldForceSplit,
            );
        } elseif ($hasSubdirectories && $immediateTestFiles !== []) {
            $this->appendImmediateFiles($sections, $prefix, $immediateTestFiles);
        }

        foreach ($directories as $directory) {
            $dirName = basename((string) $directory);
            $this->discoverDirectoryTests(
                basePath: $directory,
                prefix: $prefix . '/' . $dirName,
                sections: $sections,
                individual: $individual,
                maxFilesPerSection: $maxFilesPerSection,
                forceSplitDirectories: $forceSplitDirectories,
            );
        }
    }

    /**
     * @param list<TestSectionData> $sections
     * @param list<string> $immediateFiles
     */
    private function appendDirectorySection(
        array &$sections,
        string $prefix,
        string $basePath,
        array $immediateFiles,
        bool $individual,
        int $maxFilesPerSection,
        bool $shouldForceSplit,
    ): void {
        $shouldSplit = $individual || $shouldForceSplit || count($immediateFiles) > $maxFilesPerSection;

        if ($shouldSplit) {
            $this->appendImmediateFiles($sections, $prefix, $immediateFiles);

            return;
        }

        $sections[] = new TestSectionData(
            name: $prefix,
            type: 'directory',
            path: $basePath,
            files: array_values($immediateFiles),
            fileCount: count($immediateFiles),
        );
    }

    /**
     * @param list<TestSectionData> $sections
     * @param list<string> $files
     */
    private function appendImmediateFiles(array &$sections, string $prefix, array $files): void
    {
        foreach ($files as $filePath) {
            $sections[] = new TestSectionData(
                name: $prefix . '/' . basename($filePath),
                type: 'file',
                path: $filePath,
                files: [$filePath],
                fileCount: 1,
            );
        }
    }

    /** @return list<string> */
    private function getImmediateTestFiles(string $directory): array
    {
        $files = [];
        $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            if ($file->isFile()
                && str_ends_with($file->getFilename(), 'Test.php')
                && ! $this->isAbstractTestClass($file->getPathname())
            ) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function isAbstractTestClass(string $filePath): bool
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return false;
        }

        $tokens = token_get_all($content);
        $classBraceDepths = [];
        $braceDepth = 0;
        $pendingAbstract = false;
        $waitingForClassBody = false;
        $pendingTopLevelClassIsAbstract = false;
        $topLevelClassAbstractStates = [];
        $previousMeaningfulToken = null;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $tokenId = $token[0];

                if (in_array($tokenId, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                    continue;
                }

                if ($tokenId === T_ABSTRACT && $classBraceDepths === []) {
                    $pendingAbstract = true;
                    $previousMeaningfulToken = $tokenId;

                    continue;
                }

                if ($tokenId === T_CLASS && $previousMeaningfulToken !== T_NEW && $previousMeaningfulToken !== '::') {
                    $waitingForClassBody = true;
                    $pendingTopLevelClassIsAbstract = $pendingAbstract && $classBraceDepths === [];
                    $pendingAbstract = false;
                    $previousMeaningfulToken = $tokenId;

                    continue;
                }

                $pendingAbstract = false;
                $previousMeaningfulToken = $tokenId;

                continue;
            }

            if ($token === '{') {
                $braceDepth++;

                if ($waitingForClassBody) {
                    $classBraceDepths[] = $braceDepth;
                    $topLevelClassAbstractStates[] = $pendingTopLevelClassIsAbstract;
                    $waitingForClassBody = false;
                    $pendingTopLevelClassIsAbstract = false;
                }
            } elseif ($token === '}') {
                if ($classBraceDepths !== [] && end($classBraceDepths) === $braceDepth) {
                    array_pop($classBraceDepths);
                }

                $braceDepth = max(0, $braceDepth - 1);
            }

            if (trim($token) !== '') {
                $pendingAbstract = false;
                $previousMeaningfulToken = $token;
            }
        }

        return $topLevelClassAbstractStates !== []
            && ! in_array(false, $topLevelClassAbstractStates, true);
    }

    private function resolveAbsolutePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param list<TestSectionData> $sections
     * @param list<string> $tests
     * @return list<TestSectionData>
     */
    private function filterByExplicitTests(array $sections, array $tests): array
    {
        $normalizedTests = array_map(
            $this->normalizePathForComparison(...),
            $tests,
        );

        return array_values(array_filter(
            $sections,
            function (TestSectionData $section) use ($normalizedTests): bool {
                if (in_array($this->normalizePathForComparison($section->path), $normalizedTests, true)) {
                    return true;
                }

                return array_any(
                    $section->files,
                    fn(string $file): bool => in_array($this->normalizePathForComparison($file), $normalizedTests, true),
                );
            }
        ));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function normalizePathForComparison(string $path): string
    {
        $resolvedPath = $this->resolveAbsolutePath($path);
        $realPath = realpath($resolvedPath);

        if ($realPath !== false) {
            $resolvedPath = $realPath;
        }

        $normalizedPath = str_replace('\\', '/', $resolvedPath);

        if (preg_match('#^//([^/]+)/([^/]+)(/.*)?$#', $normalizedPath, $matches) === 1) {
            return '//' . $matches[1] . '/' . $matches[2] . $this->normalizePathSuffix($matches[3] ?? '/');
        }

        if (preg_match('#^([A-Za-z]:)(/.*)?$#', $normalizedPath, $matches) === 1) {
            return strtoupper($matches[1]) . $this->normalizePathSuffix($matches[2] ?? '/');
        }

        return $this->normalizePathSuffix($normalizedPath);
    }

    private function normalizePathSuffix(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn(string $segment): bool => $segment !== '',
        ));
        $normalizedSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($normalizedSegments !== [] && end($normalizedSegments) !== '..') {
                    array_pop($normalizedSegments);

                    continue;
                }

                if (! $isAbsolute) {
                    $normalizedSegments[] = $segment;
                }

                continue;
            }

            $normalizedSegments[] = $segment;
        }

        $collapsed = implode('/', $normalizedSegments);

        if ($isAbsolute) {
            return '/' . $collapsed;
        }

        return $collapsed;
    }

    private function buildCacheKey(SectionResolutionContext $context): string
    {
        $normalizedSplitDirs = $context->forceSplitDirectories;
        sort($normalizedSplitDirs);

        $normalizedScanPaths = $context->scanPaths;
        sort($normalizedScanPaths);

        $normalizedTests = $context->tests;
        sort($normalizedTests);

        return implode(':', [
            $context->individual ? '1' : '0',
            (string) ($context->extraOptions['max_files_per_section'] ?? config('parallel-test-runner.sections.max_files_per_section', 10)),
            md5(implode('|', $normalizedSplitDirs)),
            md5(implode('|', $normalizedScanPaths)),
            md5(implode('|', $normalizedTests)),
        ]);
    }
}
