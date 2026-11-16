<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Services\IndexedContractService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;

/**
 * The input of instituta_get_sections, shared by the HTTP and local tools (#225): a list of
 * at most {@see IndexedContractService::MAX_REQUESTED_SECTIONS} `{item, anchor}` pairs, checked at the boundary.
 */
trait ReadsRequestedSectionsTrait
{
    private const string INVALID_SECTIONS_MESSAGE = 'sections must be a list of 1 to 20 {item, anchor} objects with non-empty strings.';

    /**
     * @return array<string, mixed>
     */
    private function requestedSectionsSchema(JsonSchema $schema): array
    {
        return [
            'sections' => $schema->array()
                ->items($schema->object([
                    'item' => $schema->string()->description('Item id, stable_key, or path')->required(),
                    'anchor' => $schema->string()->description('Section anchor')->required(),
                ]))
                ->min(1)
                ->max(IndexedContractService::MAX_REQUESTED_SECTIONS)
                ->description('The sections to read, at most 20')
                ->required(),
        ];
    }

    /**
     * The requested pairs, or null when the input does not fit the schema.
     *
     * @return list<array{item: string, anchor: string}>|null
     */
    private function requestedSections(Request $request): ?array
    {
        $rawSections = $request->get('sections');

        if (! is_array($rawSections) || ! array_is_list($rawSections) || $rawSections === [] || count($rawSections) > IndexedContractService::MAX_REQUESTED_SECTIONS) {
            return null;
        }

        $requestedSections = [];

        foreach ($rawSections as $rawSection) {
            if (! is_array($rawSection)) {
                return null;
            }

            $item = $rawSection['item'] ?? null;
            $anchor = $rawSection['anchor'] ?? null;

            if (! is_string($item) || $item === '' || ! is_string($anchor) || $anchor === '') {
                return null;
            }

            $requestedSections[] = ['item' => $item, 'anchor' => $anchor];
        }

        return $requestedSections;
    }
}
