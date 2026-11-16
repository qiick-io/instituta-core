<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;

/**
 * Activity rows for the assistant (log name `ai`), copied from externa-core's AiActivityLogger.
 *
 * The `ai_prompt` rows are what the daily prompt cap counts. Unlike externa-core, a response is
 * logged without its text: answers quote brain content, and the activity log never holds it.
 */
final class AiActivityLoggerService
{
    private const int PROMPT_PREVIEW_LENGTH = 300;

    /**
     * @param  array<string, mixed>  $extra  e.g. `surface` and token `usage` for drafts, which keep no conversation
     */
    public function prompt(User $user, ?string $organisationId, string $prompt, ?string $conversationId = null, array $extra = []): void
    {
        activity('ai')
            ->causedBy($user)
            ->event('ai_prompt')
            ->withProperties([
                'organisation_id' => $organisationId,
                'conversation_id' => $conversationId,
                'prompt' => mb_substr($prompt, 0, self::PROMPT_PREVIEW_LENGTH),
                ...$extra,
            ])
            ->log('ai_prompt');
    }

    public function response(User $user, ?string $organisationId, string $response, ?string $conversationId, ?string $provider, ?string $model): void
    {
        activity('ai')
            ->causedBy($user)
            ->event('ai_response')
            ->withProperties([
                'organisation_id' => $organisationId,
                'conversation_id' => $conversationId,
                'response_length' => mb_strlen($response),
                'provider' => $provider,
                'model' => $model,
            ])
            ->log('ai_response');
    }
}
