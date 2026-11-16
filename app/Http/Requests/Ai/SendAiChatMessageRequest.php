<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Models\User;
use App\Services\Ai\AiDailyBudgetService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A question for the assistant (#203), copied from externa-core without attachments and tool
 * approvals (the brain tools only read).
 *
 * The daily budget is enforced in `authorize()` so it answers 429 before validation and before
 * the provider is called.
 */
class SendAiChatMessageRequest extends FormRequest
{
    /**
     * @throws HttpResponseException When the user's daily prompt cap or token budget is used up.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $exhaustedMessage = app(AiDailyBudgetService::class)->exhaustedMessage($user);

        if ($exhaustedMessage !== null) {
            throw new HttpResponseException(response()->json(['message' => $exhaustedMessage], Response::HTTP_TOO_MANY_REQUESTS));
        }

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:20000'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
