<?php

declare(strict_types=1);

namespace App\Brain\Data;

/**
 * Case 6: products reference org-level shared sources by id + version, never copy.
 */
final readonly class SharedSourceRefData
{
    public function __construct(
        public string $sourceId,
        public string $sourceVersionId,
        public string $productId,
    ) {}
}
