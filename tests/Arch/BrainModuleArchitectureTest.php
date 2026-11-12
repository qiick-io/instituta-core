<?php

declare(strict_types=1);

/**
 * Brain module boundary (kickoff §2.1): the module never depends on the app shell.
 */
arch('brain module does not use app models')
    ->expect('App\Brain')
    ->not->toUse('App\Models');

arch('brain module does not use http')
    ->expect('App\Brain')
    ->not->toUse('App\Http');

arch('brain module does not use jobs')
    ->expect('App\Brain')
    ->not->toUse('App\Jobs');

arch('brain module does not use app services outside itself')
    ->expect('App\Brain')
    ->not->toUse('App\Services');

arch('brain module does not use eloquent')
    ->expect('App\Brain')
    ->not->toUse('Illuminate\Database\Eloquent');

arch('brain module does not use facades')
    ->expect('App\Brain')
    ->not->toUse('Illuminate\Support\Facades');

arch('brain data objects are readonly')
    ->expect('App\Brain\Data')
    ->classes()
    ->toBeReadonly();

arch('brain adapters end with Adapter')
    ->expect('App\Brain\Adapters')
    ->classes()
    ->toHaveSuffix('Adapter');

arch('brain readers end with Reader')
    ->expect('App\Brain\Readers')
    ->classes()
    ->toHaveSuffix('Reader');

arch('brain scanners end with Scanner')
    ->expect('App\Brain\Scanners')
    ->classes()
    ->toHaveSuffix('Scanner');

arch('brain filters end with Filter')
    ->expect('App\Brain\Filters')
    ->classes()
    ->toHaveSuffix('Filter');

arch('brain resolvers end with Resolver')
    ->expect('App\Brain\Resolvers')
    ->classes()
    ->toHaveSuffix('Resolver');

test('brain module source avoids config env and app helpers', function (): void {
    $root = dirname(__DIR__, 2).'/app/Brain';
    $violations = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        foreach (['config(', 'env(', 'app('] as $forbidden) {
            if (str_contains($contents, $forbidden)) {
                $violations[] = $file->getPathname().' contains '.$forbidden;
            }
        }
    }

    expect($violations)->toBe([]);
});
