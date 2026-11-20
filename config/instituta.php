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
    | REST write kill switch (#54)
    |--------------------------------------------------------------------------
    |
    | Off by default. brain:write keys and proposal endpoints stay refused until
    | this (or MCP write) is enabled. Never enables direct durable-store writes.
    |
    */

    'api' => [
        'write_enabled' => (bool) env('INSTITUTA_API_WRITE_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proposal decisions (#197)
    |--------------------------------------------------------------------------
    |
    | Accepting a write proposal is a human decision. Off by default: agent and
    | service keys may reject but never accept. A key can never accept a proposal
    | it (or its principal) opened, whatever this says.
    |
    */

    'proposals' => [
        'agents_can_accept' => (bool) env('INSTITUTA_AGENTS_CAN_ACCEPT_PROPOSALS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingest
    |--------------------------------------------------------------------------
    |
    | Never-ingest tokens from INSTITUTA_INGEST_DENYLIST (comma-separated,
    | trimmed, lowercased; empty entries dropped). Default is empty: installs
    | that must refuse named organisations set the env. The brain module reader
    | receives these as ReadRequestData input and never calls config() itself;
    | the app layer copies this list when wiring ingest / CLI / MCP.
    |
    */

    'ingest' => [
        'denylist' => array_values(array_filter(array_map(
            static fn (string $token): string => mb_strtolower(trim($token)),
            explode(',', (string) env('INSTITUTA_INGEST_DENYLIST', '')),
        ), static fn (string $token): bool => $token !== '')),

        /*
         * Optional read-only GitHub token for the github provider.
         * Leave empty for local/git dogfood; remote github sync refuses without it.
         */
        'github_token' => env('INSTITUTA_GITHUB_TOKEN'),
    ],

];
