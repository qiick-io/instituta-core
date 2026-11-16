<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SourceWebhookController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemNeighboursController;
use App\Http\Controllers\Api\V1\ItemOutlineController;
use App\Http\Controllers\Api\V1\ItemSectionController;
use App\Http\Controllers\Api\V1\ItemTableController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\ProductResolveController;
use App\Http\Controllers\Api\V1\ProposalController;
use App\Http\Controllers\Api\V1\ScopeController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SectionBatchController;
use App\Http\Controllers\Api\V1\SimilarSearchController;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Http\Middleware\RequireBrainWriteMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['throttle:instituta-api'])
    ->group(function (): void {
        Route::get('openapi.json', OpenApiController::class);
    });

Route::prefix('v1')
    ->middleware([
        AuthenticateApiKeyMiddleware::class,
        'throttle:instituta-api',
    ])
    ->group(function (): void {
        Route::get('scopes', ScopeController::class);
        Route::get('items', [ItemController::class, 'index']);
        Route::get('items/{id}/outline', ItemOutlineController::class);
        Route::get('items/{id}/sections/{anchor}', ItemSectionController::class);
        Route::get('items/{id}/tables/{key}', ItemTableController::class);
        Route::post('sections', SectionBatchController::class);
        Route::get('items/{id}/neighbours', ItemNeighboursController::class);
        Route::get('items/{id}', [ItemController::class, 'show']);
        Route::get('search', SearchController::class);
        Route::get('similar', SimilarSearchController::class);
        Route::get('products/{id}/resolved', ProductResolveController::class);

        Route::middleware([RequireBrainWriteMiddleware::class])->group(function (): void {
            Route::get('proposals', [ProposalController::class, 'index']);
            Route::post('proposals', [ProposalController::class, 'store']);
            Route::patch('proposals/{id}', [ProposalController::class, 'update']);
        });
    });

// Push webhooks: signed per source, no API key (#199).
Route::post('webhooks/sources/{source}', SourceWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.sources');
