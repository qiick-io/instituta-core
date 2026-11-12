<?php

declare(strict_types=1);

namespace App\Brain\Contracts;

use App\Brain\Data\ItemData;
use App\Brain\Data\ManifestData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Data\SkippedFileData;
use App\Brain\Data\ValidationIssueData;

interface BrainAdapterContract
{
    public function name(): string;

    public function supports(string $relativePath, string $contents): bool;

    /**
     * @return array{
     *     items: list<ItemData>,
     *     skipped: list<SkippedFileData>,
     *     issues: list<ValidationIssueData>
     * }
     */
    public function readFile(
        string $absolutePath,
        string $relativePath,
        string $contents,
        ReadRequestData $request,
        ?ManifestData $manifest,
    ): array;
}
