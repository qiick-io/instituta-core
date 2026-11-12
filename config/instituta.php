<?php

declare(strict_types=1);

return [

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
