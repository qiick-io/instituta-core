<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Per-user daily AI budget (#203): the prompt cap (`ai.daily_prompt_limit`) and the token budget
 * (`ai.daily_token_budget`), both 0 = off. Copied from externa-core's AiDailyBudgetService.
 *
 * "Today" starts at midnight in the app timezone. Prompts are today's `ai_prompt` activity rows
 * (chat and drafts). Tokens are the input + output tokens of today's assistant messages plus the
 * usage recorded on draft prompts, which keep no conversation.
 */
final class AiDailyBudgetService
{
    public const string SURFACE_CHAT = 'chat';

    public const string SURFACE_DRAFT = 'draft';

    public function promptLimit(): int
    {
        return max(0, (int) config('ai.daily_prompt_limit', 0));
    }

    public function tokenBudget(): int
    {
        return max(0, (int) config('ai.daily_token_budget', 0));
    }

    /**
     * Why the user cannot send another prompt today, or null while within budget.
     */
    public function exhaustedMessage(User $user): ?string
    {
        $promptLimit = $this->promptLimit();
        if ($promptLimit > 0 && $this->promptsUsedToday($user) >= $promptLimit) {
            return __('Daily AI prompt limit reached.');
        }

        $tokenBudget = $this->tokenBudget();
        if ($tokenBudget > 0 && $this->tokensUsedToday($user) >= $tokenBudget) {
            return __('Daily AI token budget reached.');
        }

        return null;
    }

    /**
     * Today's usage against the configured limits, or null when no limit is set.
     *
     * @return array{prompts_used: int, prompt_limit: int|null, tokens_used: int, token_budget: int|null}|null
     */
    public function usage(User $user): ?array
    {
        $promptLimit = $this->promptLimit();
        $tokenBudget = $this->tokenBudget();

        if ($promptLimit === 0 && $tokenBudget === 0) {
            return null;
        }

        return [
            'prompts_used' => $this->promptsUsedToday($user),
            'prompt_limit' => $promptLimit > 0 ? $promptLimit : null,
            'tokens_used' => $this->tokensUsedToday($user),
            'token_budget' => $tokenBudget > 0 ? $tokenBudget : null,
        ];
    }

    public function promptsUsedToday(User $user): int
    {
        return $this->todaysPrompts($user)->count();
    }

    public function tokensUsedToday(User $user): int
    {
        $conversationTokens = ConversationMessage::query()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', now()->startOfDay())
            ->pluck('usage')
            ->sum(fn (mixed $usage): int => $this->totalTokens($usage));

        $draftTokens = $this->todaysPrompts($user)
            ->where('properties->surface', self::SURFACE_DRAFT)
            ->pluck('properties')
            ->sum(fn (mixed $properties): int => $this->totalTokens(data_get($properties, 'usage')));

        return (int) $conversationTokens + (int) $draftTokens;
    }

    /**
     * @return Builder<Activity>
     */
    private function todaysPrompts(User $user): Builder
    {
        return Activity::query()
            ->where('log_name', 'ai')
            ->where('event', 'ai_prompt')
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay());
    }

    /**
     * Input + output tokens of a laravel/ai usage payload (`{"input_tokens":…,"output_tokens":…}`).
     */
    private function totalTokens(mixed $usage): int
    {
        if (is_string($usage)) {
            $usage = json_decode($usage, true);
        }

        if (! is_array($usage)) {
            return 0;
        }

        $input = $usage['input_tokens'] ?? 0;
        $output = $usage['output_tokens'] ?? 0;

        return (is_numeric($input) ? (int) $input : 0) + (is_numeric($output) ? (int) $output : 0);
    }
}
