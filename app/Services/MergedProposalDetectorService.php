<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Support\FrontmatterParser;
use App\Enums\ProposalActionEnum;
use App\Enums\ProposalMaterialisationStatusEnum;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Proposal;
use App\Models\Source;

/**
 * Closes the write loop (#199): after a sync, a materialised proposal whose change is now in
 * the source (its PR or branch was merged, or a person applied the local review) is marked
 * `merged`.
 *
 * The check compares content, not commits: a squash merge has a new commit, and a reviewer
 * may merge the change as proposed. If the reviewer edited it before merging, the proposal
 * stays `materialised` — it is not what was proposed any more.
 */
final class MergedProposalDetectorService
{
    public function __construct(
        private readonly FrontmatterParser $frontmatter = new FrontmatterParser,
    ) {}

    /**
     * @return int How many proposals were marked merged.
     */
    public function markMergedIn(Source $source): int
    {
        $waitingProposals = Proposal::query()
            ->where('source_id', $source->id)
            ->whereIn('materialisation_status', [
                ProposalMaterialisationStatusEnum::Materialised,
                ProposalMaterialisationStatusEnum::LocalReview,
            ])
            ->get();

        $mergedCount = 0;

        foreach ($waitingProposals as $proposal) {
            if (! $this->isInSource($source, $proposal)) {
                continue;
            }

            $proposal->forceFill([
                'materialisation_status' => ProposalMaterialisationStatusEnum::Merged,
                'merged_at' => now(),
            ])->save();
            $mergedCount++;
        }

        return $mergedCount;
    }

    private function isInSource(Source $source, Proposal $proposal): bool
    {
        $item = Item::query()
            ->where('source_id', $source->id)
            ->where('path', $proposal->path)
            ->first();

        if ($proposal->action === ProposalActionEnum::Delete) {
            return $item === null;
        }

        if ($item === null) {
            return false;
        }

        if (! $this->hasFrontmatterChanges($item, $proposal)) {
            return false;
        }

        // A frontmatter-only proposal changes no body (#219).
        if ($proposal->body === null || $proposal->body === '') {
            return true;
        }

        if ($proposal->section_anchor !== null) {
            /** @var ItemSection|null $section */
            $section = $item->sections()->where('anchor', $proposal->section_anchor)->first();

            return $section !== null && $section->markdown === rtrim($proposal->body ?? '');
        }

        // Same hash the sync stores: path + body without frontmatter (materialising keeps the
        // file's frontmatter when the proposal has none).
        $proposedBody = $this->frontmatter->parse(rtrim($proposal->body ?? '', "\r\n")."\n")['body'];

        return hash_equals($item->content_hash, hash('sha256', $proposal->path."\n".$proposedBody));
    }

    /**
     * The item's indexed frontmatter carries every change of the proposal (#219): set keys equal,
     * removed keys absent.
     */
    private function hasFrontmatterChanges(Item $item, Proposal $proposal): bool
    {
        $frontmatter = $item->frontmatter ?? [];

        foreach ($proposal->frontmatter_changes ?? [] as $key => $value) {
            if ($value === null ? array_key_exists($key, $frontmatter) : ($frontmatter[$key] ?? null) != $value) {
                return false;
            }
        }

        return true;
    }
}
