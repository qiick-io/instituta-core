<?php

declare(strict_types=1);

namespace App\Http\Controllers\Brain;

use App\Brain\Enums\ScopeTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brain\SwitchScopeRequest;
use App\Models\User;
use App\Services\SessionPerimeterService;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Switches the signed-in user's session perimeter (organisation / company / product).
 */
final class ScopeSwitcherController extends Controller
{
    public function __construct(private readonly SessionPerimeterService $sessions) {}

    public function __invoke(SwitchScopeRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();
        abort_unless($user instanceof User, 401);

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
