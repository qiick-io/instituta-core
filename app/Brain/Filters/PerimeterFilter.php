<?php

declare(strict_types=1);

namespace App\Brain\Filters;

use App\Brain\Data\PerimeterData;
use App\Brain\Data\ResolvedEntryData;
use App\Brain\Data\ScopeEntryData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use InvalidArgumentException;

/**
 * Pure ACL filter: one perimeter per request, applied everywhere.
 */
final class PerimeterFilter
{
    /**
     * Visibility rank: higher = more sensitive. max_visibility must be >= item.
     *
     * @var array<string, int>
     */
    private const VISIBILITY_RANK = [
        'public' => 0,
        'internal' => 1,
        'restricted' => 2,
    ];

    /**
     * Case 5: a session/key is bound to exactly one scope — never widen.
     */
    public function assertSingleScope(PerimeterData $perimeter, ScopeTypeEnum $requestedType, string $requestedId): void
    {
        if ($perimeter->scopeType !== $requestedType || $perimeter->scopeId !== $requestedId) {
            throw new InvalidArgumentException(
                'A session or key is bound to one scope and cannot be widened.',
            );
        }
    }

    public function allowsEntry(PerimeterData $perimeter, ScopeEntryData $entry): bool
    {
        if (! $this->visibilityAllowed($perimeter->maxVisibility, $entry->visibility)) {
            return false;
        }

        return $perimeter->allowsLocation($entry->area, $entry->path);
    }

    public function allowsResolved(PerimeterData $perimeter, ResolvedEntryData $entry): bool
    {
        if (! $this->visibilityAllowed($perimeter->maxVisibility, $entry->visibility)) {
            return false;
        }

        return $perimeter->allowsLocation($entry->area, $entry->origin->path);
    }

    /**
     * @param  list<ScopeEntryData>  $entries
     * @return list<ScopeEntryData>
     */
    public function filterEntries(PerimeterData $perimeter, array $entries): array
    {
        return array_values(array_filter(
            $entries,
            fn (ScopeEntryData $entry): bool => $this->allowsEntry($perimeter, $entry),
        ));
    }

    /**
     * @param  list<ResolvedEntryData>  $entries
     * @return list<ResolvedEntryData>
     */
    public function filterResolved(PerimeterData $perimeter, array $entries): array
    {
        return array_values(array_filter(
            $entries,
            fn (ResolvedEntryData $entry): bool => $this->allowsResolved($perimeter, $entry),
        ));
    }

    private function visibilityAllowed(VisibilityEnum $max, VisibilityEnum $item): bool
    {
        return self::VISIBILITY_RANK[$item->value] <= self::VISIBILITY_RANK[$max->value];
    }
}
