<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\UploadedFile;

/**
 * Validated name and slug for a client company write.
 */
readonly class ClientCompanyData
{
    public function __construct(
        public string $name,
        public string $slug,
        /** A new logo to store (#257); null keeps the current one unless removeLogo. */
        public ?UploadedFile $logo = null,
        public bool $removeLogo = false,
    ) {}
}
