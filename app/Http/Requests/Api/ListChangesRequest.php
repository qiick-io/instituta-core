<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Trust-boundary validation for GET /api/v1/changes (#228).
 */
final class ListChangesRequest extends FormRequest
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
            'since' => ['required', 'string', 'max:64'],
            'area' => ['nullable', 'string'],
            'cursor' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
