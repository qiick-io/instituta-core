<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\AiConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's assistant conversations (#203): list, open, delete. Only the user's own
 * conversations are ever found.
 */
final class AiConversationController extends Controller
{
    public function __construct(private readonly AiConversationService $conversations) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['conversations' => $this->conversations->listFor($this->user($request))]);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        $model = $this->conversations->findOwned($this->user($request), $conversation);
        abort_if($model === null, 404);

        return response()->json([
            'id' => (string) $model->getKey(),
            'title' => $this->conversations->titleOf($model),
            'messages' => $this->conversations->messagesOf($model),
        ]);
    }

    public function destroy(Request $request, string $conversation): JsonResponse
    {
        $model = $this->conversations->findOwned($this->user($request), $conversation);
        abort_if($model === null, 404);

        $this->conversations->delete($model);

        return response()->json(['message' => __('Conversation deleted.')]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
