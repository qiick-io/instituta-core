<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\CreateSuperAdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAgencySeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * The development super admin every qiick app seeds (#362).
 */
beforeEach(function (): void {
    config([
        'super_admin.email' => 'superadmin@example.com',
        'super_admin.password' => 'password',
    ]);
});

it('seeds a verified, active super admin with the configured password', function (): void {
    $this->seed(DatabaseSeeder::class);

    $superAdmin = User::query()->where('email', 'superadmin@example.com')->sole();

    expect($superAdmin->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue()
        ->and($superAdmin->is_active)->toBeTrue()
        ->and($superAdmin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $superAdmin->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('keeps an existing password and seeds only once', function (): void {
    $this->seed(DatabaseSeeder::class);
    User::query()->where('email', 'superadmin@example.com')->sole()->update(['password' => 'changed-locally']);

    $this->seed(CreateSuperAdminSeeder::class);

    expect(User::query()->where('email', 'superadmin@example.com')->count())->toBe(1)
        ->and(Hash::check('changed-locally', User::query()->where('email', 'superadmin@example.com')->sole()->password))->toBeTrue();
});

it('seeds nobody outside local and testing, or without a password', function (string $environment, ?string $password): void {
    app()->detectEnvironment(fn (): string => $environment);
    config(['super_admin.password' => $password]);

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0);
})->with([
    'production' => ['production', 'password'],
    'staging' => ['staging', 'password'],
    'no password' => ['local', null],
    'empty password' => ['local', ''],
]);

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
