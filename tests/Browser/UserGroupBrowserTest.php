<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * An admin reaches Groups from the sidebar, creates a group in the drawer and finds it in the
 * list (#258). Runs in the `ui-quality` browser job, not in `php artisan test`.
 */
it('creates a user group from the drawer, in light and dark', function (string $mode): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    UserGroup::factory()->create(['name' => 'Support team']);
    $admin = assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value);

    $this->actingAs($admin);
    $start = visit(route('groups.index', absolute: false));
    $page = $mode === 'dark' ? $start->inDarkMode() : $start->inLightMode();

    $page->assertSee('Support team')
        ->assertPresent('nav a[href$="/groups"]')
        ->assertNoAccessibilityIssues(1)
        ->click('New group')
        ->fill('#group_name', 'Content editors')
        ->fill('#group_description', 'Writes the brand pages')
        ->press('Create')
        ->assertSee('Content editors')
        ->assertNoJavaScriptErrors();

    expect(UserGroup::query()->where('name', 'Content editors')->exists())->toBeTrue();
})->with(['light', 'dark']);
