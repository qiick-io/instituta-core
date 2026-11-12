<?php

declare(strict_types=1);

namespace App\Brain\Data;

/**
 * Case 3: grouping is only explicit — never implied by inheritance.
 */
final readonly class ProductGroupData
{
    /**
     * @param  list<string>  $productIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $productIds,
    ) {}

    public function contains(string $productId): bool
    {
        return in_array($productId, $this->productIds, true);
    }
}
