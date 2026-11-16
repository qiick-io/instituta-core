<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum;
use App\Enums\CompanyKindEnum;
use App\Enums\RoleEnum as AppRoleEnum;
use App\Models\Company;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Every page renders one app shell, including pages with header actions (#210): before, a
 * header action passed through setLayoutProps re-rendered the page in a loop (blank page), and
 * the admin pages were wrapped in the shell twice. Runs in the `ui-quality` browser job.
 */
it('renders one shell with header actions on lists that have rows, in light and dark', function (string $mode): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    $organisation = Organisation::factory()->create();
    Company::factory()->create(['organisation_id' => $organisation->id, 'kind' => CompanyKindEnum::Client, 'name' => 'Acme Client']);
    $owner = User::factory()->create()->assignRole(AppRoleEnum::SuperAdmin->value);
    app(GrantOrganisationAccessAction::class)->execute($owner, $organisation, RoleEnum::Owner);
    $this->actingAs($owner);

    $pages = [
        route('clients.index', absolute: false) => 'Acme Client',
        route('repositories.index', absolute: false) => null,
        route('api-keys.index', absolute: false) => null,
        route('users.index', absolute: false) => $owner->email,
    ];

    foreach ($pages as $path => $expectedText) {
        $start = visit($path);
        $page = $mode === 'dark' ? $start->inDarkMode() : $start->inLightMode();

        $page->assertPresent('#main-content')
            ->assertScript("() => document.querySelectorAll('#main-content').length", 1)
            ->assertNoJavaScriptErrors()
            ->assertNoAccessibilityIssues(1);

        if ($expectedText !== null) {
            $page->assertSee($expectedText);
        }
    }
})->with(['light', 'dark']);
