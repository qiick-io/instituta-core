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
 * Validates create payload for a product under an owned client company.
 */
final class StoreClientProductRequest extends FormRequest
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
        $companyId = $company instanceof Company ? $company->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->where(
                    fn ($query) => $query->where('company_id', $companyId),
                ),
            ],
            'inherits_from_parent' => ['sometimes', 'boolean'],
        ];
    }
}
