<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Ai\Agents\ProposalDraftWriter;
use App\Brain\Data\PerimeterData;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\User;
use App\Services\PerimeterScopeColumnsService;
use InvalidArgumentException;

/**
 * Drafts the markdown for the propose drawer (#203): the whole item, one section, or a new file,
 * from the person's request and the current markdown in their perimeter. The result is only a
 * draft in the form; nothing is proposed or written here.
 *
 * @side-effect Calls the AI provider; logs an `ai_prompt` (surface `draft`) with its token usage.
 */
final class ProposalDraftService
{
    public function __construct(
        private readonly AiActivityLoggerService $activity = new AiActivityLoggerService,
        private readonly PerimeterScopeColumnsService $scopeColumns = new PerimeterScopeColumnsService,
    ) {}

    /**
     * @throws InvalidArgumentException When the item or section is not in the perimeter.
     */
    public function draft(User $user, PerimeterData $perimeter, string $request, ?string $stableKey, ?string $sectionAnchor): string
    {
        $current = $stableKey === null ? null : $this->currentMarkdown($perimeter, $stableKey, $sectionAnchor);

        $prompt = $current === null
            ? "Write a new brain document.\n\nRequest:\n{$request}"
            : "Current markdown:\n<<<\n{$current}\n>>>\n\nRequest:\n{$request}";

        $response = (new ProposalDraftWriter((int) config('ai.drafts.max_tokens', 2000)))->prompt($prompt);

        [$organisationId] = $this->scopeColumns->columnsFor($perimeter);
        $this->activity->prompt($user, $organisationId, $request, null, [
            'surface' => AiDailyBudgetService::SURFACE_DRAFT,
            'usage' => $response->usage->toArray(),
        ]);

        return $this->withoutFence(trim($response->text));
    }

    private function currentMarkdown(PerimeterData $perimeter, string $stableKey, ?string $sectionAnchor): string
    {
        $item = Item::query()->withinPerimeter($perimeter)->where('stable_key', $stableKey)->first();

        if (! $item instanceof Item) {
            throw new InvalidArgumentException('No item with this stable_key in your perimeter.');
        }

        if ($sectionAnchor !== null) {
            /** @var ItemSection|null $section */
            $section = $item->sections()->where('anchor', $sectionAnchor)->first();

            if ($section === null || $section->markdown === null) {
                throw new InvalidArgumentException('No section with this anchor in the item.');
            }

            return $section->markdown;
        }

        return $item->sections()->orderBy('position')->pluck('markdown')->filter()->implode("\n\n");
    }

    /**
     * Models often wrap markdown in a ```markdown fence despite the instructions; the form wants
     * the markdown itself.
     */
    private function withoutFence(string $text): string
    {
        if (preg_match('/\A```(?:markdown|md)?\s*\n(.*)\n```\z/s', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return $text;
    }
}
