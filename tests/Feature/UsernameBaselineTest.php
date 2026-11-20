<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function usernameUserPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'password' => 'correct-horse-battery',
        'roles' => [RoleEnum::Reader->value],
        'is_active' => true,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function usernameProfilePayload(array $overrides = []): array
{
    return [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'locale' => 'en',
        'timezone' => 'UTC',
        ...$overrides,
    ];
}

it('adds a nullable unique username column before email', function (): void {
    $columns = Schema::getColumnListing('users');

    expect($columns)->toContain('username')
        ->and(User::factory()->create(['username' => null])->username)->toBeNull();

    expect(array_search('username', $columns, true))
        ->toBe(array_search('email', $columns, true) - 1);
});

it('trims and lowercases usernames on the model and stores an empty string as null', function (): void {
    $user = User::factory()->create(['username' => '  LuCa.Hopper  ']);

    expect($user->username)->toBe('luca.hopper');

    $blank = User::factory()->create(['username' => '   ']);

    expect($blank->username)->toBeNull();
});

it('creates admin users with and without a username', function (): void {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);

    $this->actingAs($admin)
        ->postJson(route('users.store'), usernameUserPayload(['username' => '  Grace.Hopper  ']))
        ->assertRedirect(route('users.index'));

    $this->actingAs($admin)
        ->postJson(route('users.store'), usernameUserPayload([
            'email' => 'alan@example.com',
            'username' => '',
        ]))->assertRedirect(route('users.index'));

    expect(User::query()->where('email', 'grace@example.com')->sole()->username)->toBe('grace.hopper')
        ->and(User::query()->where('email', 'alan@example.com')->sole()->username)->toBeNull();
});

it('updates an admin username and clears it with an empty string', function (): void {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    $target = User::factory()->create(['username' => 'grace.hopper']);

    $this->actingAs($admin)
        ->putJson(route('users.update', $target), usernameUserPayload([
            'email' => $target->email,
            'username' => '  New.Name  ',
        ]))->assertRedirect(route('users.index'));

    expect($target->fresh()->username)->toBe('new.name');

    $this->actingAs($admin)
        ->putJson(route('users.update', $target), usernameUserPayload([
            'email' => $target->email,
            'username' => '',
        ]))->assertRedirect(route('users.index'));

    expect($target->fresh()->username)->toBeNull();
});

it('rejects a duplicate username regardless of case on create and update', function (): void {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);
    User::factory()->create(['username' => 'luca']);
    $target = User::factory()->create(['username' => 'grace.hopper']);

    $this->actingAs($admin)
        ->postJson(route('users.store'), usernameUserPayload(['username' => 'Luca']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');

    $this->actingAs($admin)
        ->putJson(route('users.update', $target), usernameUserPayload([
            'email' => $target->email,
            'username' => 'LUCA',
        ]))->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('rejects invalid username characters', function (): void {
    $admin = User::factory()->create()->assignRole(RoleEnum::Admin->value);

    $this->actingAs($admin)
        ->postJson(route('users.store'), usernameUserPayload(['username' => 'Bad Name!']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');

    $this->actingAs($admin)
        ->postJson(route('users.store'), usernameUserPayload([
            'email' => 'other@example.com',
            'username' => 'ab',
        ]))->assertUnprocessable()
        ->assertJsonValidationErrors('username');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson(route('profile.update'), usernameProfilePayload(['username' => 'bad@name']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('updates the profile username, keeps the current one and clears it with an empty string', function (): void {
    $user = User::factory()->create(['username' => 'grace.hopper']);

    $this->actingAs($user)
        ->patchJson(route('profile.update'), usernameProfilePayload(['username' => ' Grace.New ']))
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->username)->toBe('grace.new');

    $this->actingAs($user)
        ->patchJson(route('profile.update'), usernameProfilePayload(['username' => 'grace.new']))
        ->assertRedirect(route('profile.edit'));

    $this->actingAs($user)
        ->patchJson(route('profile.update'), usernameProfilePayload(['username' => '']))
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->username)->toBeNull();
});

it('stores usernames lowercase so the database unique key catches a case-only duplicate', function (): void {
    User::factory()->create(['username' => 'luca']);

    expect(fn (): User => User::factory()->create(['username' => 'LUCA']))->toThrow(QueryException::class);
});
