<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Brain\Data\PerimeterData;

/**
 * Full-text search behind the public contract. Perimeter is applied by the caller
 * (allowed item ids); drivers only rank and snippet within that set.
 */
interface SearchIndexContract
{
    /**
     * @param  list<string>  $allowedItemIds
     * @return list<array{
     *     id: string,
     *     title: string,
     *     summary: string,
     *     anchor: string|null,
     *     heading: string|null,
     *     snippet: string,
     *     score: float,
     *     citation: array{source: string, path: string, section: string|null, commit: string|null}
     * }>
     */
    public function search(
        PerimeterData $perimeter,
        string $query,
        array $allowedItemIds,
        ?string $area = null,
        int $limit = 20,
    ): array;
}
