<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Models\Company;
use App\Models\User;
use App\Services\BrainClientsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates create payload for a client company under the caller's organisation.
 */
final class StoreClientCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Company::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $organisationId = $user instanceof User
            ? app(BrainClientsService::class)->ownerOrganisationIdFor($user)
            : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            // Raster images only: an SVG can carry script (#257).
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('companies', 'slug')->where(
                    fn ($query) => $query->where('organisation_id', $organisationId),
                ),
            ],
        ];
    }
}
