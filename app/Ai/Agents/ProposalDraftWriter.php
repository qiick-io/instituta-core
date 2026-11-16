<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * One-shot agent that drafts the markdown of a brain change from a short request (#203), shaped
 * after externa-core's ItemDraftWriter. Its answer only fills the propose drawer: a person
 * reviews it, the proposal is opened by the person, and a person accepts it.
 */
class ProposalDraftWriter implements Agent
{
    use Promptable;

    public function __construct(private readonly ?int $maxTokenLimit = null) {}

    public function provider(): string
    {
        $provider = config('ai.assistant.provider');

        return is_string($provider) && $provider !== '' ? $provider : 'openai-compatible';
    }

    /**
     * A local model can need well over a minute for a full draft.
     */
    public function timeout(): int
    {
        return 300;
    }

    /**
     * Read by laravel/ai when building the text generation options (null = provider default).
     */
    public function maxTokens(): ?int
    {
        return $this->maxTokenLimit !== null && $this->maxTokenLimit > 0 ? $this->maxTokenLimit : null;
    }

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
            You edit the markdown of one document in a company brain (rules, decisions, brand, design system, plans).

            Rules:
            - Answer with the new markdown only: no preface, no explanation, no code fence around it.
            - When current markdown is given, change only what the request asks; keep everything else as it is, including headings, links and formatting.
            - When you edit one section, keep its heading line first, at the same level.
            - Write in the language of the current markdown, or of the request when the document is new.
            - Never invent facts, numbers, names or links the request does not give.
            INSTRUCTIONS;
    }
}
