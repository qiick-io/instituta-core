<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use App\Models\Source;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * An organisation with one synced source and `$itemsCount` internal rules items (one section each).
 *
 * @return array{organisation: Organisation, source: Source}
 */
function dashboardOrganisation(string $name, int $itemsCount): array
{
    $organisation = Organisation::factory()->create(['name' => $name]);
    $company = Company::factory()->create(['organisation_id' => $organisation->id]);
    $source = Source::factory()->create([
        'organisation_id' => $organisation->id,
        'company_id' => $company->id,
        'name' => "{$name} notes",
    ]);

    foreach (range(1, $itemsCount) as $position) {
        $item = Item::factory()->create([
            'organisation_id' => $organisation->id,
            'company_id' => $company->id,
            'source_id' => $source->id,
            'area' => 'rules',
            'path' => "rules/{$position}.md",
            'stable_key' => "rules/{$position}",
            'visibility' => VisibilityEnum::Internal,
        ]);
        ItemSection::factory()->create(['item_id' => $item->id]);
    }

    return ['organisation' => $organisation, 'source' => $source];
}

/**
 * A user with a perimeter in the organisation.
 */
function dashboardUser(Organisation $organisation, RoleEnum $role): User
{
    $user = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($user, $organisation, $role);

    return $user;
}

it('redirects guests to the login page', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('shows the empty state to a user without a perimeter', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('hasPerimeter', false)
            ->missing('contentStats'));
});

it('shows an owner the content, sources, proposals, keys and activity of their organisation only', function (): void {
    ['organisation' => $acme, 'source' => $acmeSource] = dashboardOrganisation('Acme', 3);
    dashboardOrganisation('Globex', 5);
    $owner = dashboardUser($acme, RoleEnum::Owner);
    $ownerPrincipal = $owner->principals()->sole();

    Proposal::factory()->create(['proposed_by_principal_id' => $ownerPrincipal->id, 'title' => 'Add a naming rule']);
    Proposal::factory()->accepted()->create(['proposed_by_principal_id' => $ownerPrincipal->id]);
    ApiKey::factory()->create(['principal_id' => $ownerPrincipal->id]);
    ApiKey::factory()->create(['principal_id' => $ownerPrincipal->id, 'expires_at' => now()->addDays(10)]);
    ApiKey::factory()->revoked()->create(['principal_id' => $ownerPrincipal->id]);
    activity('keys')
        ->causedBy($owner)
        ->event('api_key.created')
        ->withProperties(['organisation_id' => $acme->id])
        ->log('acme-key-created');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('hasPerimeter', true)
            ->where('contentStats.items_count', 3)
            ->where('contentStats.sections_count', 3)
            ->where('contentStats.areas_covered', 1)
            ->where('areaCounts', [['area' => 'rules', 'count' => 3]])
            ->has('sources', 1)
            ->where('sources.0.id', $acmeSource->id)
            ->where('sources.0.items_count', 3)
            ->where('proposals.open_count', 1)
            ->where('proposals.recent.0.title', 'Add a naming rule')
            ->where('apiKeys', ['active_count' => 2, 'expiring_soon_count' => 1])
            ->where('latestActivity.0.description', 'acme-key-created')
            ->where('latestActivity', fn ($events): bool => collect($events)->every(
                fn (array $event): bool => $event['organisation_id'] === $acme->id,
            )));
});

it('hides keys and activity from members, like their own pages', function (): void {
    ['organisation' => $acme] = dashboardOrganisation('Acme', 2);
    $member = dashboardUser($acme, RoleEnum::Member);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contentStats.items_count', 2)
            ->where('apiKeys', null)
            ->where('latestActivity', null));
});

it('counts only the items a guest grant can read', function (): void {
    ['organisation' => $acme] = dashboardOrganisation('Acme', 2);
    $guest = dashboardUser($acme, RoleEnum::Guest);

    $this->actingAs($guest)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contentStats.items_count', 0)
            ->where('areaCounts', []));
});

it('ignores a principal of another user', function (): void {
    ['organisation' => $acme] = dashboardOrganisation('Acme', 2);
    Principal::factory()->create(['organisation_id' => $acme->id, 'type' => 'person']);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('hasPerimeter', false));
});
