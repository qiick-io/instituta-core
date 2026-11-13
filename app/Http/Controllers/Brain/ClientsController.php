<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Actions\CreateClientCompanyAction;
use App\Actions\UpdateClientCompanyAction;
use App\Data\ClientCompanyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\StoreClientCompanyRequest;
use App\Http\Requests\Brain\UpdateClientCompanyRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\BrainClientsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agency portal clients list and create / edit forms (case 4).
 */
final class ClientsController extends Controller
{
    public function __construct(
        private readonly BrainClientsService $clients,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return Inertia::render('brain/clients', $this->clients->listFor($user));
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if ($this->clients->ownerOrganisationIdFor($user) === null) {
            abort(403);
        }

        return Inertia::render('brain/clients/create');
    }

    public function store(
        StoreClientCompanyRequest $request,
        CreateClientCompanyAction $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validated();

        $action->execute($user, new ClientCompanyData(
            name: $validated['name'],
            slug: $validated['slug'],
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client created.')]);

        return to_route('brain.clients.index');
    }

    public function edit(Request $request, Company $company): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $this->clients->assertOwnerCanManage($user, $company);

        return Inertia::render('brain/clients/edit', [
            'client' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
            ],
        ]);
    }

    public function update(
        UpdateClientCompanyRequest $request,
        Company $company,
        UpdateClientCompanyAction $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validated();

        $action->execute($user, $company, new ClientCompanyData(
            name: $validated['name'],
            slug: $validated['slug'],
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client updated.')]);

        return to_route('brain.clients.index');
    }
}
