<?php

declare(strict_types=1);

namespace App\Brain\Data;

/**
 * In-memory resolution input for a product (file-level; no database).
 */
final readonly class ResolutionContextData
{
    /**
     * @param  list<ScopeEntryData>  $organisationEntries
     * @param  list<ScopeEntryData>  $companyEntries
     * @param  list<ScopeEntryData>  $productEntries
     * @param  list<SharedSourceRefData>  $sharedSourceRefs
     * @param  list<ScopeEntryData>  $sharedSourceEntries  Entries from shared sources (case 6), keyed later by source id
     * @param  list<ProductGroupData>  $productGroups
     */
    public function __construct(
        public string $organisationId,
        public string $companyId,
        public string $productId,
        public bool $inheritsFromParent = true,
        public array $organisationEntries = [],
        public array $companyEntries = [],
        public array $productEntries = [],
        public array $sharedSourceRefs = [],
        public array $sharedSourceEntries = [],
        public array $productGroups = [],
    ) {}
}
