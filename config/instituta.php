<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | MCP (HTTP)
    |--------------------------------------------------------------------------
    |
    | HTTP MCP is off by default. Writes stay off for the MVP.
    |
    */

    'mcp' => [
        'enabled' => (bool) env('INSTITUTA_MCP_ENABLED', false),
        'write_enabled' => (bool) env('INSTITUTA_MCP_WRITE_ENABLED', false),
        'rate_limit_per_minute' => (int) env('INSTITUTA_MCP_RATE_LIMIT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingest
    |--------------------------------------------------------------------------
    |
    | The brain module reader also receives these tokens as ReadRequestData
    | input; it never calls config() itself. The app layer copies this list
    | when wiring ingest / CLI / MCP.
    |
    */

    'ingest' => [
        'denylist' => [
            'klover',
            'cybergrant',
            'zerotredici',
        ],

        /*
         * Optional read-only GitHub token for the github provider.
         * Leave empty for local/git dogfood; remote github sync refuses without it.
         */
        'github_token' => env('INSTITUTA_GITHUB_TOKEN'),
    ],

];
