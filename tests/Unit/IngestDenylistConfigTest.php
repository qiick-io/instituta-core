<?php

declare(strict_types=1);

use App\Services\DenylistGuardService;

/**
 * Never-ingest denylist parsing and config wiring (#369).
 */
it('parses a comma-separated denylist with trim lowercase and empty entries dropped', function (): void {
    expect(DenylistGuardService::parseTokens(' Acme , GLOBEX,, Initech '))
        ->toBe(['acme', 'globex', 'initech']);
});

it('parses a blank denylist string as an empty list', function (): void {
    expect(DenylistGuardService::parseTokens(''))->toBe([])
        ->and(DenylistGuardService::parseTokens('  , , '))->toBe([]);
});

it('reads denylist tokens from config without touching process env', function (): void {
    config([
        'instituta.ingest.denylist' => DenylistGuardService::parseTokens(' Acme , GLOBEX,, Initech '),
    ]);

    expect(app(DenylistGuardService::class)->tokens())->toBe(['acme', 'globex', 'initech']);
});

it('refuses nothing when the configured denylist is empty', function (): void {
    config(['instituta.ingest.denylist' => []]);

    app(DenylistGuardService::class)->assertAllowed(
        name: 'Acme Globex Initech Docs',
        remoteUrl: 'https://github.com/acme/globex.git',
        localPath: '/tmp/initech',
    );

    expect(app(DenylistGuardService::class)->tokens())->toBe([]);
});
