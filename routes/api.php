<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemNeighboursController;
use App\Http\Controllers\Api\V1\ItemOutlineController;
use App\Http\Controllers\Api\V1\ItemSectionController;
use App\Http\Controllers\Api\V1\ItemTableController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\ProductResolveController;
use App\Http\Controllers\Api\V1\ScopeController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
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
        Route::get('items/{id}/neighbours', ItemNeighboursController::class);
        Route::get('items/{id}', [ItemController::class, 'show']);
        Route::get('search', SearchController::class);
        Route::get('products/{id}/resolved', ProductResolveController::class);
    });
