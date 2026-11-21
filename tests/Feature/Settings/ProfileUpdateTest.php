<?php

declare(strict_types=1);

use App\Brain\Enums\ScopeTypeEnum;
use App\Models\ApiKey;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

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

it('user deletion erases personal data from app tables', function (): void {
    $user = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane.doe@example.com',
        'username' => 'janedoe',
        'last_login_ip' => '203.0.113.7',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $organisation = Organisation::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $user->id,
        'name' => 'Jane Doe',
    ]);
    $grant = Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
    ]);
    $apiKey = ApiKey::factory()->create([
        'owner_principal_id' => $principal->id,
        'created_by' => $user->id,
        'name' => 'Jane key',
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $organisation->id,
    ]);

    DB::table('sessions')->insert([
        'id' => 'sess-janedoe',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'test',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    DB::table('password_reset_tokens')->insert([
        'email' => 'jane.doe@example.com',
        'token' => 'token',
        'created_at' => now(),
    ]);

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['email' => 'jane.doe@example.com', 'name' => 'Jane Doe']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    activity()->causedBy($user)->performedOn($user)->withProperties([
        'attributes' => ['email' => 'jane.doe@example.com', 'first_name' => 'Jane'],
        'old' => ['email' => 'old@example.com', 'first_name' => 'Old'],
    ])->event('updated')->log('updated');

    activity()->performedOn($principal)->withProperties([
        'attributes' => ['name' => 'Jane Doe'],
        'old' => ['name' => 'Old Name'],
    ])->event('updated')->log('updated');

    activity()->performedOn($apiKey)->withProperties([
        'attributes' => ['name' => 'Jane key'],
        'old' => ['name' => 'Old key'],
    ])->event('updated')->log('updated');

    $this->actingAs($user)->delete(route('profile.destroy'), [
        'password' => 'password',
    ])->assertRedirect(route('home'));

    $erasedUser = User::withTrashed()->findOrFail($user->id);
    expect($erasedUser->email)->not->toBe('jane.doe@example.com')
        ->and($erasedUser->username)->not->toBe('janedoe')
        ->and($erasedUser->last_login_ip)->toBeNull()
        ->and($erasedUser->two_factor_secret)->toBeNull()
        ->and($erasedUser->two_factor_recovery_codes)->toBeNull()
        ->and($erasedUser->two_factor_confirmed_at)->toBeNull()
        ->and(Hash::check('password', (string) $erasedUser->password))->toBeFalse();

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
    expect(DB::table('password_reset_tokens')->where('email', 'jane.doe@example.com')->exists())->toBeFalse();
    expect(DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->exists())->toBeFalse();

    $erasedPrincipal = Principal::query()->findOrFail($principal->id);
    expect($erasedPrincipal->name)->not->toBe('Jane Doe');

    $erasedKey = ApiKey::query()->findOrFail($apiKey->id);
    expect($erasedKey->name)->not->toBe('Jane key');

    $needles = ['jane.doe@example.com', 'janedoe', 'Jane Doe', 'Jane key', '203.0.113.7'];
    $activityRows = Activity::query()->get();
    foreach ($activityRows as $activity) {
        $blob = json_encode([$activity->properties?->toArray(), $activity->attribute_changes?->toArray(), $activity->description]);
        foreach ($needles as $needle) {
            expect($blob)->not->toContain($needle);
        }
    }
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
