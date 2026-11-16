<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Trust-boundary validation for GET /api/v1/search.
 */
final class SearchRequest extends FormRequest
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
            'area' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'mode' => ['nullable', 'string', 'in:hybrid,keyword,semantic'],
        ];
    }
}
