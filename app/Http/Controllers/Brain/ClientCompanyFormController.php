<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Agency portal client create/edit deep-links; drawers open on the clients index.
 */
final class ClientCompanyFormController extends Controller
{
    public function create(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        Gate::authorize('create', Company::class);

        return to_route('clients.index', ['new' => 1]);
    }

    public function edit(Request $request, Company $company): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        Gate::authorize('update', $company);

        return to_route('clients.index', ['edit' => $company->id]);
    }
}
