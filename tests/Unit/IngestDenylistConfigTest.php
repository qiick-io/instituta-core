<?php

declare(strict_types=1);

/**
 * Env → config parsing for the never-ingest denylist (#369).
 */
it('parses INSTITUTA_INGEST_DENYLIST with trim lowercase and empty entries dropped', function (): void {
    $previous = $_ENV['INSTITUTA_INGEST_DENYLIST'] ?? false;
    $_ENV['INSTITUTA_INGEST_DENYLIST'] = ' Acme , GLOBEX,, Initech ';
    putenv('INSTITUTA_INGEST_DENYLIST= Acme , GLOBEX,, Initech ');

    $config = require config_path('instituta.php');

    expect($config['ingest']['denylist'])->toBe(['acme', 'globex', 'initech']);

    if ($previous === false) {
        unset($_ENV['INSTITUTA_INGEST_DENYLIST']);
        putenv('INSTITUTA_INGEST_DENYLIST');
    } else {
        $_ENV['INSTITUTA_INGEST_DENYLIST'] = $previous;
        putenv('INSTITUTA_INGEST_DENYLIST='.$previous);
    }
});

it('defaults the denylist to an empty list when the env is blank', function (): void {
    $previous = $_ENV['INSTITUTA_INGEST_DENYLIST'] ?? false;
    $_ENV['INSTITUTA_INGEST_DENYLIST'] = '';
    putenv('INSTITUTA_INGEST_DENYLIST=');

    $config = require config_path('instituta.php');

    expect($config['ingest']['denylist'])->toBe([]);

    if ($previous === false) {
        unset($_ENV['INSTITUTA_INGEST_DENYLIST']);
        putenv('INSTITUTA_INGEST_DENYLIST');
    } else {
        $_ENV['INSTITUTA_INGEST_DENYLIST'] = $previous;
        putenv('INSTITUTA_INGEST_DENYLIST='.$previous);
    }
});
