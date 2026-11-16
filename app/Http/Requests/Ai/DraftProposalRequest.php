<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

/**
 * A request to draft the propose drawer's markdown with AI (#203). Same daily budget as the chat.
 */
class DraftProposalRequest extends SendAiChatMessageRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'request' => ['required', 'string', 'max:4000'],
            'stable_key' => ['nullable', 'string', 'max:255'],
            'section_anchor' => ['nullable', 'string', 'max:255'],
        ];
    }
}
