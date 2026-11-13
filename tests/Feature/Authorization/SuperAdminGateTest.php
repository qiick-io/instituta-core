<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole(RoleEnum::SuperAdmin->value);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleEnum::Admin->value);

    $this->reader = User::factory()->create();
    $this->reader->assignRole(RoleEnum::Reader->value);
});

test('super admin bypasses gate permission checks', function (): void {
    expect(Gate::forUser($this->superAdmin)->allows(PermissionEnum::CanDeleteUsers->value))->toBeTrue()
        ->and(Gate::forUser($this->superAdmin)->allows('non-existent-ability'))->toBeTrue();
});

test('admin is allowed only for granted permissions via Spatie gate checks', function (): void {
    expect(Gate::forUser($this->admin)->allows(PermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and(Gate::forUser($this->reader)->allows(PermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and(Gate::forUser($this->reader)->allows(PermissionEnum::CanDeleteUsers->value))->toBeFalse();
});

test('effective permission resolver lists every permission for super admin', function (): void {
    $resolver = app(EffectivePermissionResolverService::class);

    expect($resolver->isSuperAdmin($this->superAdmin))->toBeTrue()
        ->and($resolver->permissionsFor($this->superAdmin))->toBe(PermissionEnum::values())
        ->and($resolver->hasPermission($this->superAdmin, PermissionEnum::CanManageSettings->value))->toBeTrue();
});

test('inertia shares auth.permissions for the signed-in user', function (): void {
    $this->withoutVite()
        ->actingAs($this->reader)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.isSuperAdmin', false)
            ->where('auth.roleNames', [RoleEnum::Reader->value])
            ->where('auth.permissions', fn ($permissions): bool => collect($permissions)->contains(PermissionEnum::CanShowUsers->value)
                && ! collect($permissions)->contains(PermissionEnum::CanDeleteUsers->value)));
});
