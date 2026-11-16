<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Services\SessionPerimeterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An owner creates an API key from the Keys page (#201), for themself or for a new agent.
 */
class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(SessionPerimeterService::class)->optionalPerimeter($this)?->role === RoleEnum::Owner;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'principal' => ['required', Rule::in(['me', 'agent'])],
            'agent_name' => ['nullable', 'required_if:principal,agent', 'string', 'max:255'],
            'role' => ['required', Rule::enum(RoleEnum::class)],
            'max_visibility' => ['required', Rule::enum(VisibilityEnum::class)],
            'can_write' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
