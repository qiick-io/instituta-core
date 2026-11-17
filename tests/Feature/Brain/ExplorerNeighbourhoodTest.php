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
 * @return array{user: User, org: Organisation, item: Item, neighbour: Item, outsider: Item}
 */
function explorerNeighbourhoodSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Neighbourhood Org']);
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
    Item::factory()->create([
        'organisation_id' => $org->id,
        'source_id' => $source->id,
        'title' => 'Brand kit',
        'area' => 'brand',
        'path' => 'brand/kit.md',
        'stable_key' => 'brand/kit',
        'visibility' => VisibilityEnum::Internal,
    ]);

    $outsider = Item::factory()->create([
        'title' => 'Outside secret',
        'area' => 'notes',
        'path' => 'notes/secret.md',
        'stable_key' => 'notes/secret',
    ]);

    ItemLink::factory()->create([
        'from_item_id' => $item->id,
        'to_item_id' => $neighbour->id,
        'organisation_id' => $org->id,
        'target' => $neighbour->stable_key,
        'relation' => 'related',
    ]);
    ItemLink::factory()->create([
        'from_item_id' => $outsider->id,
        'to_item_id' => $item->id,
        'organisation_id' => $org->id,
        'target' => $item->stable_key,
        'relation' => 'related',
    ]);

    return compact('user', 'org', 'item', 'neighbour', 'outsider');
}

it('carries linked neighbours both ways and excludes items outside the perimeter', function (): void {
    $seed = explorerNeighbourhoodSeed();
    // The reverse link must not duplicate the neighbour.
    ItemLink::factory()->create([
        'from_item_id' => $seed['neighbour']->id,
        'to_item_id' => $seed['item']->id,
        'organisation_id' => $seed['org']->id,
        'target' => $seed['item']->stable_key,
        'relation' => 'related',
    ]);

    $this->actingAs($seed['user'])
        ->get(route('explorer.show', ['item' => $seed['item']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/explorer')
            ->has('neighbourhood.neighbours', 1)
            ->where('neighbourhood.neighbours.0.id', (string) $seed['neighbour']->id)
            ->where('neighbourhood.neighbours.0.stable_key', $seed['neighbour']->stable_key)
            ->where('neighbourhood.neighbours.0.title', 'Neighbour note')
            ->where('neighbourhood.areas', ['brand', 'notes', 'rules'])
            ->where('neighbourhood.neighbours', function ($neighbours) use ($seed): bool {
                $ids = collect($neighbours)->pluck('id')->all();

                return ! in_array((string) $seed['outsider']->id, $ids, true)
                    && ! in_array((string) $seed['item']->id, $ids, true);
            }));
});

it('includes a neighbour linked only from the item', function (): void {
    $seed = explorerNeighbourhoodSeed();

    $this->actingAs($seed['user'])
        ->get(route('explorer.show', ['item' => $seed['item']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('neighbourhood.neighbours', 1)
            ->where('neighbourhood.neighbours.0.stable_key', 'notes/neighbour'));
});

it('includes a neighbour linked only toward the item', function (): void {
    $seed = explorerNeighbourhoodSeed();
    ItemLink::query()->delete();
    ItemLink::factory()->create([
        'from_item_id' => $seed['neighbour']->id,
        'to_item_id' => $seed['item']->id,
        'organisation_id' => $seed['org']->id,
        'target' => $seed['item']->stable_key,
        'relation' => 'related',
    ]);

    $this->actingAs($seed['user'])
        ->get(route('explorer.show', ['item' => $seed['item']->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('neighbourhood.neighbours', 1)
            ->where('neighbourhood.neighbours.0.stable_key', 'notes/neighbour'));
});

it('passes null neighbourhood on the explorer index', function (): void {
    $seed = explorerNeighbourhoodSeed();

    $this->actingAs($seed['user'])
        ->get(route('explorer.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brain/explorer')
            ->where('neighbourhood', null));
});
