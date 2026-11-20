<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/**
 * Env → config parsing for the never-ingest denylist (#369).
 *
 * Writes through Env's repository so the value is visible after bootstrap
 * (putenv / $_ENV alone are ignored once the key has been read).
 */
it('parses INSTITUTA_INGEST_DENYLIST with trim lowercase and empty entries dropped', function (): void {
    $repository = Env::getRepository();
    $previous = $repository->has('INSTITUTA_INGEST_DENYLIST')
        ? $repository->get('INSTITUTA_INGEST_DENYLIST')
        : false;

    $repository->set('INSTITUTA_INGEST_DENYLIST', ' Acme , GLOBEX,, Initech ');

    $config = require config_path('instituta.php');

    expect($config['ingest']['denylist'])->toBe(['acme', 'globex', 'initech']);

    if ($previous === false) {
        $repository->clear('INSTITUTA_INGEST_DENYLIST');
    } else {
        $repository->set('INSTITUTA_INGEST_DENYLIST', $previous);
    }
});

it('defaults the denylist to an empty list when the env is blank', function (): void {
    $repository = Env::getRepository();
    $previous = $repository->has('INSTITUTA_INGEST_DENYLIST')
        ? $repository->get('INSTITUTA_INGEST_DENYLIST')
        : false;

    $repository->set('INSTITUTA_INGEST_DENYLIST', '');

    $config = require config_path('instituta.php');

    expect($config['ingest']['denylist'])->toBe([]);

    if ($previous === false) {
        $repository->clear('INSTITUTA_INGEST_DENYLIST');
    } else {
        $repository->set('INSTITUTA_INGEST_DENYLIST', $previous);
    }
});
