<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\ProposalActionEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Trust-boundary validation for opening a brain write proposal over REST.
 */
class ProposeBrainChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::enum(ProposalActionEnum::class)],
            'stable_key' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'path' => ['required', 'string', 'max:512'],
            'area' => ['nullable', 'string', 'max:64'],
            'body' => ['nullable', 'string'],
            'source_id' => ['nullable', 'string', 'uuid'],
            'base_content_hash' => ['nullable', 'string', 'size:64'],
            'section_anchor' => ['nullable', 'string', 'max:255'],
            'base_section_hash' => ['nullable', 'string', 'size:64'],
            'frontmatter' => ['nullable', 'array'],
        ];
    }
}
