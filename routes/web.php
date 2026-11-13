<?php

use App\Http\Controllers\Brain\MapController;
use App\Http\Controllers\Brain\ScopeSwitcherController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::post('brain/scope', ScopeSwitcherController::class)->name('brain.scope');
    Route::get('brain/map', MapController::class)->name('brain.map');
});

require __DIR__.'/settings.php';
