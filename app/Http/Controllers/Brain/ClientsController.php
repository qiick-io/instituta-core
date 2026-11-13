<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrainClientsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agency portal clients list (case 4).
 */
final class ClientsController extends Controller
{
    public function __construct(
        private readonly BrainClientsService $clients,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return Inertia::render('brain/clients', $this->clients->listFor($user));
    }
}
