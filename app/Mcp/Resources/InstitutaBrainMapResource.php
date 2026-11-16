<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Support\HttpBrainContext;
use App\Services\BrainMapService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

/**
 * The brain map of the key's perimeter (#202): items per area, gaps included, so a client can
 * attach "what this brain holds" without a tool call.
 */
#[Name('brain-map')]
#[Uri('instituta://map')]
#[MimeType('application/json')]
#[Description('Areas of the brain in your perimeter with how many items each holds; empty areas are gaps.')]
class InstitutaBrainMapResource extends Resource
{
    public function __construct(
        private readonly HttpBrainContext $brain = new HttpBrainContext,
        private readonly BrainMapService $map = new BrainMapService,
    ) {}

    public function handle(Request $request): Response
    {
        $json = json_encode(['areas' => $this->map->areas($this->brain->perimeter())], JSON_UNESCAPED_SLASHES) ?: '{}';

        return Response::text($json);
    }
}
