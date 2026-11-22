<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

/**
 * Fleet users baseline: public_id UUID v7 is the external id; password may be null.
 */
it('assigns a uuid v7 public_id and never exposes the bigint id in the admin resource', function (): void {
    $admin = assignOrganisationRole(User::factory()->create(), RoleEnum::Admin->value);
    $user = User::factory()->create();

    expect(Str::isUuid($user->public_id))->toBeTrue()
        ->and(strlen($user->public_id))->toBe(36);

    $response = $this->actingAs($admin)
        ->getJson(route('users.index'))
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('email', $user->email);

    expect($row)->not->toBeNull()
        ->and($row['id'])->toBe($user->public_id)
        ->and($row['public_id'])->toBe($user->public_id)
        ->and($row['id'])->not->toBe($user->id);
});

it('allows a user without a password', function (): void {
    $user = User::factory()->create(['password' => null]);

    expect($user->fresh()->password)->toBeNull();
});
