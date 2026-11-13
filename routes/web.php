<?php

use App\Http\Controllers\Brain\MapController;
use App\Http\Controllers\Brain\ReadingController;
use App\Http\Controllers\Brain\ResolvedController;
use App\Http\Controllers\Brain\ScopeSwitcherController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::post('brain/scope', ScopeSwitcherController::class)->name('brain.scope');
    Route::get('brain/map', MapController::class)->name('brain.map');
    Route::get('brain/items/{item}', ReadingController::class)
        ->where('item', '.*')
        ->name('brain.reading');
    Route::get('brain/resolved', ResolvedController::class)->name('brain.resolved');
});

require __DIR__.'/settings.php';
