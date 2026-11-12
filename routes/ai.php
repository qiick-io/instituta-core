<?php

declare(strict_types=1);

use App\Mcp\Servers\LocalBrainServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('instituta-local', LocalBrainServer::class);
