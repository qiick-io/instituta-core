<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\AiConversationService;
use App\Services\Ai\AiDailyBudgetService;
use App\Services\Ai\AiProviderStatusService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Assistant page `/ai` (#203, app-ui `docs/chat-and-assistant.md`). Open while the assistant
 * is off, so it can say how to turn it on.
 */
final class AiPageController extends Controller
{
    public function __invoke(
        Request $request,
        AiProviderStatusService $providers,
        AiDailyBudgetService $dailyBudget,
        AiConversationService $conversations,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $status = $providers->status();

        return Inertia::render('ai/index', [
            'status' => [...$status, 'daily_usage' => $dailyBudget->usage($user)],
            'conversations' => $status['enabled'] ? $conversations->listFor($user) : [],
        ]);
    }
}
