<?php

use App\Http\Controllers\Brain\MapController;
use App\Http\Controllers\Brain\ReadingController;
use App\Http\Controllers\Brain\ResolvedController;
use App\Http\Controllers\Brain\ScopeSwitcherController;
use App\Http\Controllers\Brain\SourcesController;
use App\Http\Controllers\Brain\TablesController;
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
    Route::get('brain/resolved', ResolvedController::class)->name('brain.resolved');
    Route::get('brain/tables', [TablesController::class, 'index'])->name('brain.tables.index');
    Route::get('brain/tables/{item}/{table}', [TablesController::class, 'show'])
        ->name('brain.tables.show');
    Route::get('brain/sources', SourcesController::class)->name('brain.sources');
});

require __DIR__.'/settings.php';
