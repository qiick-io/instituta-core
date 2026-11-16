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
 * Validates create payload for a product under an owned client company.
 */
final class StoreClientProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        if (! $company instanceof Company) {
            return false;
        }

        abort_unless(Gate::allows('create', [Product::class, $company]), 403);

        return true;
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
            // Raster images only: an SVG can carry script (#257).
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
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
