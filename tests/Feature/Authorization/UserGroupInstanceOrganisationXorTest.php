<?php

declare(strict_types=1);

use App\Models\Organisation;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\InstanceSuperAdminGroupService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * DB XOR: is_instance=true ⇔ organisation_id IS NULL. Runs on whatever driver CI uses
 * (SQLite in app CI; MySQL / PostgreSQL in conformance).
 */
beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

test('valid instance and organisation group shapes insert successfully', function (): void {
    $organisation = Organisation::factory()->create();

    $instance = new UserGroup;
    $instance->forceFill([
        'name' => InstanceSuperAdminGroupService::GROUP_NAME,
        'organisation_id' => null,
        'is_instance' => true,
    ])->save();

    $organisationGroup = new UserGroup;
    $organisationGroup->forceFill([
        'name' => 'Org editors',
        'organisation_id' => $organisation->id,
        'is_instance' => false,
    ])->save();

    expect($instance->fresh()->organisation_id)->toBeNull()
        ->and($instance->fresh()->is_instance)->toBeTrue()
        ->and($organisationGroup->fresh()->organisation_id)->toBe($organisation->id)
        ->and($organisationGroup->fresh()->is_instance)->toBeFalse();
});

test('is_instance true with an organisation_id is rejected by the database', function (): void {
    $organisation = Organisation::factory()->create();

    expect(fn () => DB::table('user_groups')->insert([
        'name' => 'Broken instance',
        'organisation_id' => $organisation->id,
        'is_instance' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('is_instance false without an organisation_id is rejected by the database', function (): void {
    expect(fn () => DB::table('user_groups')->insert([
        'name' => 'Broken org group',
        'organisation_id' => null,
        'is_instance' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('isSuperAdmin requires is_instance and a null organisation_id', function (): void {
    $resolver = app(EffectivePermissionResolverService::class);
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create();

    // Bypass Eloquent mass-assignment then patch attributes in memory only: a row that
    // violates XOR cannot be persisted; the resolver must still refuse a warm model that
    // looks like is_instance with an organisation.
    $fake = new UserGroup;
    $fake->forceFill([
        'id' => 999_999,
        'name' => 'Memory-only',
        'organisation_id' => $organisation->id,
        'is_instance' => true,
    ]);
    $fake->exists = true;
    $user->setRelation('groups', collect([$fake]));

    expect($resolver->isSuperAdmin($user))->toBeFalse();

    grantInstanceSuperAdmin($user);
    $resolver->forget($user);

    expect($resolver->isSuperAdmin($user))->toBeTrue()
        ->and(UserGroup::query()->where('is_instance', true)->sole()->organisation_id)->toBeNull();
});
