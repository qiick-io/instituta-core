<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Http\Requests\Concerns\ValidatesAreaLimitsTrait;
use App\Services\BrainKeysService;
use App\Services\SessionPerimeterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An owner creates an API key from the Keys page (#201), for themself or for a new agent.
 */
class StoreApiKeyRequest extends FormRequest
{
    use ValidatesAreaLimitsTrait;

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
            // Limits inside the owner's own (#334); the action refuses anything past them.
            ...self::areaLimitRules('areas', 'path_prefix', $this->areaOptions()),
        ];
    }

    /**
     * @return list<string>
     */
    private function areaOptions(): array
    {
        $perimeter = app(SessionPerimeterService::class)->optionalPerimeter($this);

        return $perimeter === null ? [] : app(BrainKeysService::class)->areaOptionsFor($perimeter);
    }
}
