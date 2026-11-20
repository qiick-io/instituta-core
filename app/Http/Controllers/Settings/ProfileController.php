<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\DeleteUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Services\ActivityEventLoggerService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profile settings: name, email, username, locale and timezone.
 */
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $changedKeys = array_keys($user->getDirty());
        $localeChanged = $user->isDirty('locale');
        $locale = $user->locale;
        $user->save();

        if ($changedKeys !== []) {
            app(ActivityEventLoggerService::class)->settingsUpdated($user, $changedKeys);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        $redirect = to_route('profile.edit');

        if ($localeChanged) {
            return $redirect->cookie('locale', $locale, 60 * 24 * 365);
        }

        return $redirect;
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteUserAction $deleteUser): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        Auth::logout();

        $deleteUser->execute($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
