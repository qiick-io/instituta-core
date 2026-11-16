<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates update payload for an owned client company.
 */
final class UpdateClientCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        if (! $company instanceof Company) {
            return false;
        }

        abort_unless(Gate::allows('update', $company), 403);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $company = $this->route('company');
        $organisationId = $company instanceof Company ? $company->organisation_id : null;
        $companyId = $company instanceof Company ? $company->id : null;

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
                Rule::unique('companies', 'slug')
                    ->where(fn ($query) => $query->where('organisation_id', $organisationId))
                    ->ignore($companyId),
            ],
        ];
    }
}
