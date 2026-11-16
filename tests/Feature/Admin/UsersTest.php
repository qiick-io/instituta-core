<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum as BrainRoleEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function managedUser(RoleEnum $role = RoleEnum::Reader, array $attributes = []): User
{
    return User::factory()->create($attributes)->assignRole($role->value);
}

/**
 * @return array<string, mixed>
 */
function userPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'password' => 'correct-horse-battery',
        'roles' => [RoleEnum::Reader->value],
        'is_active' => true,
        ...$overrides,
    ];
}

it('lets a reader list users but not write', function (): void {
    // RoleSeeder grants every `can-show-*` to reader, including can-show-users.
    $reader = managedUser();
    $other = managedUser();

    $this->actingAs($reader)->get(route('users.index'))->assertOk();
    $this->actingAs($reader)->post(route('users.store'), userPayload())->assertForbidden();
    $this->actingAs($reader)->put(route('users.update', $other), userPayload())->assertForbidden();
    $this->actingAs($reader)->delete(route('users.destroy', $other))->assertForbidden();
    $this->actingAs($reader)->post(route('users.password-reset', $other))->assertForbidden();
});

it('lists users for an admin', function (): void {
    $admin = managedUser(RoleEnum::Admin);
    managedUser();

    $this->actingAs($admin)->get(route('users.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/users/index')
            ->has('users.data', 2)
            ->where('roles', fn ($roles) => collect($roles)->pluck('name')->all() === [RoleEnum::Admin->value, RoleEnum::Reader->value]));
});

it('searches, sorts and paginates users', function (): void {
    $admin = managedUser(RoleEnum::Admin, ['first_name' => 'Zed', 'last_name' => 'Admin']);
    managedUser(attributes: ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);
    managedUser(attributes: ['first_name' => 'Alan', 'last_name' => 'Turing']);

    $this->actingAs($admin)->get(route('users.index', ['search' => 'ada@', 'sort' => 'name', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Ada Lovelace')
            ->where('filters', ['search' => 'ada@', 'sort' => 'name', 'direction' => 'asc']));

    foreach (range(1, 8) as $index) {
        managedUser(attributes: ['first_name' => 'Zz', 'last_name' => "User {$index}"]);
    }

    $this->actingAs($admin)->get(route('users.index', ['sort' => 'name', 'direction' => 'asc', 'per_page' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 10)
            ->where('users.data.0.name', 'Ada Lovelace')
            ->where('users.meta.per_page', 10)
            ->where('users.meta.last_page', 2));

    $this->actingAs($admin)->get(route('users.index', ['per_page' => 7]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('users.meta.per_page', 20));
});

it('creates a verified user with roles', function (): void {
    $this->actingAs(managedUser(RoleEnum::Admin))
        ->post(route('users.store'), userPayload())
        ->assertRedirect(route('users.index'));

    $user = User::query()->where('email', 'grace@example.com')->sole();

    expect($user->hasRole(RoleEnum::Reader->value))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->is_active)->toBeTrue();
});

it('validates new users', function (): void {
    $admin = managedUser(RoleEnum::Admin);

    $this->actingAs($admin)
        ->post(route('users.store'), userPayload(['email' => $admin->email, 'roles' => []]))
        ->assertSessionHasErrors(['email', 'roles']);
});

it('updates a user and keeps the password when empty', function (): void {
    $user = managedUser();
    $password = $user->password;

    $this->actingAs(managedUser(RoleEnum::Admin))
        ->put(route('users.update', $user), userPayload(['password' => '', 'roles' => [RoleEnum::Admin->value]]))
        ->assertRedirect(route('users.index'));

    $user->refresh();

    expect($user->name)->toBe('Grace Hopper')
        ->and($user->password)->toBe($password)
        ->and($user->getRoleNames()->all())->toBe([RoleEnum::Admin->value]);
});

it('deactivates a user, who can then not log in', function (): void {
    $user = managedUser();

    $this->actingAs(managedUser(RoleEnum::Admin))
        ->put(route('users.update', $user), userPayload(['email' => $user->email, 'password' => '', 'is_active' => false]))
        ->assertRedirect(route('users.index'));

    expect($user->fresh()->is_active)->toBeFalse();

    auth()->logout();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('deletes a user', function (): void {
    $user = managedUser();

    $this->actingAs(managedUser(RoleEnum::Admin))
        ->delete(route('users.destroy', $user))
        ->assertRedirect(route('users.index'));

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('blocks deleting or deactivating yourself', function (): void {
    $admin = managedUser(RoleEnum::Admin);

    $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertForbidden();
    $this->actingAs($admin)
        ->put(route('users.update', $admin), userPayload(['email' => $admin->email, 'roles' => [RoleEnum::Admin->value], 'is_active' => false]))
        ->assertSessionHasErrors('is_active');

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('protects the last super-admin', function (): void {
    $superAdmin = managedUser(RoleEnum::SuperAdmin);
    managedUser(RoleEnum::SuperAdmin, ['is_active' => false]);

    $this->actingAs($superAdmin)
        ->put(route('users.update', $superAdmin), userPayload(['email' => $superAdmin->email, 'roles' => [RoleEnum::Admin->value]]))
        ->assertSessionHasErrors('roles');

    expect($superAdmin->fresh()->hasRole(RoleEnum::SuperAdmin->value))->toBeTrue();

    $second = managedUser(RoleEnum::SuperAdmin);

    $this->actingAs($second)
        ->put(route('users.update', $superAdmin), userPayload(['email' => $superAdmin->email, 'roles' => [RoleEnum::Admin->value]]))
        ->assertSessionHasNoErrors();

    expect($superAdmin->fresh()->hasRole(RoleEnum::SuperAdmin->value))->toBeFalse();

    $this->actingAs($second)->delete(route('users.destroy', $second))->assertForbidden();
});

it('lets only a super-admin manage super-admins', function (): void {
    $admin = managedUser(RoleEnum::Admin);
    $superAdmin = managedUser(RoleEnum::SuperAdmin);
    $reader = managedUser();

    $this->actingAs($admin)
        ->put(route('users.update', $superAdmin), userPayload(['email' => $superAdmin->email, 'roles' => [RoleEnum::Admin->value]]))
        ->assertForbidden();
    $this->actingAs($admin)->delete(route('users.destroy', $superAdmin))->assertForbidden();
    $this->actingAs($admin)
        ->put(route('users.update', $reader), userPayload(['email' => $reader->email, 'roles' => [RoleEnum::SuperAdmin->value]]))
        ->assertSessionHasErrors('roles.0');
    $this->actingAs($admin)
        ->put(route('users.update', $admin), userPayload(['email' => $admin->email, 'roles' => [RoleEnum::SuperAdmin->value]]))
        ->assertSessionHasErrors('roles.0');

    expect($reader->fresh()->hasRole(RoleEnum::SuperAdmin->value))->toBeFalse();
});

it('sends a password-reset email through Fortify', function (): void {
    Notification::fake();
    $user = managedUser();

    $this->actingAs(managedUser(RoleEnum::Admin))
        ->post(route('users.password-reset', $user))
        ->assertRedirect();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('splits user permissions per operation', function (): void {
    Role::query()->create(['name' => 'user-viewer', 'guard_name' => 'web'])
        ->givePermissionTo(PermissionEnum::CanShowUsers->value);

    // Editor holds every permission of `limited-reader`, so RoleGrantService lets them touch it.
    Role::query()->create(['name' => 'user-editor', 'guard_name' => 'web'])
        ->givePermissionTo([
            PermissionEnum::CanShowUsers->value,
            PermissionEnum::CanEditUsers->value,
        ]);

    Role::query()->create(['name' => 'limited-reader', 'guard_name' => 'web'])
        ->givePermissionTo(PermissionEnum::CanShowUsers->value);

    $viewer = User::factory()->create()->assignRole('user-viewer');
    $editor = User::factory()->create()->assignRole('user-editor');
    $target = User::factory()->create()->assignRole('limited-reader');

    $this->actingAs($viewer)->get(route('users.index'))->assertOk();
    $this->actingAs($viewer)->post(route('users.store'), userPayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('users.update', $target), userPayload(['email' => $target->email]))->assertForbidden();

    $this->actingAs($editor)->post(route('users.store'), userPayload())->assertForbidden();
    $this->actingAs($editor)->delete(route('users.destroy', $target))->assertForbidden();
    $this->actingAs($editor)
        ->put(route('users.update', $target), userPayload([
            'email' => $target->email,
            'password' => '',
            'roles' => ['limited-reader'],
        ]))
        ->assertRedirect(route('users.index'));

    expect($target->fresh()->first_name)->toBe('Grace');
});

it('lists the roles the user may grant for the role picker', function (): void {
    $admin = managedUser(RoleEnum::Admin);

    $this->actingAs($admin)->getJson(route('users.assignable-roles'))
        ->assertOk()
        ->assertJsonPath('last_page', 1)
        ->assertJson(fn ($json) => $json->has('data')->etc());

    $names = collect($this->actingAs($admin)->getJson(route('users.assignable-roles'))->json('data'))->pluck('name');
    expect($names)->not->toContain(RoleEnum::SuperAdmin->value)
        ->and(collect($this->actingAs($admin)->getJson(route('users.assignable-roles', ['search' => 'read']))->json('data'))->pluck('name')->all())
        ->toBe([RoleEnum::Reader->value]);

    $this->actingAs(User::factory()->create())->getJson(route('users.assignable-roles'))->assertForbidden();
});

it('makes a user created by an admin a member of the admin organisation', function (): void {
    $admin = managedUser(RoleEnum::Admin);
    $organisation = Organisation::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($admin, $organisation, BrainRoleEnum::Owner);

    $this->actingAs($admin)->post(route('users.store'), userPayload())->assertRedirect(route('users.index'));

    $created = User::query()->where('email', 'grace@example.com')->firstOrFail();
    $grant = Grant::query()->where('principal_id', $created->principals()->sole()->id)->sole();

    expect($grant->scope_id)->toBe($organisation->id)
        ->and($grant->role)->toBe(BrainRoleEnum::Member);
});

it('creates a user without a perimeter when the admin has none', function (): void {
    $admin = managedUser(RoleEnum::Admin);

    $this->actingAs($admin)->post(route('users.store'), userPayload())->assertRedirect(route('users.index'));

    expect(User::query()->where('email', 'grace@example.com')->firstOrFail()->principals()->count())->toBe(0);
});
