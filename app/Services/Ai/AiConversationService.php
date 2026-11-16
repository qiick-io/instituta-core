<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;
use Carbon\CarbonInterface;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * The signed-in user's assistant conversations (#203). A conversation is only ever found for
 * its own participant, so another user's id answers "not found".
 */
final class AiConversationService
{
    private const int LIST_LIMIT = 50;

    public function findOwned(User $user, string $conversationId): ?Conversation
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->first();
    }

    /**
     * Newest first.
     *
     * @return list<array{id: string, title: string|null, updated_at: string|null}>
     */
    public function listFor(User $user): array
    {
        $conversations = Conversation::query()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->orderByDesc('updated_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        $rows = [];
        foreach ($conversations as $conversation) {
            $rows[] = [
                'id' => (string) $conversation->getKey(),
                'title' => $this->titleOf($conversation),
                'updated_at' => $this->isoDate($conversation->getAttribute('updated_at')),
            ];
        }

        return $rows;
    }

    public function titleOf(Conversation $conversation): ?string
    {
        $title = $conversation->getAttribute('title');

        return is_string($title) && $title !== '' ? $title : null;
    }

    /**
     * The messages of a conversation in order, with the tool steps the UI turns into answer parts.
     *
     * @return list<array{id: string, role: string, content: string, steps: mixed, usage: mixed, created_at: string|null}>
     */
    public function messagesOf(Conversation $conversation): array
    {
        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($messages as $message) {
            $rows[] = [
                'id' => (string) $message->getKey(),
                'role' => (string) $message->getAttribute('role'),
                'content' => (string) $message->getAttribute('content'),
                'steps' => $message->getAttribute('steps'),
                'usage' => $message->getAttribute('usage'),
                'created_at' => $this->isoDate($message->getAttribute('created_at')),
            ];
        }

        return $rows;
    }

    public function delete(Conversation $conversation): void
    {
        ConversationMessage::query()->where('conversation_id', $conversation->getKey())->delete();
        $conversation->delete();
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }
}
