<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API key token
    |--------------------------------------------------------------------------
    |
    | Fleet format: <pfx>_<env>_<id><secret> (qiick-io/app-ui#171). Instituta's
    | product prefix is `ins`. The public id is generated separately from the
    | secret and is what key_prefix stores; the stored hash is SHA-256 of the
    | full token.
    |
    */

    'key_prefix' => 'ins',

    'key_rotation_grace_hours' => (int) env('API_KEY_ROTATION_GRACE_HOURS', 24),

    'default_rate_limit_per_minute' => (int) env('API_DEFAULT_RATE_LIMIT_PER_MINUTE', 60),

    'expiry_warning_days' => 7,

];
