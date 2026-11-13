<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates update payload for a product under an owned client company.
 */
final class UpdateClientProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        $product = $this->route('product');

        if (! $company instanceof Company || ! $product instanceof Product) {
            return false;
        }

        if ($product->company_id !== $company->id) {
            return false;
        }

        abort_unless(Gate::allows('update', $product), 403);

        return true;
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
