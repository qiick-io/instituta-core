<?php

declare(strict_types=1);

use App\Services\SettingsRepositoryService;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->withoutVite();
    $this->skipUnlessFortifyHas(Features::registration());
});

it('registration is disabled by default', function (): void {
    expect(app(SettingsRepositoryService::class)->isRegistrationEnabled())->toBeFalse();

    $this->get(route('register'))->assertForbidden();
});

it('registration screen can be rendered when enabled', function (): void {
    app(SettingsRepositoryService::class)->save(['registration_enabled' => true]);

    $this->get(route('register'))->assertOk();
});

it('new users can register when enabled', function (): void {
    app(SettingsRepositoryService::class)->save(['registration_enabled' => true]);

    $response = $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});
