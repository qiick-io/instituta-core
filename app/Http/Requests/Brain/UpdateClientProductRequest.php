<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\BrainClientsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates update payload for a product under an owned client company.
 */
final class UpdateClientProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $company = $this->route('company');
        $product = $this->route('product');

        if (
            ! $user instanceof User
            || ! $company instanceof Company
            || ! $product instanceof Product
        ) {
            return false;
        }

        return app(BrainClientsService::class)->canOwnerManage($user, $company)
            && $product->company_id === $company->id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $company = $this->route('company');
        $product = $this->route('product');
        $companyId = $company instanceof Company ? $company->id : null;
        $productId = $product instanceof Product ? $product->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($productId),
            ],
            'inherits_from_parent' => ['sometimes', 'boolean'],
        ];
    }
}
