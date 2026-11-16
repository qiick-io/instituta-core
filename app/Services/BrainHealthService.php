<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ItemStatusEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Source;
use Illuminate\Support\Collection;

/**
 * The brain's health (#231): what the last syncs left wrong, read from the index they wrote, so
 * it always matches the latest sync. Reader issues and skipped files come from each source's
 * last sync report; broken internal links, `superseded_by` pointing nowhere, several active
 * entries claiming one resolution key in the same scope, and overdue reviews from the index.
 * Every issue names its item and path and suggests a fix.
 */
final class BrainHealthService
{
    public const string CODE_READER_ISSUE = 'reader_issue';

    public const string CODE_SKIPPED_FILE = 'skipped_file';

    public const string CODE_BROKEN_LINK = 'broken_link';

    public const string CODE_MISSING_SUPERSEDED_BY = 'missing_superseded_by';

    public const string CODE_DUPLICATE_RESOLUTION_KEY = 'duplicate_resolution_key';

    public const string CODE_REVIEW_OVERDUE = 'review_overdue';

    /**
     * @return array{issues: list<array{code: string, severity: string, item: string|null, stable_key: string|null, path: string, message: string, suggested_fix: string}>, counts: array<string, int>, error_count: int, warning_count: int}
     */
    public function check(PerimeterData $perimeter): array
    {
        $items = Item::query()->withinPerimeter($perimeter)->get();

        $issues = [
            ...$this->sourceReportIssues($perimeter),
            ...$this->brokenLinks($items),
            ...$this->missingSupersededBy($items),
            ...$this->duplicateResolutionKeys($items),
            ...$this->overdueReviews($items),
        ];

        $counts = array_count_values(array_column($issues, 'code'));
        $severities = array_count_values(array_column($issues, 'severity'));

        return [
            'issues' => $issues,
            'counts' => $counts,
            'error_count' => $severities['error'] ?? 0,
            'warning_count' => $severities['warning'] ?? 0,
        ];
    }

    /**
     * @return list<array{code: string, severity: string, item: string|null, stable_key: string|null, path: string, message: string, suggested_fix: string}>
     */
    private function sourceReportIssues(PerimeterData $perimeter): array
    {
        $sources = Source::query()->where(match ($perimeter->scopeType) {
            ScopeTypeEnum::Organisation => 'organisation_id',
            ScopeTypeEnum::Company => 'company_id',
            ScopeTypeEnum::Product => 'product_id',
        }, $perimeter->scopeId)->get();

        $issues = [];

        foreach ($sources as $source) {
            $report = $source->last_sync_report ?? [];

            foreach (is_array($report['issues'] ?? null) ? $report['issues'] : [] as $readerIssue) {
                if (! is_array($readerIssue)) {
                    continue;
                }

                $issues[] = $this->issue(
                    self::CODE_READER_ISSUE,
                    ($readerIssue['severity'] ?? 'error') === 'error' ? 'error' : 'warning',
                    null,
                    (string) ($readerIssue['path'] ?? ''),
                    $source->name.': '.(string) ($readerIssue['message'] ?? $readerIssue['code'] ?? 'Unreadable file.'),
                    'Fix the file so it parses (frontmatter YAML, headings), then sync the source.',
                );
            }

            foreach (is_array($report['skipped'] ?? null) ? $report['skipped'] : [] as $skipped) {
                $path = is_array($skipped) ? (string) ($skipped['path'] ?? '') : (string) $skipped;
                $reason = is_array($skipped) ? (string) ($skipped['reason'] ?? 'skipped') : 'skipped';

                $issues[] = $this->issue(
                    self::CODE_SKIPPED_FILE,
                    'warning',
                    null,
                    $path,
                    $source->name.': the sync skipped this file ('.$reason.').',
                    'Move the file inside the brain format, or exclude it on purpose.',
                );
            }
        }

        return $issues;
    }

    /**
     * Links to brain files the index cannot resolve. External URLs and links to non-markdown
     * files (images, PDFs) are not checked.
     *
     * @param  Collection<int, Item>  $items
     * @return list<array{code: string, severity: string, item: string|null, stable_key: string|null, path: string, message: string, suggested_fix: string}>
     */
    private function brokenLinks(Collection $items): array
    {
        $itemsById = $items->keyBy('id');

        return array_values(ItemLink::query()
            ->whereIn('from_item_id', $itemsById->keys()->all())
            ->whereNull('to_item_id')
            ->get()
            ->filter(static fn (ItemLink $link): bool => self::isInternalTarget($link->target))
            ->map(function (ItemLink $link) use ($itemsById): array {
                /** @var Item $item */
                $item = $itemsById->get($link->from_item_id);

                return $this->issue(
                    self::CODE_BROKEN_LINK,
                    'warning',
                    $item,
                    $item->path,
                    'Link to "'.$link->target.'" points to no file in the brain.',
                    'Correct the link to an existing path, or create the missing entry.',
                );
            })
            ->all());
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return list<array{code: string, severity: string, item: string|null, stable_key: string|null, path: string, message: string, suggested_fix: string}>
     */
    private function missingSupersededBy(Collection $items): array
    {
        $superseding = $items->filter(static fn (Item $item): bool => $item->superseded_by !== null);

        if ($superseding->isEmpty()) {
            return [];
        }

        $existingKeys = Item::query()
            ->whereIn('organisation_id', $superseding->pluck('organisation_id')->unique()->all())
            ->whereIn('stable_key', $superseding->pluck('superseded_by')->unique()->all())
            ->pluck('stable_key')
            ->all();

        return array_values($superseding
            ->reject(static fn (Item $item): bool => in_array($item->superseded_by, $existingKeys, true))
            ->map(fn (Item $item): array => $this->issue(
                self::CODE_MISSING_SUPERSEDED_BY,
                'error',
                $item,
                $item->path,
                'superseded_by "'.$item->superseded_by.'" names no entry.',
                'Set superseded_by to the stable key (path without .md) of the entry that replaces it.',
            ))
            ->all());
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return list<array{code: string, severity: string, item: string|null, stable_key: string|null, path: string, message: string, suggested_fix: string}>
     */
    private function duplicateResolutionKeys(Collection $items): array
    {
        return array_values($items
            ->filter(static fn (Item $item): bool => $item->status === ItemStatusEnum::Active && ($item->resolution_key ?? '') !== '')
            ->groupBy(static fn (Item $item): string => implode('|', [$item->organisation_id, $item->company_id, $item->product_id, $item->resolution_key]))
            ->filter(static fn (Collection $group): bool => $group->count() > 1)
            ->flatMap(fn (Collection $group): array => $group->map(fn (Item $item): array => $this->issue(
                self::CODE_DUPLICATE_RESOLUTION_KEY,
                'error',
                $item,
                $item->path,
                'Active entries claim resolution key "'.$item->resolution_key.'" in the same scope: '.$group->pluck('path')->implode(', ').'.',
                'Keep one active entry per key: mark the others superseded (superseded_by) or give them their own key.',
            ))->all())
            ->all());
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return list<array{code: string, severity: string, item: string|null, stable_key: string|null, path: string, message: string, suggested_fix: string}>
     */
    private function overdueReviews(Collection $items): array
    {
        return array_values($items
            ->filter(static fn (Item $item): bool => $item->status === ItemStatusEnum::Active && $item->review_date !== null && $item->review_date->isPast())
            ->map(fn (Item $item): array => $this->issue(
                self::CODE_REVIEW_OVERDUE,
                'warning',
                $item,
                $item->path,
                'Review was due on '.$item->review_date?->toDateString().'.',
                'Review the entry and move review_date forward, or mark it deprecated.',
            ))
            ->all());
    }

    private static function isInternalTarget(string $target): bool
    {
        $target = trim($target);

        if ($target === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
            return false;
        }

        $extension = pathinfo((string) parse_url($target, PHP_URL_PATH), PATHINFO_EXTENSION);

        return $extension === '' || strtolower($extension) === 'md';
    }

    /**
     * @return array{code: string, severity: string, item: string|null, stable_key: string|null, path: string, message: string, suggested_fix: string}
     */
    private function issue(string $code, string $severity, ?Item $item, string $path, string $message, string $suggestedFix): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'item' => $item?->id,
            'stable_key' => $item?->stable_key,
            'path' => $path,
            'message' => $message,
            'suggested_fix' => $suggestedFix,
        ];
    }
}
