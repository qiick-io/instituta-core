<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Brain\Data\PerimeterData;
use App\Contracts\SearchIndexContract;
use RuntimeException;

/**
 * MySQL and unknown drivers refuse search clearly in v0.1.0.
 */
final class UnsupportedSearchDriver implements SearchIndexContract
{
    public function search(
        PerimeterData $perimeter,
        string $query,
        array $allowedItemIds,
        ?string $area = null,
        int $limit = 20,
    ): array {
        unset($perimeter, $query, $allowedItemIds, $area, $limit);

        throw new RuntimeException('search driver not supported');
    }
}
