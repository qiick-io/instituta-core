<?php

use App\Http\Controllers\Settings\AppearanceSettingsController;
use App\Http\Controllers\Settings\BrowserSessionController;
use App\Http\Controllers\Settings\InstanceLocalizationSettingsController;
use App\Http\Controllers\Settings\InstanceSecuritySettingsController;
use App\Http\Controllers\Settings\InstanceSettingsController;
use App\Http\Controllers\Settings\LocaleController;
use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Models\Setting;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::patch('settings/notification-preferences', [NotificationPreferencesController::class, 'update'])
        ->name('notification-preferences.update');

    Route::patch('settings/locale', [LocaleController::class, 'update'])->name('locale.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/sessions', [BrowserSessionController::class, 'index'])
        ->middleware(RequirePassword::class)
        ->name('sessions.index');
    Route::delete('settings/sessions/others', [BrowserSessionController::class, 'destroyOthers'])
        ->middleware(RequirePassword::class)
        ->name('sessions.destroy-others');
    Route::delete('settings/sessions/{publicId}', [BrowserSessionController::class, 'destroy'])
        ->middleware(RequirePassword::class)
        ->name('sessions.destroy');

    // Personal appearance for everyone; instance theme form on the same page for managers.
    Route::get('settings/appearance', [AppearanceSettingsController::class, 'edit'])->name('appearance.edit');

    // Instance settings for managers (SettingPolicy / can-manage-settings).
    Route::middleware('can:update,'.Setting::class)->group(function () {
        Route::get('settings/instance', [InstanceSettingsController::class, 'edit'])->name('instance.edit');
        Route::put('settings/instance', [InstanceSettingsController::class, 'update'])->name('instance.update');

        Route::get('settings/instance/localization', [InstanceLocalizationSettingsController::class, 'edit'])->name('instance.localization.edit');
        Route::put('settings/instance/localization', [InstanceLocalizationSettingsController::class, 'update'])->name('instance.localization.update');

        Route::get('settings/instance/security', [InstanceSecuritySettingsController::class, 'edit'])->name('instance.security.edit');
        Route::put('settings/instance/security', [InstanceSecuritySettingsController::class, 'update'])->name('instance.security.update');

        Route::put('settings/appearance', [AppearanceSettingsController::class, 'update'])->name('appearance.update');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
