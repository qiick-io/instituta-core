<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Brain\ClientsController;
use App\Http\Controllers\Brain\KeysController;
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
    Route::get('brain/keys', KeysController::class)->name('brain.keys');
    Route::get('brain/clients', [ClientsController::class, 'index'])->name('brain.clients.index');
    Route::get('brain/clients/create', [ClientsController::class, 'create'])->name('brain.clients.create');
    Route::post('brain/clients', [ClientsController::class, 'store'])->name('brain.clients.store');
    Route::get('brain/clients/{company}/edit', [ClientsController::class, 'edit'])->name('brain.clients.edit');
    Route::put('brain/clients/{company}', [ClientsController::class, 'update'])->name('brain.clients.update');
    Route::get('activity', ActivityLogController::class)->name('activity.index');
});

require __DIR__.'/settings.php';
