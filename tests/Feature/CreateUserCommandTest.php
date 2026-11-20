<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\RoleEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

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

it('normalizes and validates the optional CLI username', function (): void {
    $this->artisan('instituta:create-user', [
        '--first-name' => 'Ada',
        '--last-name' => 'Lovelace',
        '--username' => '  Ada.Lovelace  ',
        '--email' => 'ada@example.com',
        '--role' => 'reader',
    ])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->assertSuccessful();

    expect(User::query()->where('email', 'ada@example.com')->sole()->username)->toBe('ada.lovelace');

    $this->artisan('instituta:create-user', [
        '--first-name' => 'Grace',
        '--last-name' => 'Hopper',
        '--username' => 'ADA.LOVELACE',
        '--email' => 'grace@example.com',
        '--role' => 'reader',
    ])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsOutputToContain('The username has already been taken.')
        ->assertFailed();

    expect(User::query()->where('email', 'grace@example.com')->exists())->toBeFalse();
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

/**
 * An organisation with one internal rules item, so the map shows whether a user sees it.
 */
function organisationWithRulesItem(string $name): Organisation
{
    $organisation = Organisation::factory()->create(['name' => $name, 'slug' => Str::slug($name)]);
    $company = Company::factory()->create(['organisation_id' => $organisation->id]);
    $source = Source::factory()->create(['organisation_id' => $organisation->id, 'company_id' => $company->id]);
    Item::factory()->create([
        'organisation_id' => $organisation->id,
        'company_id' => $company->id,
        'source_id' => $source->id,
        'area' => 'rules',
        'title' => "{$name} rules",
        'path' => 'rules/coding.md',
        'stable_key' => 'rules/coding',
        'visibility' => VisibilityEnum::Internal,
    ]);

    return $organisation;
}

it('gives a CLI user a perimeter in the only organisation so the brain is not empty', function (): void {
    $organisation = organisationWithRulesItem('Acme');

    $this->artisan('instituta:create-user', [
        '--first-name' => 'Ada',
        '--last-name' => 'Lovelace',
        '--email' => 'ada@example.com',
        '--role' => 'super-admin',
    ])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsOutputToContain('Granted owner access to organisation Acme')
        ->assertSuccessful();

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $principal = $user->principals()->sole();

    expect($principal->organisation_id)->toBe($organisation->id)
        ->and(Grant::query()->where('principal_id', $principal->id)->sole()->role)->toBe(BrainRoleEnum::Owner);

    $this->actingAs($user)->get(route('explorer.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasPerimeter', true)
            ->where('total', 1));
});

it('makes later people of an organisation members and keeps other organisations out', function (): void {
    $acme = organisationWithRulesItem('Acme');
    organisationWithRulesItem('Globex');
    Principal::factory()->create(['organisation_id' => $acme->id, 'type' => 'person']);

    $this->artisan('instituta:create-user', [
        '--first-name' => 'Bob',
        '--email' => 'bob@example.com',
        '--role' => 'reader',
        '--organisation' => 'acme',
    ])
        ->expectsQuestion('Last name', '')
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->assertSuccessful();

    $user = User::query()->where('email', 'bob@example.com')->firstOrFail();
    $grant = Grant::query()->where('principal_id', $user->principals()->sole()->id)->sole();

    expect($grant->scope_id)->toBe($acme->id)
        ->and($grant->role)->toBe(BrainRoleEnum::Member)
        ->and($grant->max_visibility)->toBe(VisibilityEnum::Internal);

    $this->actingAs($user)->get(route('explorer.index'))
        ->assertInertia(fn (Assert $page) => $page->where('total', 1));
});

it('asks for the organisation when there are several and honours the brain role option', function (): void {
    organisationWithRulesItem('Acme');
    $globex = organisationWithRulesItem('Globex');

    $this->artisan('instituta:create-user', [
        '--first-name' => 'Cy',
        '--last-name' => 'Guest',
        '--email' => 'cy@example.com',
        '--role' => 'reader',
        '--brain-role' => 'guest',
    ])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsChoice('Organisation', $globex->id, [$globex->id => 'Globex', Organisation::query()->where('slug', 'acme')->value('id') => 'Acme'])
        ->assertSuccessful();

    $grant = Grant::query()
        ->where('principal_id', User::query()->where('email', 'cy@example.com')->firstOrFail()->principals()->sole()->id)
        ->sole();

    expect($grant->scope_id)->toBe($globex->id)
        ->and($grant->role)->toBe(BrainRoleEnum::Guest)
        ->and($grant->max_visibility)->toBe(VisibilityEnum::Public);
});

it('refuses an unknown organisation or brain role without creating the user', function (array $options): void {
    organisationWithRulesItem('Acme');

    $this->artisan('instituta:create-user', [
        '--first-name' => 'Ada',
        '--last-name' => 'Lovelace',
        '--email' => 'ada@example.com',
        '--role' => 'reader',
        ...$options,
    ])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'unknown organisation' => [['--organisation' => 'nope']],
    'unknown brain role' => [['--brain-role' => 'admin']],
]);

it('warns that a user created before any organisation has no perimeter', function (): void {
    $this->artisan('instituta:create-user', [
        '--first-name' => 'Ada',
        '--last-name' => 'Lovelace',
        '--email' => 'ada@example.com',
        '--role' => 'reader',
    ])
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->expectsOutputToContain('Run instituta:install')
        ->assertSuccessful();

    expect(Principal::query()->count())->toBe(0);
});
