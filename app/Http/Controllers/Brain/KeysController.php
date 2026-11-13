<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrainKeysService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

        return Inertia::render('brain/keys', $this->keys->listFor($user));
    }
}
