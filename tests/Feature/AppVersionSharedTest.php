<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AppVersionService;
use Inertia\Testing\AssertableInertia as Assert;

it('shares the app version on authenticated inertia pages', function (): void {
    $user = User::factory()->create();
    $expectedVersion = app(AppVersionService::class)->current();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('appVersion', $expectedVersion));
});

it('resolves a non-empty app version string', function (): void {
    expect(app(AppVersionService::class)->current())->not->toBe('');
});
