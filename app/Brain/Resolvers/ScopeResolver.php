<?php

declare(strict_types=1);

namespace App\Brain\Resolvers;

use App\Brain\Data\OriginData;
use App\Brain\Data\ProductGroupData;
use App\Brain\Data\ResolutionContextData;
use App\Brain\Data\ResolvedEntryData;
use App\Brain\Data\ScopeEntryData;

/**
 * Resolves product knowledge: product > company/client > organisation.
 * Every value keeps its origin. Shared sources are referenced by id + version.
 */
final class ScopeResolver
{
    /**
     * @return list<ResolvedEntryData>
     */
    public function resolve(ResolutionContextData $context): array
    {
        $layers = [];

        if ($context->inheritsFromParent) {
            $layers[] = $this->indexByKey($context->organisationEntries);
            $layers[] = $this->indexByKey($context->companyEntries);
        }

        $layers[] = $this->indexByKey($context->productEntries);
        $layers[] = $this->indexByKey($this->sharedEntriesForProduct($context));

        /** @var array<string, ResolvedEntryData> $resolved */
        $resolved = [];

        foreach ($layers as $layer) {
            foreach ($layer as $key => $entry) {
                $resolved[$key] = $this->toResolved($entry);
            }
        }

        ksort($resolved);

        return array_values($resolved);
    }

    /**
     * Explicit groups only (case 3). Inheritance never implies group membership.
     *
     * @param  list<ProductGroupData>  $groups
     * @return list<ProductGroupData>
     */
    public function groupsForProduct(string $productId, array $groups): array
    {
        return array_values(array_filter(
            $groups,
            static fn (ProductGroupData $group): bool => $group->contains($productId),
        ));
    }

    /**
     * @param  list<ScopeEntryData>  $entries
     * @return array<string, ScopeEntryData>
     */
    private function indexByKey(array $entries): array
    {
        $indexed = [];
        foreach ($entries as $entry) {
            $indexed[$entry->resolutionKey] = $entry;
        }

        return $indexed;
    }

    /**
     * @return list<ScopeEntryData>
     */
    private function sharedEntriesForProduct(ResolutionContextData $context): array
    {
        $allowed = [];
        foreach ($context->sharedSourceRefs as $ref) {
            if ($ref->productId !== $context->productId) {
                continue;
            }
            $allowed[$ref->sourceId.'@'.$ref->sourceVersionId] = $ref;
        }

        if ($allowed === []) {
            return [];
        }

        $out = [];
        foreach ($context->sharedSourceEntries as $entry) {
            if ($entry->sharedSourceId === null || $entry->sharedSourceVersionId === null) {
                continue;
            }
            $key = $entry->sharedSourceId.'@'.$entry->sharedSourceVersionId;
            if (! isset($allowed[$key])) {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }

    private function toResolved(ScopeEntryData $entry): ResolvedEntryData
    {
        return new ResolvedEntryData(
            resolutionKey: $entry->resolutionKey,
            title: $entry->title,
            summary: $entry->summary,
            visibility: $entry->visibility,
            origin: new OriginData(
                scopeType: $entry->scopeType,
                scopeId: $entry->scopeId,
                source: $entry->source,
                path: $entry->path,
                commit: $entry->commit,
            ),
            fromSharedSource: $entry->fromSharedSource,
        );
    }
}
