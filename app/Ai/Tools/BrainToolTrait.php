<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Brain\Data\PerimeterData;
use App\Services\IndexedContractService;

/**
 * Shared shape of the assistant's brain tools (#203): bound to the signed-in user's session
 * perimeter, reading through the same IndexedContractService as MCP and REST (audited without an
 * API key), and returning JSON the model can quote.
 */
trait BrainToolTrait
{
    public function __construct(
        private readonly PerimeterData $perimeter,
        private readonly IndexedContractService $contract = new IndexedContractService,
    ) {}

    /**
     * @param  array<string, mixed>|list<mixed>|null  $payload
     */
    private function json(?array $payload, string $notFound = 'Not found in your perimeter.'): string
    {
        if ($payload === null) {
            return (string) json_encode(['error' => $notFound]);
        }

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
