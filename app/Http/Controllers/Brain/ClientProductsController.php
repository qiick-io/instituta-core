<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Actions\CreateClientProductAction;
use App\Actions\UpdateClientProductAction;
use App\Data\ClientProductData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\StoreClientProductRequest;
use App\Http\Requests\Brain\UpdateClientProductRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\BrainClientProductsService;
use App\Services\BrainClientsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agency portal product list and create / edit under a client company.
 */
final class ClientProductsController extends Controller
{
    public function __construct(
        private readonly BrainClientsService $clients,
        private readonly BrainClientProductsService $products,
    ) {}

    public function index(Request $request, Company $company): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return Inertia::render(
            'brain/clients/products',
            $this->products->listFor($user, $company),
        );
    }

    public function create(Request $request, Company $company): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $this->clients->assertOwnerCanManage($user, $company);

        return Inertia::render('brain/clients/products/create', [
            'client' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
            ],
        ]);
    }

    public function store(
        StoreClientProductRequest $request,
        Company $company,
        CreateClientProductAction $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validated();

        $action->execute($user, $company, new ClientProductData(
            name: $validated['name'],
            slug: $validated['slug'],
            inheritsFromParent: (bool) ($validated['inherits_from_parent'] ?? true),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return to_route('brain.clients.products.index', $company);
    }

    public function edit(Request $request, Company $company, Product $product): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $this->clients->assertOwnerCanManage($user, $company);

        if ($product->company_id !== $company->id) {
            abort(404);
        }

        return Inertia::render('brain/clients/products/edit', [
            'client' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
            ],
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'inherits_from_parent' => $product->inherits_from_parent,
            ],
        ]);
    }

    public function update(
        UpdateClientProductRequest $request,
        Company $company,
        Product $product,
        UpdateClientProductAction $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validated();

        $action->execute($user, $company, $product, new ClientProductData(
            name: $validated['name'],
            slug: $validated['slug'],
            inheritsFromParent: (bool) ($validated['inherits_from_parent'] ?? true),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product updated.')]);

        return to_route('brain.clients.products.index', $company);
    }
}
