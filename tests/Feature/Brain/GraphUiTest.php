<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\GapReportKindEnum;
use App\Enums\GapReportStatusEnum;
use App\Enums\ItemChangeKindEnum;
use App\Enums\ProposalStatusEnum;
use App\Models\GapReport;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemChange;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
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

it('previews an item for the graph card without leaving the page', function (): void {
    $seed = graphUiSeed();
    $seed['item']->update(['summary' => 'The root of the rules']);

    foreach (range(1, 5) as $position) {
        ItemSection::factory()->create([
            'item_id' => $seed['item']->id,
            'organisation_id' => $seed['org']->id,
            'anchor' => "part-{$position}",
            'heading' => "Part {$position}",
            'level' => 2,
            'position' => $position,
            'body' => "Body {$position}",
            'markdown' => "Body **{$position}**",
        ]);
    }

    $this->actingAs($seed['user'])
        ->getJson(route('graph.preview', ['item' => $seed['item']->stable_key]))
        ->assertOk()
        ->assertJsonPath('summary', 'The root of the rules')
        ->assertJsonCount(4, 'sections')
        ->assertJsonPath('sections.0.heading', 'Part 1')
        ->assertJsonPath('hasMore', true);
});

it('does not preview an item outside the perimeter', function (): void {
    $seed = graphUiSeed();
    $other = Item::factory()->create(['stable_key' => 'rules/elsewhere']);

    $this->actingAs($seed['user'])
        ->getJson(route('graph.preview', ['item' => $other->stable_key]))
        ->assertNotFound();
});

it('reads the graph view from the query string', function (): void {
    $seed = graphUiSeed();

    $this->actingAs($seed['user'])
        ->get(route('graph', [
            'focus' => $seed['item']->stable_key,
            'to' => $seed['neighbour']->stable_key,
            'area' => 'rules, notes,,rules',
            'layout' => 'constellations',
            'review' => 'gaps,bogus,proposals',
            'colour' => 'freshness',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view.review', ['gaps', 'proposals'])
            ->where('view.colour', 'freshness')
            ->where('view.areas', ['rules', 'notes'])
            ->where('view.layout', 'constellations')
            ->where('view.to', $seed['neighbour']->stable_key));
});

it('falls back to the free layout for an unknown one', function (): void {
    $seed = graphUiSeed();

    $this->actingAs($seed['user'])
        ->get(route('graph', ['layout' => 'spiral']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view.areas', [])
            ->where('view.layout', 'free')
            ->where('view.review', [])
            ->where('view.colour', 'area')
            ->where('view.to', null));
});

it('carries review signals and the last change on each node', function (): void {
    $seed = graphUiSeed();
    $principal = Principal::query()->where('user_id', $seed['user']->id)->firstOrFail();

    Proposal::factory()->create([
        'organisation_id' => $seed['org']->id,
        'proposed_by_principal_id' => $principal->id,
        'stable_key' => $seed['item']->stable_key,
    ]);
    Proposal::factory()->create([
        'organisation_id' => $seed['org']->id,
        'proposed_by_principal_id' => $principal->id,
        'stable_key' => $seed['item']->stable_key,
        'status' => ProposalStatusEnum::Rejected,
    ]);
    GapReport::query()->create([
        'organisation_id' => $seed['org']->id,
        'scope_type' => 'organisation',
        'scope_id' => $seed['org']->id,
        'kind' => GapReportKindEnum::Missing,
        'status' => GapReportStatusEnum::Open,
        'summary' => 'Neighbour lacks an example',
        'reported_by_principal_id' => $principal->id,
        'related' => [[
            'item' => $seed['neighbour']->id,
            'stable_key' => $seed['neighbour']->stable_key,
            'path' => $seed['neighbour']->path,
            'title' => $seed['neighbour']->title,
            'anchor' => null,
        ]],
    ]);
    ItemChange::query()->create([
        'organisation_id' => $seed['org']->id,
        'source_id' => $seed['item']->source_id,
        'item_id' => $seed['item']->id,
        'kind' => ItemChangeKindEnum::Updated,
        'stable_key' => $seed['item']->stable_key,
        'path' => $seed['item']->path,
        'title' => $seed['item']->title,
        'area' => $seed['item']->area,
        'visibility' => $seed['item']->visibility,
        'status' => $seed['item']->fresh()?->status,
        'changed_at' => '2026-11-10 09:30:00',
    ]);

    $this->actingAs($seed['user'])
        ->get(route('graph'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('nodes', function ($nodes) use ($seed): bool {
                $byKey = collect($nodes)->keyBy('stable_key');
                $item = $byKey[$seed['item']->stable_key];
                $neighbour = $byKey[$seed['neighbour']->stable_key];

                return $item['open_proposals'] === 1
                    && $item['open_gaps'] === 0
                    && str_starts_with((string) $item['changed_at'], '2026-11-10T09:30:00')
                    && $neighbour['open_proposals'] === 0
                    && $neighbour['open_gaps'] === 1
                    && $neighbour['changed_at'] !== null;
            }));
});
