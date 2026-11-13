<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;

beforeEach(function (): void {
    $this->withoutVite();
});

it('creates the first CLI user as super-admin who can log in', function (): void {
    $this->artisan('instituta:create-user')
        ->expectsQuestion('First name', 'Ada')
        ->expectsQuestion('Last name', 'Lovelace')
        ->expectsQuestion('Email', 'Ada@Example.com')
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsChoice('Role', 'super-admin', ['admin', 'reader', 'super-admin'])
        ->assertSuccessful();

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

    expect($user->is_active)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue();

    $this->post(route('login.store'), [
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('defaults later users to the reader role', function (): void {
    User::factory()->create();

    $this->artisan('instituta:create-user', [
        '--first-name' => 'Bob',
        '--last-name' => 'Builder',
        '--email' => 'bob@example.com',
    ])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsChoice('Role', 'reader', ['admin', 'reader', 'super-admin'])
        ->assertSuccessful();

    $user = User::query()->where('email', 'bob@example.com')->firstOrFail();

    expect($user->hasRole(RoleEnum::Reader->value))->toBeTrue();
});

it('refuses a duplicate email from the CLI', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('instituta:create-user', [
        '--first-name' => 'Ada',
        '--last-name' => 'Lovelace',
        '--email' => 'ada@example.com',
    ])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsChoice('Role', 'reader', ['admin', 'reader', 'super-admin'])
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});
