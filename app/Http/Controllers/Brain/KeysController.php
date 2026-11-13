<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\BrainKeysService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only API keys list for organisation owners (create/revoke stay in Artisan).
 */
final class KeysController extends Controller
{
    public function __construct(
        private readonly BrainKeysService $keys,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        Gate::authorize('viewAny', ApiKey::class);

        return Inertia::render('brain/keys', $this->keys->listFor($user));
    }
}
