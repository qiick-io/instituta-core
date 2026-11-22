<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\PermissionsTeamContextService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * With Spatie teams on, organisation-scoped roles must not leak across organisations,
 * while instance super-admin (group membership) remains effective everywhere.
 */
beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

test('roles assigned in organisation A are invisible in organisation B', function (): void {
    $organisationA = Organisation::factory()->create();
    $organisationB = Organisation::factory()->create();
    $user = User::factory()->create();
    $teamContext = app(PermissionsTeamContextService::class);

    assignOrganisationRole($user, RoleEnum::Admin, $organisationA->id);

    $teamContext->setOrganisation($organisationA->id, $user);
    expect($user->hasRole(RoleEnum::Admin->value))->toBeTrue()
        ->and(Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value))->toBeTrue();

    $teamContext->setOrganisation($organisationB->id, $user);
    expect($user->hasRole(RoleEnum::Admin->value))->toBeFalse()
        ->and(Gate::forUser($user)->allows(PermissionEnum::CanShowUsers->value))->toBeFalse();
});

test('instance super-admin works in every organisation without a team pivot row', function (): void {
    $organisationA = Organisation::factory()->create();
    $organisationB = Organisation::factory()->create();
    $user = grantInstanceSuperAdmin(User::factory()->create());
    $teamContext = app(PermissionsTeamContextService::class);
    $resolver = app(EffectivePermissionResolverService::class);

    expect($resolver->isSuperAdmin($user))->toBeTrue();

    foreach ([$organisationA->id, $organisationB->id] as $organisationId) {
        $teamContext->setOrganisation($organisationId);
        expect(Gate::forUser($user)->allows('non-existent-ability'))->toBeTrue()
            ->and(Gate::forUser($user)->allows(PermissionEnum::CanDeleteUsers->value))->toBeTrue();
    }

    expect(
        $user->roles()->where('name', RoleEnum::SuperAdmin->value)->exists()
    )->toBeFalse();
});
