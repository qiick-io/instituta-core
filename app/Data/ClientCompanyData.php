<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Validated name and slug for a client company write.
 */
readonly class ClientCompanyData
{
    public function __construct(
        public string $name,
        public string $slug,
    ) {}
}
