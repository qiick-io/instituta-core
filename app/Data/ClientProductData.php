<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Validated fields for a product under a client company.
 */
readonly class ClientProductData
{
    public function __construct(
        public string $name,
        public string $slug,
        public bool $inheritsFromParent,
    ) {}
}
