<?php

use App\Enums\PermissionEnum;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Ai\AiChatController;
use App\Http\Controllers\Ai\AiConversationController;
use App\Http\Controllers\Ai\AiDraftController;
use App\Http\Controllers\Ai\AiPageController;
use App\Http\Controllers\Ai\AiStatusController;
use App\Http\Controllers\Brain\BrainAssetController;
use App\Http\Controllers\Brain\ClientCompanyFormController;
use App\Http\Controllers\Brain\ClientEraseController;
use App\Http\Controllers\Brain\ClientExportController;
use App\Http\Controllers\Brain\ClientProductFormController;
use App\Http\Controllers\Brain\ClientProductsController;
use App\Http\Controllers\Brain\ClientsController;
use App\Http\Controllers\Brain\ExplorerController;
use App\Http\Controllers\Brain\GapsController;
use App\Http\Controllers\Brain\GraphController;
use App\Http\Controllers\Brain\GraphPreviewController;
use App\Http\Controllers\Brain\LegacyBrainRedirectController;
use App\Http\Controllers\Brain\ProposalsController;
use App\Http\Controllers\Brain\ResolvedController;
use App\Http\Controllers\Brain\ScopeSwitcherController;
use App\Http\Controllers\Brain\SessionReplayController;
use App\Http\Controllers\Brain\SourcesController;
use App\Http\Controllers\Brain\SourceSyncController;
use App\Http\Controllers\Brain\TablesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Settings\KeysController;
use App\Http\Middleware\EnsureAiAssistantEnabledMiddleware;
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

    Route::post('scope', ScopeSwitcherController::class)->name('scope.update');

    // The brain as files (#253) and as a graph.
    Route::redirect('map', 'explorer')->name('map');
    Route::get('explorer', [ExplorerController::class, 'index'])->name('explorer.index');
    Route::get('explorer/{item}', [ExplorerController::class, 'show'])
        ->where('item', '.*')
        ->name('explorer.show');
    Route::get('graph', GraphController::class)->name('graph');
    Route::get('graph/preview/{item}', GraphPreviewController::class)
        ->where('item', '.*')
        ->name('graph.preview');
    Route::get('brain-assets/{item}', BrainAssetController::class)->name('brain.assets.show');
    Route::get('resolved', ResolvedController::class)->name('resolved');
    Route::get('tables', [TablesController::class, 'index'])->name('tables.index');
    Route::get('tables/{item}/{table}', [TablesController::class, 'show'])->name('tables.show');

    // What waits for a person: proposed changes and reported gaps.
    Route::redirect('review', 'review/proposals')->name('review.index');
    Route::get('review/proposals', [ProposalsController::class, 'index'])->name('review.proposals.index');
    Route::post('review/proposals', [ProposalsController::class, 'store'])->name('review.proposals.store');
    Route::get('review/proposals/{proposal}', [ProposalsController::class, 'show'])->name('review.proposals.show');
    Route::patch('review/proposals/{proposal}', [ProposalsController::class, 'update'])->name('review.proposals.update');
    Route::get('review/gaps', [GapsController::class, 'index'])->name('review.gaps.index');
    Route::delete('review/gaps/{gap}', [GapsController::class, 'destroy'])->name('review.gaps.destroy');

    // Brain settings: the repositories the brain reads and the API keys agents use.
    Route::get('settings/repositories', [SourcesController::class, 'index'])->name('repositories.index');
    Route::post('settings/repositories', [SourcesController::class, 'store'])->name('repositories.store');
    Route::put('settings/repositories/{source}', [SourcesController::class, 'update'])->name('repositories.update');
    Route::delete('settings/repositories/{source}', [SourcesController::class, 'destroy'])->name('repositories.destroy');
    Route::post('settings/repositories/{source}/sync', SourceSyncController::class)->name('repositories.sync');
    Route::get('settings/api-keys', [KeysController::class, 'index'])->name('api-keys.index');
    Route::post('settings/api-keys', [KeysController::class, 'store'])->name('api-keys.store');
    Route::post('settings/api-keys/{key}/rotate', [KeysController::class, 'rotate'])->name('api-keys.rotate');
    Route::delete('settings/api-keys/{key}', [KeysController::class, 'destroy'])->name('api-keys.destroy');

    Route::get('clients', [ClientsController::class, 'index'])->name('clients.index');
    Route::get('clients/create', [ClientCompanyFormController::class, 'create'])->name('clients.create');
    Route::post('clients', [ClientsController::class, 'store'])->name('clients.store');
    Route::get('clients/{company}/edit', [ClientCompanyFormController::class, 'edit'])->name('clients.edit');
    Route::put('clients/{company}', [ClientsController::class, 'update'])->name('clients.update');
    Route::get('clients/{company}/products', [ClientProductsController::class, 'index'])
        ->name('clients.products.index');
    Route::get('clients/{company}/products/create', [ClientProductFormController::class, 'create'])
        ->name('clients.products.create');
    Route::post('clients/{company}/products', [ClientProductsController::class, 'store'])
        ->name('clients.products.store');
    Route::get('clients/{company}/products/{product}/edit', [ClientProductFormController::class, 'edit'])
        ->name('clients.products.edit');
    Route::put('clients/{company}/products/{product}', [ClientProductsController::class, 'update'])
        ->name('clients.products.update');
    Route::post('clients/{company}/export', [ClientExportController::class, 'store'])
        ->name('clients.export.store');
    Route::get('clients/{company}/exports/{offboarding}/download', [ClientExportController::class, 'download'])
        ->name('clients.export.download');
    Route::post('clients/{company}/exports/{offboarding}/erase', ClientEraseController::class)
        ->name('clients.export.erase');

    Route::get('activity/agent-calls', SessionReplayController::class)->name('activity.agent-calls');

    // Links from before #252 keep working.
    Route::get('brain/{path}', LegacyBrainRedirectController::class)->where('path', '.*')->name('brain.legacy');
    Route::get('activity', ActivityLogController::class)->name('activity.index');
});

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';

// The in-app assistant (#203): status is open to every AI user, the rest needs AI_ASSISTANT_ENABLED.
Route::middleware(['auth', 'verified', 'permission:'.PermissionEnum::CanUseAi->value])
    ->prefix('ai')
    ->name('ai.')
    ->group(function (): void {
        Route::get('/', AiPageController::class)->name('index');
        Route::get('status', AiStatusController::class)->name('status');

        Route::middleware(EnsureAiAssistantEnabledMiddleware::class)->group(function (): void {
            Route::post('chat', AiChatController::class)->middleware('throttle:20,1')->name('chat');
            Route::post('drafts', AiDraftController::class)->middleware('throttle:20,1')->name('drafts');
            Route::get('conversations', [AiConversationController::class, 'index'])->name('conversations.index');
            Route::get('conversations/{conversation}', [AiConversationController::class, 'show'])->name('conversations.show');
            Route::delete('conversations/{conversation}', [AiConversationController::class, 'destroy'])->name('conversations.destroy');
        });
    });
