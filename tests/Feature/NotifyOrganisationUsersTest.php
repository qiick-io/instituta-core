<?php

declare(strict_types=1);

use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use App\Services\NotifyOrganisationUsersService;

test('users for organisation excludes inactive users and deactivated principals', function () {
    $organisation = Organisation::factory()->create();

    $active = User::factory()->create(['is_active' => true]);
    $inactive = User::factory()->inactive()->create();
    $deactivatedPrincipalUser = User::factory()->create(['is_active' => true]);

    Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $active->id,
    ]);
    Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $inactive->id,
    ]);
    Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $deactivatedPrincipalUser->id,
        'deactivated_at' => now(),
    ]);

    $users = app(NotifyOrganisationUsersService::class)
        ->usersForOrganisation($organisation->id);

    expect($users->pluck('id')->all())->toBe([$active->id]);
});
