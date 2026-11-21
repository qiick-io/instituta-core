<?php

declare(strict_types=1);

use App\Models\User;

it('profile page is displayed', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

it('profile information can be updated', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'locale' => 'it',
            'timezone' => 'Europe/Rome',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'))
        ->assertCookie('locale', 'it', encrypted: false);

    $user->refresh();

    $this->assertSame('Test', $user->first_name);
    $this->assertSame('User', $user->last_name);
    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertSame('it', $user->locale);
    $this->assertSame('Europe/Rome', $user->timezone);
    $this->assertNull($user->email_verified_at);
});

it('email is stored in lowercase so the user can still log in', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'Test@Example.com',
            'locale' => 'en',
            'timezone' => 'UTC',
        ])
        ->assertSessionHasNoErrors();

    $this->assertSame('test@example.com', $user->refresh()->email);

    $this->post(route('logout'));

    $this->post(route('login.store'), [
        'email' => 'Test@Example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

it('email must be unique regardless of case', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'Taken@Example.com',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

    $response
        ->assertSessionHasErrors('email')
        ->assertRedirect(route('profile.edit'));
});

it('email verification status is unchanged when the email address is unchanged', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $this->assertNotNull($user->refresh()->email_verified_at);
});

it('user can delete their account', function (): void {
    $user = User::factory()->create();
    $email = $user->email;
    $publicId = $user->public_id;

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $this->assertSoftDeleted($user);

    $erasedUser = User::withTrashed()->findOrFail($user->id);
    $this->assertSame('deleted+'.$publicId.'@example.invalid', $erasedUser->email);
    $this->assertSame('Deleted', $erasedUser->first_name);
    $this->assertNull($erasedUser->last_name);
});

it('correct password must be provided to delete account', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    $this->assertNotNull($user->fresh());
});
