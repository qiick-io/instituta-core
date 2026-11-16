<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Mcp\Support\HttpBrainContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * "Propose a change to item Y" (#202): the item's sections with the version markers a
 * proposal needs, and the steps that keep a change reviewable and conflict-safe (#198).
 */
#[Name('instituta_propose_change')]
#[Description('Prepare a change to one brain item: its sections with their markdown and version hashes, and the steps to open a proposal a person can review.')]
class InstitutaProposeChangePrompt extends Prompt
{
    public function __construct(private readonly HttpBrainContext $brain = new HttpBrainContext) {}

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('item', 'Item id, stable_key or path', required: true),
            new Argument('goal', 'What should change, in one or two sentences'),
        ];
    }

    public function handle(Request $request): Response
    {
        $itemId = (string) $request->get('item');
        $goal = trim((string) ($request->get('goal') ?? ''));

        $item = $this->brain->contract()->get($this->brain->perimeter(), $this->brain->apiKey(), $itemId);

        if ($item === null) {
            return Response::error('Item not found in your perimeter.');
        }

        $sections = array_map(static fn (array $section): array => [
            'anchor' => $section['anchor'],
            'heading' => $section['heading'],
            'section_hash' => $section['section_hash'] ?? null,
            'markdown' => $section['markdown'] ?? null,
        ], $item['sections'] ?? []);

        $json = json_encode([
            'stable_key' => $item['stable_key'],
            'path' => $item['origin']['path'] ?? null,
            'content_hash' => $item['origin']['content_hash'] ?? null,
            'sections' => $sections,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        $goalLine = $goal !== '' ? "Goal: {$goal}\n\n" : '';

        return Response::text(<<<TEXT
            {$goalLine}Prepare a change to {$item['stable_key']}.

            Steps:
            1. Pick the smallest unit: if only one section changes, edit that section's markdown (keep its heading line).
            2. Call instituta_propose with action "update", the stable_key and path below, and either
               section_anchor + base_section_hash (one section) or base_content_hash (whole file).
            3. Say in the title what changes and why. A person reviews the diff and accepts it; never accept your own proposal.
            4. If the proposal is refused as stale, re-read the item and start again.

            Item:
            {$json}
            TEXT);
    }
}
