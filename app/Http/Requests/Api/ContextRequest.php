<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Services\IndexedContractService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Trust-boundary validation for GET /api/v1/context (#224).
 */
final class ContextRequest extends FormRequest
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
            'scope' => ['required', 'string'],
            'q' => ['required', 'string'],
            'product_id' => ['nullable', 'string'],
            'area' => ['nullable', 'string'],
            'budget' => ['nullable', 'integer', 'min:'.IndexedContractService::CONTEXT_MIN_BUDGET, 'max:'.IndexedContractService::CONTEXT_MAX_BUDGET],
        ];
    }
}
