<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Source;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, org: Organisation, item: Item, neighbour: Item}
 */
function graphUiSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Graph Org']);
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

    $source = Source::factory()->create([
        'organisation_id' => $org->id,
    ]);

    $item = Item::factory()->create([
        'organisation_id' => $org->id,
        'source_id' => $source->id,
        'title' => 'Root rule',
        'area' => 'rules',
        'path' => 'rules/root.md',
        'stable_key' => 'rules/root',
        'visibility' => VisibilityEnum::Internal,
    ]);
    $neighbour = Item::factory()->create([
        'organisation_id' => $org->id,
        'source_id' => $source->id,
        'title' => 'Neighbour note',
        'area' => 'notes',
        'path' => 'notes/neighbour.md',
        'stable_key' => 'notes/neighbour',
        'visibility' => VisibilityEnum::Internal,
    ]);
    ItemLink::factory()->create([
        'from_item_id' => $item->id,
        'to_item_id' => $neighbour->id,
        'organisation_id' => $org->id,
        'target' => $neighbour->stable_key,
        'relation' => 'related',
    ]);

    return compact('user', 'org', 'item', 'neighbour');
}

it('renders every item of the perimeter and each link once, with link counts', function (): void {
    $seed = graphUiSeed();
    // The same pair linked back is one edge, not two.
    ItemLink::factory()->create([
        'from_item_id' => $seed['neighbour']->id,
        'to_item_id' => $seed['item']->id,
        'organisation_id' => $seed['org']->id,
        'target' => $seed['item']->stable_key,
        'relation' => 'related',
    ]);

    $this->actingAs($seed['user'])
        ->get(route('graph', ['focus' => $seed['item']->stable_key]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/graph')
            ->where('hasPerimeter', true)
            ->where('focus', $seed['item']->stable_key)
            ->has('nodes', 2)
            ->has('edges', 1)
            ->where('nodes', fn ($nodes): bool => collect($nodes)->every(fn (array $node): bool => $node['degree'] === 1)));
});

it('requires authentication for the graph', function (): void {
    $this->get(route('graph'))->assertRedirect();
});
