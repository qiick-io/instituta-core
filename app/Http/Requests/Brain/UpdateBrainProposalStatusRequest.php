<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Session accept/reject for a brain write proposal.
 */
final class UpdateBrainProposalStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(['accepted', 'rejected'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
