<?php

declare(strict_types=1);

/**
 * This repo's settings for the shared `NamingArchitectureTest.php` (qiick standards).
 *
 * The sync writes this file once and never overwrites or checks it: it is the repo's own.
 *
 * - `namespace`: the root namespace the rules check (`App` for an app, `Qiick\Xyz` for a package).
 * - `ignoring`: per rule, the classes or namespaces it skips. Only framework or package
 *   scaffolding that must keep its upstream name, or a deferred clean-up with its issue.
 *   Give the reason in a comment on each entry. Rule keys: a sub-namespace of the suffix
 *   rules (`Http\Middleware`, `Actions`, `Services`, …), or `strict types`, `env`, `Data`,
 *   `Enums`, `Contracts`, `traits`.
 * - `suffixes`: extra sub-namespace => suffix pairs for roles of this repo (`Parsers` => `Parser`).
 * - `controllerPublicMethods`: controllers that already have more than five public methods,
 *   with their current count (a ratchet: it may only go down; the test says when to lower it).
 */
return [
    'namespace' => 'App',

    'ignoring' => [
        'strict types' => [
            // Laravel starter-kit files keep their upstream form.
            'App\Actions\Fortify',
            'App\Concerns',
            'App\Http\Controllers\Controller',
            'App\Http\Controllers\Settings',
            'App\Http\Middleware',
            'App\Http\Requests\Settings',
            'App\Models\User',
            'App\Providers',
        ],
        'Actions' => [
            // Fortify actions keep their upstream names (CreateNewUser, ResetUserPassword).
            'App\Actions\Fortify',
        ],
        'Http\Middleware' => [
            // Starter-kit middleware, never renamed.
            'App\Http\Middleware\HandleAppearance',
            'App\Http\Middleware\HandleInertiaRequests',
        ],
        'Http\Controllers' => [
            // Machine probes; name matches externa-core and the distribution smoke.
            'App\Http\Controllers\HealthController',
        ],
        'Services' => [
            // Search drivers live under Services\Search and use the Driver suffix.
            'App\Services\Search',
        ],
        'traits' => [
            // Starter-kit validation-rule traits, never renamed.
            'App\Concerns',
        ],
    ],

    'suffixes' => [
        // Brain module roles (kickoff §2.1).
        'Brain\\Readers' => 'Reader',
        'Brain\\Adapters' => 'Adapter',
        'Brain\\Resolvers' => 'Resolver',
        'Brain\\Filters' => 'Filter',
        'Brain\\Scanners' => 'Scanner',
        'Brain\\Tools' => 'Tool',
        'Brain\\Data' => 'Data',
        'Brain\\Exceptions' => 'Exception',
        // Plan step 11 search drivers (SearchIndexContract implementations).
        'Services\\Search' => 'Driver',
    ],

    'controllerPublicMethods' => [],
];
