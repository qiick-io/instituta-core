<?php

declare(strict_types=1);

use App\Models\User;

it('sends guests from the root URL to the login page', function (): void {
    $this->get(route('home'))->assertRedirect(route('login'));
});

it('sends signed-in users from the root URL to the dashboard', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
