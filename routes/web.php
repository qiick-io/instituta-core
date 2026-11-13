<?php

use App\Http\Controllers\Brain\MapController;
use App\Http\Controllers\Brain\ReadingController;
use App\Http\Controllers\Brain\ScopeSwitcherController;
use App\Http\Controllers\Brain\TreeController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::post('brain/scope', ScopeSwitcherController::class)->name('brain.scope');
    Route::get('brain/map', MapController::class)->name('brain.map');
    Route::get('brain/tree', TreeController::class)->name('brain.tree');
    Route::get('brain/items/{item}', ReadingController::class)
        ->where('item', '.*')
        ->name('brain.reading');
});

require __DIR__.'/settings.php';
