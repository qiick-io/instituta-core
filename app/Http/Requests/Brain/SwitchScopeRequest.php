<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Trust-boundary validation for switching the UI session perimeter.
 */
final class SwitchScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope_type' => ['required', 'string', 'in:organisation,company,product'],
            'scope_id' => ['required', 'string'],
        ];
    }
}
