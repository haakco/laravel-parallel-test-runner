<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Sections;

use Haakco\ParallelTestRunner\Contracts\SectionResolverInterface;
use Haakco\ParallelTestRunner\Data\SectionResolutionContext;
use Haakco\ParallelTestRunner\Data\TestSectionData;

final readonly class SectionResolutionWorkflow
{
    public function __construct(private SectionResolverInterface $resolver) {}

    /**
     * List all sections for the given context.
     *
     * @return list<TestSectionData>
     */
    public function listSections(SectionResolutionContext $context): array
    {
        return $this->resolver->resolve($context);
    }

    /**
     * Resolve sections for a test run, applying filters and split-group selection.
     *
     * @return list<TestSectionData>
     */
    public function sectionsForRun(SectionResolutionContext $context): array
    {
        $sections = $this->resolver->resolve($context);

        if ($context->sections !== []) {
            $sections = $this->filterByRequestedSections($sections, $context->sections);
        }

        if ($sections === []) {
            return [];
        }

        if ($context->filter !== null && $context->filter !== '') {
            $sections = $this->applyFilter($sections, $context->filter);
        }

        if ($sections === []) {
            return [];
        }

        return $this->maybeApplySplit($sections, $context->splitTotal, $context->splitGroup);
    }

    /**
     * Distribute sections into split groups using LPT algorithm.
     *
     * @param list<TestSectionData> $sections
     * @return list<list<TestSectionData>>
     */
    public function getSplitGroups(array $sections, int $totalGroups): array
    {
        /** @var list<list<TestSectionData>> $groups */
        $groups = array_fill(0, $totalGroups, []);
        $groupWeights = array_fill(0, $totalGroups, 0);

        $sectionsWithWeight = [];
        foreach ($sections as $section) {
            $sectionsWithWeight[] = [
                'section' => $section,
                'weight' => count($section->files),
            ];
        }

        usort($sectionsWithWeight, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

        foreach ($sectionsWithWeight as $item) {
            $minWeight = min($groupWeights);
            $minGroupIndex = (int) array_search($minWeight, $groupWeights, true);

            $groups[$minGroupIndex][] = $item['section'];
            $groupWeights[$minGroupIndex] += $item['weight'];
        }

        return $groups;
    }

    /**
     * @param list<TestSectionData> $sections
     * @param list<string> $requestedSections
     * @return list<TestSectionData>
     */
    private function filterByRequestedSections(array $sections, array $requestedSections): array
    {
        $matched = [];

        foreach ($requestedSections as $requested) {
            foreach ($sections as $section) {
                if ($section->name === $requested || str_starts_with($section->name, $requested)) {
                    $key = $section->name . '|' . $section->path;
                    $matched[$key] = $section;
                }
            }
        }

        return array_values($matched);
    }

    /**
     * @param list<TestSectionData> $sections
     * @return list<TestSectionData>
     */
    private function applyFilter(array $sections, string $filter): array
    {
        $normalized = strtolower($filter);

        $filtered = array_values(array_filter(
            $sections,
            static fn(TestSectionData $section): bool => str_contains(strtolower($section->name), $normalized)
                || array_any($section->files, static fn(string $file): bool => str_contains(strtolower($file), $normalized)),
        ));

        return $filtered !== [] ? $filtered : $sections;
    }

    /**
     * @param list<TestSectionData> $sections
     * @return list<TestSectionData>
     */
    private function maybeApplySplit(array $sections, ?int $splitTotal, ?int $splitGroup): array
    {
        if ($splitTotal === null || $splitGroup === null) {
            return $sections;
        }

        $groups = $this->getSplitGroups($sections, $splitTotal);
        $groupIndex = $splitGroup - 1;

        if (! isset($groups[$groupIndex])) {
            return [];
        }

        return $groups[$groupIndex];
    }
}
