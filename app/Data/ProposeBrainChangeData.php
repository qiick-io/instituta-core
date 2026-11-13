<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ProposalActionEnum;

/**
 * Validated payload for opening a brain write proposal.
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
    ) {}
}
