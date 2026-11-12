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
    ],

];
