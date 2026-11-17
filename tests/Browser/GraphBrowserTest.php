<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Source;
use App\Models\User;

/**
 * Owner with a perimeter, three linked items (A→B→C), and a section on A for the side-card preview.
 *
 * @return array{user: User, org: Organisation, item: Item, neighbour: Item, far: Item}
 */
function graphBrowserSeed(): array
{
    $user = User::factory()->create();
    $org = Organisation::factory()->create(['name' => 'Graph Browser Org']);
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
        'title' => 'Alpha root',
        'area' => 'rules',
        'path' => 'rules/alpha.md',
        'stable_key' => 'rules/alpha',
        'visibility' => VisibilityEnum::Internal,
    ]);
    $neighbour = Item::factory()->create([
        'organisation_id' => $org->id,
        'source_id' => $source->id,
        'title' => 'Beta middle',
        'area' => 'notes',
        'path' => 'notes/beta.md',
        'stable_key' => 'notes/beta',
        'visibility' => VisibilityEnum::Internal,
    ]);
    $far = Item::factory()->create([
        'organisation_id' => $org->id,
        'source_id' => $source->id,
        'title' => 'Gamma end',
        'area' => 'notes',
        'path' => 'notes/gamma.md',
        'stable_key' => 'notes/gamma',
        'visibility' => VisibilityEnum::Internal,
    ]);

    ItemLink::factory()->create([
        'from_item_id' => $item->id,
        'to_item_id' => $neighbour->id,
        'organisation_id' => $org->id,
        'target' => $neighbour->stable_key,
        'relation' => 'related',
    ]);
    ItemLink::factory()->create([
        'from_item_id' => $neighbour->id,
        'to_item_id' => $far->id,
        'organisation_id' => $org->id,
        'target' => $far->stable_key,
        'relation' => 'related',
    ]);

    ItemSection::factory()->create([
        'item_id' => $item->id,
        'organisation_id' => $org->id,
        'anchor' => 'overview',
        'heading' => 'Overview',
        'level' => 2,
        'position' => 1,
        'body' => 'The root rule says keep paths short.',
        'markdown' => 'The root rule says keep paths short.',
    ]);

    return compact('user', 'org', 'item', 'neighbour', 'far');
}

/**
 * The graph page paints in light and dark: Area filter, Free/Constellations, canvas, legend (#281).
 * Runs in the `ui-quality` browser job, not in `php artisan test`.
 */
it('renders the graph in light and dark', function (string $mode): void {
    $seed = graphBrowserSeed();

    $this->actingAs($seed['user']);
    $start = visit(route('graph', absolute: false));
    $page = $mode === 'dark' ? $start->inDarkMode() : $start->inLightMode();

    $page->assertSee('Area')
        ->assertSee('Free')
        ->assertSee('Constellations')
        ->assertPresent('canvas[role="img"]')
        ->assertSee('Rules')
        ->assertSee('Notes')
        ->assertNoAccessibilityIssues(1)
        ->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

/**
 * `?focus=` opens the side card with the item title, preview section text and a linked neighbour.
 */
it('shows the focused item card with preview and linked items', function (): void {
    $seed = graphBrowserSeed();

    $this->actingAs($seed['user']);
    $page = visit(route('graph', [
        'focus' => $seed['item']->stable_key,
    ], absolute: false))->inLightMode();

    $page->assertSee('Alpha root')
        ->assertSee('Preview')
        ->assertSee('The root rule says keep paths short.')
        ->assertSee('Linked items')
        ->assertSee('Beta middle')
        ->assertNoJavaScriptErrors();
});

/**
 * `?focus=` + `?to=` on a three-item chain shows the Path card with two steps and titles in order.
 */
it('shows the path card for a three-item chain', function (): void {
    $seed = graphBrowserSeed();

    $this->actingAs($seed['user']);
    $page = visit(route('graph', [
        'focus' => $seed['item']->stable_key,
        'to' => $seed['far']->stable_key,
    ], absolute: false))->inLightMode();

    $page->assertSee('Path')
        ->assertSee('2 steps')
        ->assertSeeIn('ol li:nth-child(1)', 'Alpha root')
        ->assertSeeIn('ol li:nth-child(2)', 'Beta middle')
        ->assertSeeIn('ol li:nth-child(3)', 'Gamma end')
        ->assertNoJavaScriptErrors();
});

/**
 * `?area=` and `?layout=constellations` keep Constellations pressed on the layout control.
 */
it('opens with an area filter and constellations layout pressed', function (): void {
    $seed = graphBrowserSeed();

    $this->actingAs($seed['user']);
    $page = visit(route('graph', [
        'area' => 'rules',
        'layout' => 'constellations',
    ], absolute: false))->inLightMode();

    $page->assertSee('Area')
        ->assertSee('Free')
        ->assertSee('Constellations')
        ->assertAttribute('[title="Group the items by area"]', 'data-state', 'on')
        ->assertNoJavaScriptErrors();
});
