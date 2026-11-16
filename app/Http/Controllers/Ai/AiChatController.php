<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Ai\Agents\AppAssistant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\SendAiChatMessageRequest;
use App\Models\User;
use App\Services\Ai\AiActivityLoggerService;
use App\Services\Ai\AiConversationService;
use App\Services\PerimeterScopeColumnsService;
use App\Services\SessionPerimeterService;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Streams the assistant's answer over SSE for the in-app chat (#203), copied from externa-core's
 * AiChatController without attachments, context pickers and tool approvals (the brain tools
 * only read). The agent works inside the user's session perimeter.
 *
 * ponytail: sync SSE holds the PHP worker for the whole tool loop; nginx/fastcgi_read_timeout is
 * the outer ceiling. A queued agent with broadcasting is the upgrade path.
 */
final class AiChatController extends Controller
{
    public function __construct(
        private readonly SessionPerimeterService $sessions,
        private readonly AiConversationService $conversations,
        private readonly AiActivityLoggerService $activity,
        private readonly PerimeterScopeColumnsService $scopeColumns,
    ) {}

    public function __invoke(SendAiChatMessageRequest $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $perimeter = $this->sessions->requirePerimeter($request);
        [$organisationId] = $this->scopeColumns->columnsFor($perimeter);

        $validated = $request->validated();
        $conversationId = isset($validated['conversation_id']) && is_string($validated['conversation_id']) ? $validated['conversation_id'] : null;

        if ($conversationId !== null) {
            abort_if($this->conversations->findOwned($user, $conversationId) === null, Response::HTTP_NOT_FOUND);
        }

        if (! app()->runningUnitTests()) {
            @ini_set('max_execution_time', '0');
            set_time_limit(0);
        }

        $message = (string) $validated['message'];
        $this->activity->prompt($user, $organisationId, $message, $conversationId, ['surface' => 'chat']);

        $agent = new AppAssistant($perimeter);
        $conversationId !== null ? $agent->continue($conversationId, as: $user) : $agent->forUser($user);

        $stream = $agent->stream($message)->then(function (StreamedAgentResponse $response) use ($user, $organisationId): void {
            $this->activity->response(
                $user,
                $organisationId,
                trim((string) ($response->text ?? '')),
                $response->conversationId,
                $response->meta->provider ?? null,
                $response->meta->model ?? null,
            );
        });

        return $this->streamResponse($stream);
    }

    /**
     * Emits the agent stream as SSE, closing with the conversation id and `[DONE]`.
     */
    private function streamResponse(StreamableAgentResponse $stream): StreamedResponse
    {
        return response()->stream(function () use ($stream): void {
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            $emit = static function (array|string $payload): void {
                $data = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);

                echo 'data: '.$data."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            try {
                foreach ($stream as $event) {
                    $emit((string) $event);
                }

                if ($stream->conversationId !== null) {
                    $emit(['type' => 'conversation', 'conversation_id' => $stream->conversationId]);
                }

                $emit('[DONE]');
            } catch (Throwable $exception) {
                report($exception);

                $emit(['type' => 'error', 'message' => __('The assistant stopped: :reason', ['reason' => $exception->getMessage()])]);
                $emit('[DONE]');
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
