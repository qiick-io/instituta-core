<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrainResolvedService;
use App\Services\SessionPerimeterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ResolvedController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly BrainResolvedService $resolved,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $principal = $this->sessions->personPrincipal($user);
        $perimeter = $principal === null ? null : $this->sessions->resolve($request, $principal);

        if ($perimeter === null) {
            return Inertia::render('brain/resolved', [
                'hasPerimeter' => false,
                'products' => [],
                'product_id' => null,
                'entries' => [],
                'token_estimate' => 0,
                'agent_view' => [],
            ]);
        }

        $productId = $request->string('product')->toString();
        $productId = $productId === '' ? null : $productId;

        $payload = $this->resolved->resolve($perimeter, $productId);

        return Inertia::render('brain/resolved', [
            'hasPerimeter' => true,
            'products' => $this->resolved->products($perimeter),
            ...$payload,
        ]);
    }
}
