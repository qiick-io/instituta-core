<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Company;
use App\Models\Grant;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Product;
use App\Models\User;
use App\Services\SessionPerimeterService;
use Inertia\Testing\AssertableInertia as Assert;

function scopeSwitcherSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Qiick Org']);
    $company = Company::factory()->create([
        'organisation_id' => $org->id,
        'name' => 'Own Co',
    ]);
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'name' => 'Instituta',
    ]);
    $principal = Principal::factory()->create([
        'organisation_id' => $org->id,
        'user_id' => $user->id,
        'type' => 'person',
        'name' => $user->name,
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $org->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    return compact('user', 'org', 'company', 'product', 'principal');
}

it('shares the session perimeter and visible scopes on authenticated pages', function (): void {
    $seed = scopeSwitcherSeed();

    $this->actingAs($seed['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('instituta.perimeter')
            ->where('instituta.perimeter.scope_id', $seed['org']->id)
            ->where('instituta.label', 'Qiick Org')
            ->has('instituta.scopes', 3));
});

it('switches the session scope to a child product', function (): void {
    $seed = scopeSwitcherSeed();

    $this->actingAs($seed['user'])
        ->from(route('dashboard'))
        ->post(route('brain.scope'), [
            'scope_type' => 'product',
            'scope_id' => $seed['product']->id,
        ])
        ->assertRedirect(route('dashboard'));

    expect(session(SessionPerimeterService::SESSION_SCOPE_TYPE))->toBe('product')
        ->and(session(SessionPerimeterService::SESSION_SCOPE_ID))->toBe($seed['product']->id);

    $this->actingAs($seed['user'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('instituta.perimeter.scope_type', 'product')
            ->where('instituta.perimeter.scope_id', $seed['product']->id)
            ->where('instituta.label', 'Instituta'));
});

it('rejects a scope outside the principal grants', function (): void {
    $seed = scopeSwitcherSeed();
    $foreign = Organisation::factory()->create();

    $this->actingAs($seed['user'])
        ->from(route('dashboard'))
        ->post(route('brain.scope'), [
            'scope_type' => 'organisation',
            'scope_id' => $foreign->id,
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors('scope_id');
});
