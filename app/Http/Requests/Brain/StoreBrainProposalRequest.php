<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Http\Requests\Api\ProposeBrainChangeRequest;

/**
 * A person proposing a change from the web UI (#200): the same payload as REST / MCP, so
 * every surface opens proposals through the same rules.
 */
class StoreBrainProposalRequest extends ProposeBrainChangeRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
