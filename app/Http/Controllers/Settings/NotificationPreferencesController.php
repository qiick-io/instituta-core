<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Persists the user's notification sound preference.
 */
class NotificationPreferencesController extends Controller
{
    /**
     * Save the notification sound toggle.
     */
    public function update(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $user->forceFill([
            'notification_sound_enabled' => $request->boolean('sound_notifications_enabled'),
        ])->save();

        return to_route('profile.edit');
    }
}
