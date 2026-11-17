<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProposalActionEnum;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Proposal;

/**
 * What a proposal would change, line by line, so a person reviews a diff instead of approving
 * blind (#200). "Current" is the indexed content now (the section's markdown, or the item's
 * sections joined for a whole-file change; frontmatter is not indexed, so it is not shown).
 *
 * A plain longest-common-subsequence diff: brain files are small, and no diff library is a
 * production dependency. Above {@see self::MAX_LINES} lines per side it returns no lines.
 */
final class ProposalDiffService
{
    private const int MAX_LINES = 1500;

    /**
     * @return array{
     *     current: string|null,
     *     proposed: string|null,
     *     is_too_large: bool,
     *     lines: list<array{type: 'same'|'added'|'removed', text: string}>
     * }
     */
    public function forProposal(Proposal $proposal): array
    {
        $current = $proposal->action === ProposalActionEnum::Create ? null : $this->currentText($proposal);
        $proposed = $proposal->action === ProposalActionEnum::Delete ? null : rtrim($proposal->body ?? '');

        $currentLines = $current === null || $current === '' ? [] : explode("\n", $current);
        $proposedLines = $proposed === null || $proposed === '' ? [] : explode("\n", $proposed);
        $isTooLarge = count($currentLines) > self::MAX_LINES || count($proposedLines) > self::MAX_LINES;

        return [
            'current' => $current,
            'proposed' => $proposed,
            'is_too_large' => $isTooLarge,
            'lines' => $isTooLarge ? [] : $this->diffLines($currentLines, $proposedLines),
        ];
    }

    private function currentText(Proposal $proposal): ?string
    {
        $item = Item::query()
            ->where('organisation_id', $proposal->organisation_id)
            ->where('stable_key', $proposal->stable_key)
            ->when($proposal->source_id !== null, fn ($query) => $query->where('source_id', $proposal->source_id))
            ->widestScopeFirst()
            ->first();

        if (! $item instanceof Item) {
            return null;
        }

        if ($proposal->section_anchor !== null) {
            /** @var ItemSection|null $section */
            $section = $item->sections()->where('anchor', $proposal->section_anchor)->first();

            return $section?->markdown;
        }

        return $item->sections()
            ->orderBy('position')
            ->pluck('markdown')
            ->filter(fn (mixed $markdown): bool => is_string($markdown) && $markdown !== '')
            ->implode("\n\n");
    }

    /**
     * @param  list<string>  $currentLines
     * @param  list<string>  $proposedLines
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    private function diffLines(array $currentLines, array $proposedLines): array
    {
        $currentCount = count($currentLines);
        $proposedCount = count($proposedLines);

        // commonLengths[i][j]: longest common subsequence of the suffixes from i and j.
        $commonLengths = array_fill(0, $currentCount + 1, array_fill(0, $proposedCount + 1, 0));
        for ($currentIndex = $currentCount - 1; $currentIndex >= 0; $currentIndex--) {
            for ($proposedIndex = $proposedCount - 1; $proposedIndex >= 0; $proposedIndex--) {
                $commonLengths[$currentIndex][$proposedIndex] = $currentLines[$currentIndex] === $proposedLines[$proposedIndex]
                    ? $commonLengths[$currentIndex + 1][$proposedIndex + 1] + 1
                    : max($commonLengths[$currentIndex + 1][$proposedIndex], $commonLengths[$currentIndex][$proposedIndex + 1]);
            }
        }

        $lines = [];
        $currentIndex = 0;
        $proposedIndex = 0;

        while ($currentIndex < $currentCount && $proposedIndex < $proposedCount) {
            if ($currentLines[$currentIndex] === $proposedLines[$proposedIndex]) {
                $lines[] = ['type' => 'same', 'text' => $currentLines[$currentIndex]];
                $currentIndex++;
                $proposedIndex++;
            } elseif ($commonLengths[$currentIndex + 1][$proposedIndex] >= $commonLengths[$currentIndex][$proposedIndex + 1]) {
                $lines[] = ['type' => 'removed', 'text' => $currentLines[$currentIndex]];
                $currentIndex++;
            } else {
                $lines[] = ['type' => 'added', 'text' => $proposedLines[$proposedIndex]];
                $proposedIndex++;
            }
        }

        for (; $currentIndex < $currentCount; $currentIndex++) {
            $lines[] = ['type' => 'removed', 'text' => $currentLines[$currentIndex]];
        }

        for (; $proposedIndex < $proposedCount; $proposedIndex++) {
            $lines[] = ['type' => 'added', 'text' => $proposedLines[$proposedIndex]];
        }

        return $lines;
    }
}
