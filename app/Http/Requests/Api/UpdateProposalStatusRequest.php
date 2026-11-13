<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\ProposalStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Trust-boundary validation for accepting or rejecting a proposal over REST.
 */
class UpdateProposalStatusRequest extends FormRequest
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
            'status' => [
                'required',
                'string',
                Rule::in([
                    ProposalStatusEnum::Accepted->value,
                    ProposalStatusEnum::Rejected->value,
                ]),
            ],
        ];
    }
}
