<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Agency portal product create/edit deep-links; drawers open on the products index.
 */
final class ClientProductFormController extends Controller
{
    public function create(Request $request, Company $company): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        Gate::authorize('create', [Product::class, $company]);

        return to_route('clients.products.index', [
            'company' => $company,
            'new' => 1,
        ]);
    }

    public function edit(Request $request, Company $company, Product $product): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if ($product->company_id !== $company->id) {
            abort(404);
        }

        Gate::authorize('update', $product);

        return to_route('clients.products.index', [
            'company' => $company,
            'edit' => $product->id,
        ]);
    }
}
