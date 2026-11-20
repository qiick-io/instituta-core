<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\CreateSuperAdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAgencySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

/**
 * The initial super admin every qiick app seeds, under the shared fleet password rule.
 */
beforeEach(function (): void {
    config([
        'super_admin.first_name' => 'Super',
        'super_admin.last_name' => 'Admin',
        'super_admin.email' => 'superadmin@example.com',
        'super_admin.password' => 'Fleet-Secret-9x',
    ]);

    // Password::defaults() with the strong policy calls a breach API. A local rule keeps
    // these tests offline while still exercising Password::defaults().
    Password::defaults(fn (): Password => Password::min(8)->mixedCase()->numbers());
});

it('seeds a verified, active super admin with the configured password', function (): void {
    $this->seed(DatabaseSeeder::class);

    $superAdmin = User::query()->where('email', 'superadmin@example.com')->sole();

    expect($superAdmin->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
        ->and($superAdmin->is_active)->toBeTrue()
        ->and($superAdmin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Fleet-Secret-9x', $superAdmin->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('is a no-op when the email already exists in every environment', function (): void {
    $this->seed(DatabaseSeeder::class);
    $existing = User::query()->where('email', 'superadmin@example.com')->sole();
    $existing->forceFill([
        'password' => 'changed-locally',
        'is_active' => false,
        'email_verified_at' => null,
        'first_name' => 'Existing',
    ])->save();

    $this->seed(CreateSuperAdminSeeder::class);

    $existing->refresh();

    expect(User::query()->where('email', 'superadmin@example.com')->count())->toBe(1)
        ->and($existing->first_name)->toBe('Existing')
        ->and($existing->is_active)->toBeFalse()
        ->and($existing->email_verified_at)->toBeNull()
        ->and(Hash::check('changed-locally', $existing->password))->toBeTrue();
});

it('seeds the local default password in local and testing', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);
    config(['super_admin.password' => 'password']);

    $this->seed(DatabaseSeeder::class);

    $superAdmin = User::query()->where('email', 'superadmin@example.com')->sole();

    expect($superAdmin->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
        ->and(Hash::check('password', $superAdmin->password))->toBeTrue();
})->with(['local', 'testing']);

it('creates a super admin outside local and testing with a strong password', function (string $environment): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app()->detectEnvironment(fn (): string => $environment);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])->assertSuccessful();

    $superAdmin = User::query()->where('email', 'superadmin@example.com')->sole();

    expect($superAdmin->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
        ->and($superAdmin->is_active)->toBeTrue()
        ->and($superAdmin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Fleet-Secret-9x', $superAdmin->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
})->with(['production', 'staging']);

it('skips outside local and testing when the password is the known default', function (string $password): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['super_admin.password' => $password]);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('Skipped the initial super admin: INITIAL_SUPER_ADMIN_PASSWORD is the default password.')
        ->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
})->with(['password', 'PASSWORD']);

it('skips outside local and testing when the password is weak', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['super_admin.password' => 'short']);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('Skipped the initial super admin: INITIAL_SUPER_ADMIN_PASSWORD does not meet Password::defaults()')
        ->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
});

it('skips outside local and testing when the password is not set', function (?string $password): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['super_admin.password' => $password]);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('Skipped the initial super admin: INITIAL_SUPER_ADMIN_PASSWORD is not set.')
        ->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
})->with(['unset' => [null], 'empty' => ['']]);

it('never modifies an existing user outside local and testing', function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app()->detectEnvironment(fn (): string => 'production');

    $existing = User::factory()->create([
        'email' => 'superadmin@example.com',
        'first_name' => 'Existing',
        'last_name' => 'Operator',
        'password' => 'original-secret-password',
        'is_active' => false,
        'email_verified_at' => null,
    ]);
    $existing->assignRole(RoleEnum::Admin->value);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])->assertSuccessful();

    $existing->refresh();

    expect(User::query()->count())->toBe(1)
        ->and($existing->first_name)->toBe('Existing')
        ->and($existing->last_name)->toBe('Operator')
        ->and($existing->is_active)->toBeFalse()
        ->and($existing->email_verified_at)->toBeNull()
        ->and(Hash::check('original-secret-password', $existing->password))->toBeTrue()
        ->and($existing->hasRole(RoleEnum::Admin->value))->toBeTrue()
        ->and($existing->hasRole(RoleEnum::SuperAdmin->value))->toBeFalse();
});

it('gives the super admin owner access to the demo brain', function (): void {
    Storage::fake('local');
    $this->withoutVite();

    $this->seed(DemoAgencySeeder::class);

    $superAdmin = User::query()->where('email', 'superadmin@example.com')->sole();
    $grant = $superAdmin->principals()->sole()->grants()->sole();

    expect($superAdmin->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
        ->and($grant->role->value)->toBe('owner');
    $this->actingAs($superAdmin)->get(route('explorer.show', 'rules/coding'))->assertOk();
});
