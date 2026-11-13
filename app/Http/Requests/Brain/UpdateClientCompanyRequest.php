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
 * Validates update payload for an owned client company.
 */
final class UpdateClientCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $company = $this->route('company');

        if (! $user instanceof User || ! $company instanceof Company) {
            return false;
        }

        return app(BrainClientsService::class)->canOwnerManage($user, $company);
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
