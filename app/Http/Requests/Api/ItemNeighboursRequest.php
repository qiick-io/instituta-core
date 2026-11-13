<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Trust-boundary validation for GET /api/v1/items/{id}/neighbours.
 */
final class ItemNeighboursRequest extends FormRequest
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
            'depth' => ['nullable', 'integer', 'min:1', 'max:2'],
        ];
    }
}
