<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

it('serves OpenAPI 3.1 JSON without an API key', function (): void {
    $this->getJson('/api/v1/openapi.json')
        ->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('info.title', 'Instituta Brain API')
        ->assertJsonStructure([
            'openapi',
            'info' => ['title', 'version'],
            'paths',
            'components' => ['securitySchemes'],
        ]);
});

it('documents every routes/api.php route in the OpenAPI paths', function (): void {
    /** @var array<string, mixed> $spec */
    $spec = Yaml::parseFile(resource_path('openapi/instituta-v1.yaml'));
    /** @var array<string, mixed> $paths */
    $paths = $spec['paths'] ?? [];

    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1'))
        ->values();

    expect($apiRoutes)->not->toBeEmpty();

    foreach ($apiRoutes as $route) {
        $openapiPath = '/'.preg_replace('#^api/v1/?#', '', $route->uri());
        if ($openapiPath === '/') {
            $openapiPath = '/';
        }

        expect(array_key_exists($openapiPath, $paths))->toBeTrue(
            "Missing OpenAPI path for {$route->uri()}",
        );

        $methods = array_map(
            static fn (string $method): string => strtolower($method),
            array_values(array_filter(
                $route->methods(),
                static fn (string $method): bool => ! in_array($method, ['HEAD', 'OPTIONS'], true),
            )),
        );

        foreach ($methods as $method) {
            /** @var array<string, mixed> $pathItem */
            $pathItem = $paths[$openapiPath];
            expect(array_key_exists($method, $pathItem))->toBeTrue(
                "Missing OpenAPI {$method} for {$openapiPath}",
            );
        }
    }
});
