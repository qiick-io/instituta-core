<?php

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Enums\RoleEnum;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Authorization\InstanceSuperAdminGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit');

pest()->extend(TestCase::class)
    ->in('Arch');

// Browser axe smoke (#52) runs outside the default suite: `vendor/bin/pest tests/Browser`.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

pest()->browser()->timeout(10_000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Keyword / FTS search is SQLite + PostgreSQL only; MySQL and MariaDB answer 501 (#313).
 */
function skipUnlessKeywordSearchSupported(): void
{
    if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
        test()->markTestSkipped('Keyword search runs on SQLite and PostgreSQL only (#313).');
    }
}

/**
 * Set Spatie's permissions team to an organisation (creates one when omitted).
 */
function permissionsOrganisation(?string $organisationId = null): string
{
    $organisationId ??= Organisation::factory()->create()->id;
    setPermissionsTeamId($organisationId);

    return $organisationId;
}

/**
 * Grant instance super-admin through the instance group (not a Spatie team pivot).
 */
function grantInstanceSuperAdmin(User $user): User
{
    app(InstanceSuperAdminGroupService::class)->grant($user);

    return $user;
}

/**
 * Assign a Spatie role in an organisation team context. Super-admin uses the instance group.
 */
function assignOrganisationRole(
    User $user,
    string|BackedEnum|Role $role,
    ?string $organisationId = null,
    bool $withPerimeter = true,
): User {
    if ($role instanceof Role) {
        $roleName = (string) $role->name;
    } elseif ($role instanceof BackedEnum) {
        $roleName = (string) $role->value;
    } else {
        $roleName = $role;
    }

    if ($roleName === RoleEnum::SuperAdmin->value) {
        return grantInstanceSuperAdmin($user);
    }

    $organisationId = permissionsOrganisation($organisationId);
    $user->assignRole($role);

    if ($withPerimeter) {
        $organisation = Organisation::query()->findOrFail($organisationId);

        if (! $user->principals()->where('organisation_id', $organisationId)->exists()) {
            app(GrantOrganisationAccessAction::class)->execute($user, $organisation, BrainRoleEnum::Member);
        }
    }

    return $user;
}
