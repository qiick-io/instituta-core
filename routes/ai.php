<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Http\Middleware\EnsureMcpEnabledMiddleware;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Servers\LocalBrainServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('instituta-local', LocalBrainServer::class);

Mcp::web('/mcp', HttpBrainServer::class)
    ->middleware([
        EnsureMcpEnabledMiddleware::class,
        AuthenticateApiKeyMiddleware::class,
        'throttle:instituta-api',
    ]);
