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
 * Agency portal clients list; create / edit open as drawers on the index.
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
