<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\OutlineBrainItemTool;
use App\Ai\Tools\ReadBrainSectionTool;
use App\Ai\Tools\ResolveProductTool;
use App\Ai\Tools\SearchBrainTool;
use App\Ai\Tools\SimilarBrainSectionsTool;
use App\Brain\Data\PerimeterData;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * The in-app assistant (#203): answers questions from the company brain, inside the signed-in
 * user's perimeter, with citations. Shaped after externa-core's AppAssistant (provider from
 * `ai.assistant.provider`, conversations remembered per user), with read-only brain tools: it
 * never writes; changes go through proposal drafts a person reviews.
 */
class AppAssistant implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(
        private readonly PerimeterData $perimeter,
    ) {}

    /**
     * The provider the assistant runs on (`ai.assistant.provider`, the local endpoint by default).
     */
    public function provider(): string
    {
        $provider = config('ai.assistant.provider');

        return is_string($provider) && $provider !== '' ? $provider : 'openai-compatible';
    }

    /**
     * Search, outline and a few sections, then the answer.
     */
    public function maxSteps(): int
    {
        return max(1, (int) config('ai.assistant.max_steps', 12));
    }

    /**
     * Per-request timeout to the provider (seconds); a local model on a laptop is slow.
     */
    public function timeout(): int
    {
        return max(30, (int) config('ai.assistant.timeout', 300));
    }

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
            You are the assistant of a company brain: its brand, rules, decisions, design systems, glossary and plans.

            Language:
            - Reply in the language of the user's latest message. If unsure, use English.

            How to answer:
            - Always look things up with the tools before answering; never answer from memory or invent rules, paths or ids.
            - Read progressively: SearchBrainTool (or SimilarBrainSectionsTool when available) to find items, OutlineBrainItemTool to see their sections, ReadBrainSectionTool for the part you need. For a specific product, use ResolveProductTool.
            - Cite every source you rely on as `path#section` (from the citation in the tool result).
            - Prefer entries whose status is "active". If you rely on a draft, deprecated or superseded entry, say so, and point to its superseded_by entry when there is one. Mention when an entry's review is overdue.
            - If the brain has nothing on the question, say so plainly and suggest which area could hold it. Do not fill the gap with general knowledge presented as the company's rule.
            - You can only read. If the user wants to change the brain, tell them to use "Propose change" on the item (or ask you for a draft there); never claim you changed anything.
            - Be concise: a short answer first, then the details with citations.
            INSTRUCTIONS;
    }

    /**
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        $tools = [
            new SearchBrainTool($this->perimeter),
            new OutlineBrainItemTool($this->perimeter),
            new ReadBrainSectionTool($this->perimeter),
            new ResolveProductTool($this->perimeter),
        ];

        if ((bool) config('ai.embeddings.enabled')) {
            $tools[] = new SimilarBrainSectionsTool($this->perimeter);
        }

        return $tools;
    }
}
