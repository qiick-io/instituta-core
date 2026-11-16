<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ProposalActionEnum;

/**
 * Validated payload for opening a brain write proposal.
 *
 * `baseContentHash` / `baseSectionHash` are the versions the author read (from `instituta_get`
 * / `instituta_get_section`); `sectionAnchor` turns an update into a one-section edit whose
 * body is that section's new markdown (#198).
 */
readonly class ProposeBrainChangeData
{
    public function __construct(
        public ProposalActionEnum $action,
        public string $stableKey,
        public string $title,
        public string $path,
        public ?string $area,
        public ?string $body,
        public ?string $sourceId,
        public ?string $baseContentHash = null,
        public ?string $sectionAnchor = null,
        public ?string $baseSectionHash = null,
    ) {}

    /**
     * Builds the payload from validated REST input or MCP tool arguments (same keys).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $optionalString = static fn (string $key): ?string => isset($input[$key]) && is_string($input[$key]) && $input[$key] !== ''
            ? $input[$key]
            : null;

        return new self(
            action: ProposalActionEnum::from((string) $input['action']),
            stableKey: (string) $input['stable_key'],
            title: (string) $input['title'],
            path: (string) $input['path'],
            area: $optionalString('area'),
            body: isset($input['body']) && is_string($input['body']) ? $input['body'] : null,
            sourceId: $optionalString('source_id'),
            baseContentHash: $optionalString('base_content_hash'),
            sectionAnchor: $optionalString('section_anchor'),
            baseSectionHash: $optionalString('base_section_hash'),
        );
    }
}
