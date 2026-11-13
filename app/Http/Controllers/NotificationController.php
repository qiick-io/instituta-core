<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Notifications\MarkNotificationsReadRequest;
use App\Http\Requests\Notifications\MarkNotificationsUnreadRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Exposes paginated in-app notifications and read-state mutations for the current user.
 *
 * Copied from externa-core; every query goes through the authenticated user's notifications.
 */
class NotificationController extends Controller
{
    /**
     * List the authenticated user's notifications with pagination metadata.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $paginator = $user
            ->notifications()
            ->paginate(20);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (DatabaseNotification $notification): array => $this->serializeNotification($notification))
                ->values()
                ->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * Return the count of unread notifications for the authenticated user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return response()->json([
            'count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark selected notifications, or all unread notifications, as read.
     *
     * ponytail: empty or missing ids (or all=true) marks every unread notification.
     */
    public function markRead(MarkNotificationsReadRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->authenticatedUser($request);
        $query = $user->unreadNotifications();

        if (! empty($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        }

        $query->update(['read_at' => now()]);

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark selected notifications as unread (clear read_at on own rows only).
     */
    public function markUnread(MarkNotificationsUnreadRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->authenticatedUser($request);

        $user->notifications()
            ->whereIn('id', $validated['ids'])
            ->update(['read_at' => null]);

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Serialize a database notification for JSON responses.
     *
     * @return array{id: string, type: string, data: array<string, mixed>, read_at: string|null, created_at: string}
     */
    protected function serializeNotification(DatabaseNotification $notification): array
    {
        $createdAt = $notification->created_at;

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $createdAt !== null
                ? $createdAt->toIso8601String()
                : now()->toIso8601String(),
        ];
    }

    /**
     * Authenticated user for notification routes (auth middleware; abort if missing).
     */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        return $user;
    }
}
