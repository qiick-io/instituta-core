<?php

declare(strict_types=1);

use App\Mcp\Servers\LocalBrainServer;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\Registrar;

function fixturePath(string $name): string
{
    return dirname(__DIR__).'/Unit/Brain/Fixtures/'.$name;
}

it('lists items from a native fixture via instituta:read', function (): void {
    $exit = Artisan::call('instituta:read', [
        'action' => 'list',
        '--path' => fixturePath('native'),
        '--source' => 'test',
        '--commit' => 'abc',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('rules/coding')
        ->and($output)->toContain('Coding rules');
});

it('searches and gets an item', function (): void {
    $searchExit = Artisan::call('instituta:read', [
        'action' => 'search',
        '--path' => fixturePath('native'),
        '--q' => 'coding',
    ]);
    $searchOutput = Artisan::output();

    expect($searchExit)->toBe(0)
        ->and($searchOutput)->toContain('Coding rules');

    $getExit = Artisan::call('instituta:read', [
        'action' => 'get',
        'id' => 'rules/coding.md',
        '--path' => fixturePath('native'),
    ]);
    $getOutput = Artisan::output();

    expect($getExit)->toBe(0)
        ->and($getOutput)->toContain('sections')
        ->and($getOutput)->toContain('rules/coding');
});

it('outlines resolves and graphs neighbours', function (): void {
    expect(Artisan::call('instituta:read', [
        'action' => 'outline',
        'id' => 'rules/coding.md',
        '--path' => fixturePath('native'),
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('anchor');

    expect(Artisan::call('instituta:read', [
        'action' => 'resolve',
        '--path' => fixturePath('native'),
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('resolution_key');

    expect(Artisan::call('instituta:read', [
        'action' => 'graph',
        'id' => 'rules/coding.md',
        '--path' => fixturePath('native'),
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('neighbours');
});

it('validates and refuses denylist paths', function (): void {
    expect(Artisan::call('instituta:read', [
        'action' => 'validate',
        '--path' => fixturePath('native'),
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('issues');

    config(['instituta.ingest.denylist' => ['acme', 'globex', 'initech']]);

    expect(Artisan::call('instituta:read', [
        'action' => 'list',
        '--path' => fixturePath('denied-path/acme-globex-docs'),
    ]))->not->toBe(0);
});

it('requires --path for instituta:mcp', function (): void {
    expect(Artisan::call('instituta:mcp'))->not->toBe(0);
});

it('registers the local instituta MCP server handle', function (): void {
    $registrar = app(Registrar::class);

    expect($registrar->getLocalServer('instituta-local'))->not->toBeNull()
        ->and(class_exists(LocalBrainServer::class))->toBeTrue();
});
