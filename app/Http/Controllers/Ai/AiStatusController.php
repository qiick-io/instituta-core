<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\AiDailyBudgetService;
use App\Services\Ai\AiProviderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whether the assistant is on and its provider reachable, plus the user's daily usage (#203),
 * copied from externa-core's AiStatusController. Open even when the assistant is off, so the UI
 * can say how to turn it on.
 */
final class AiStatusController extends Controller
{
    public function __invoke(Request $request, AiProviderStatusService $providers, AiDailyBudgetService $dailyBudget): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            ...$providers->status(),
            'daily_usage' => $dailyBudget->usage($user),
        ]);
    }
}
