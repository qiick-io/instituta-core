<?php

namespace Tests\Feature\Auth;

use App\Services\SettingsRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_is_disabled_by_default(): void
    {
        expect(app(SettingsRepositoryService::class)->isRegistrationEnabled())->toBeFalse();

        $this->get(route('register'))->assertForbidden();
    }

    public function test_registration_screen_can_be_rendered_when_enabled(): void
    {
        app(SettingsRepositoryService::class)->save(['registration_enabled' => true]);

        $this->get(route('register'))->assertOk();
    }

    public function test_new_users_can_register_when_enabled(): void
    {
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
    }
}
