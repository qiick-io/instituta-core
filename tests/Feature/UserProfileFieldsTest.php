<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('composes the display name from first and last name', function (): void {
    $user = User::factory()->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);

    expect($user->name)->toBe('Ada Lovelace');
});

it('trims a missing last name from the display name', function (): void {
    $user = User::factory()->create([
        'first_name' => 'Ada',
        'last_name' => null,
    ]);

    expect($user->name)->toBe('Ada');
});

it('persists locale timezone and is_active defaults from the factory', function (): void {
    $user = User::factory()->create();

    expect($user->locale)->toBe('en')
        ->and($user->timezone)->toBe('UTC')
        ->and($user->is_active)->toBeTrue();
});
