<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Brain\Data\PerimeterData;
use App\Models\ApiKey;
use App\Services\IndexedContractService;
use RuntimeException;

/**
 * Request-scoped access to the authenticated API key perimeter for HTTP MCP tools.
 */
final class HttpBrainContext
{
    public function __construct(
        private readonly IndexedContractService $contract = new IndexedContractService,
    ) {}

    public function contract(): IndexedContractService
    {
        return $this->contract;
    }

    public function perimeter(): PerimeterData
    {
        $perimeter = request()->attributes->get('instituta.perimeter');
        if (! $perimeter instanceof PerimeterData) {
            throw new RuntimeException('HTTP MCP perimeter is not set. Authenticate with a Bearer API key.');
        }

        return $perimeter;
    }

    public function apiKey(): ApiKey
    {
        $key = request()->attributes->get('instituta.api_key');
        if (! $key instanceof ApiKey) {
            throw new RuntimeException('HTTP MCP API key is not set. Authenticate with a Bearer API key.');
        }

        return $key;
    }
}
