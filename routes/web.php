<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Brain\ClientCompanyFormController;
use App\Http\Controllers\Brain\ClientEraseController;
use App\Http\Controllers\Brain\ClientExportController;
use App\Http\Controllers\Brain\ClientProductFormController;
use App\Http\Controllers\Brain\ClientProductsController;
use App\Http\Controllers\Brain\ClientsController;
use App\Http\Controllers\Brain\GraphController;
use App\Http\Controllers\Brain\KeysController;
use App\Http\Controllers\Brain\MapController;
use App\Http\Controllers\Brain\ProposalsController;
use App\Http\Controllers\Brain\ReadingController;
use App\Http\Controllers\Brain\ResolvedController;
use App\Http\Controllers\Brain\ScopeSwitcherController;
use App\Http\Controllers\Brain\SessionReplayController;
use App\Http\Controllers\Brain\SourcesController;
use App\Http\Controllers\Brain\TablesController;
use App\Http\Controllers\Brain\TreeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::post('notifications/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
    Route::post('notifications/unread', [NotificationController::class, 'markUnread'])
        ->name('notifications.unread');

    Route::get('search', GlobalSearchController::class)
        ->middleware('throttle:60,1')
        ->name('search');

    Route::post('brain/scope', ScopeSwitcherController::class)->name('brain.scope');
    Route::get('brain/map', MapController::class)->name('brain.map');
    Route::get('brain/graph/{item?}', GraphController::class)
        ->where('item', '.*')
        ->name('brain.graph');
    Route::get('brain/tree', TreeController::class)->name('brain.tree');
    Route::get('brain/items/{item}', ReadingController::class)
        ->where('item', '.*')
        ->name('brain.reading');
    Route::get('brain/resolved', ResolvedController::class)->name('brain.resolved');
    Route::get('brain/tables', [TablesController::class, 'index'])->name('brain.tables.index');
    Route::get('brain/tables/{item}/{table}', [TablesController::class, 'show'])
        ->name('brain.tables.show');
    Route::get('brain/sources', SourcesController::class)->name('brain.sources');
    Route::get('brain/proposals', [ProposalsController::class, 'index'])->name('brain.proposals.index');
    Route::post('brain/proposals', [ProposalsController::class, 'store'])->name('brain.proposals.store');
    Route::get('brain/proposals/{proposal}', [ProposalsController::class, 'show'])->name('brain.proposals.show');
    Route::patch('brain/proposals/{proposal}', [ProposalsController::class, 'update'])->name('brain.proposals.update');
    Route::get('brain/keys', KeysController::class)->name('brain.keys');
    Route::get('brain/clients', [ClientsController::class, 'index'])->name('brain.clients.index');
    Route::get('brain/clients/create', [ClientCompanyFormController::class, 'create'])->name('brain.clients.create');
    Route::post('brain/clients', [ClientsController::class, 'store'])->name('brain.clients.store');
    Route::get('brain/clients/{company}/edit', [ClientCompanyFormController::class, 'edit'])->name('brain.clients.edit');
    Route::put('brain/clients/{company}', [ClientsController::class, 'update'])->name('brain.clients.update');
    Route::get('brain/clients/{company}/products', [ClientProductsController::class, 'index'])
        ->name('brain.clients.products.index');
    Route::get('brain/clients/{company}/products/create', [ClientProductFormController::class, 'create'])
        ->name('brain.clients.products.create');
    Route::post('brain/clients/{company}/products', [ClientProductsController::class, 'store'])
        ->name('brain.clients.products.store');
    Route::get('brain/clients/{company}/products/{product}/edit', [ClientProductFormController::class, 'edit'])
        ->name('brain.clients.products.edit');
    Route::put('brain/clients/{company}/products/{product}', [ClientProductsController::class, 'update'])
        ->name('brain.clients.products.update');
    Route::post('brain/clients/{company}/export', [ClientExportController::class, 'store'])
        ->name('brain.clients.export.store');
    Route::get('brain/clients/{company}/exports/{offboarding}/download', [ClientExportController::class, 'download'])
        ->name('brain.clients.export.download');
    Route::post('brain/clients/{company}/exports/{offboarding}/erase', ClientEraseController::class)
        ->name('brain.clients.export.erase');
    Route::get('brain/session-replay', SessionReplayController::class)
        ->name('brain.session-replay');
    Route::get('activity', ActivityLogController::class)->name('activity.index');
});

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
