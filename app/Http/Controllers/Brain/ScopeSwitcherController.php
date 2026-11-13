<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Brain\Enums\ScopeTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SessionPerimeterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ScopeSwitcherController extends Controller
{
    public function __construct(private readonly SessionPerimeterService $sessions) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'string', 'in:organisation,company,product'],
            'scope_id' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $this->sessions->switchTo(
                $request,
                $user,
                ScopeTypeEnum::from($validated['scope_type']),
                $validated['scope_id'],
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['scope_id' => $exception->getMessage()]);
        }

        return back();
    }
}
