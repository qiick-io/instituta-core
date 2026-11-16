<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\UploadedFile;

/**
 * Validated fields for a product under a client company.
 */
readonly class ClientProductData
{
    public function __construct(
        public string $name,
        public string $slug,
        public bool $inheritsFromParent,
        /** A new logo to store (#257); null keeps the current one unless removeLogo. */
        public ?UploadedFile $logo = null,
        public bool $removeLogo = false,
    ) {}
}
