<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum;
use App\Enums\ProposalActionEnum;
use App\Enums\ProposalStatusEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\User;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A person proposes and reviews brain changes from the web UI (#200).
 */
beforeEach(function (): void {
    Queue::fake();
    $this->withoutVite();

    $this->sourcePath = storage_path('framework/testing/ui-propose-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $this->sourcePath);

    $this->organisation = Organisation::factory()->create();
    $source = app(SourceRegistryService::class)->add(
        organisation: $this->organisation,
        name: 'UI fixture',
        provider: SourceProviderEnum::Local,
        localPath: $this->sourcePath,
        ref: null,
        path: null,
        isShared: true,
    );
    app(SourceSyncService::class)->sync($source->fresh());

    $this->coding = Item::query()->where('path', 'rules/coding.md')->sole();
    $this->style = $this->coding->sections()->where('anchor', 'coding-rules/style')->sole();
});

afterEach(function (): void {
    File::deleteDirectory($this->sourcePath);
});

function uiPerson(Organisation $organisation, RoleEnum $role): User
{
    $user = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($user, $organisation, $role);

    return $user;
}

it('gives the reading view what it needs to propose a change', function (): void {
    $this->actingAs(uiPerson($this->organisation, RoleEnum::Member))
        ->get(route('explorer.show', $this->coding->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canPropose', true)
            ->where('item.content_hash', $this->coding->content_hash)
            ->where('item.sections.1.anchor', 'coding-rules/style')
            ->where('item.sections.1.markdown', $this->style->markdown)
            ->where('item.sections.1.section_hash', ItemSection::hashOf($this->style->markdown)));
});

it('renders each section from its markdown, with resolved links to the reading view', function (): void {
    $decision = Item::query()->where('path', 'decisions/one-repo.md')->sole();

    $this->actingAs(uiPerson($this->organisation, RoleEnum::Member))
        ->get(route('explorer.show', $this->coding->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('item.sections.1.html', '<p>Prefer boring code over clever code. See <a href="'.route('explorer.show', $decision->id, absolute: false).'">decisions</a>.</p>'));
});

it('lets a member propose a one-section change and opens its page with a diff', function (): void {
    $member = uiPerson($this->organisation, RoleEnum::Member);
    $newStyle = "## Style\n\nTabs, never spaces.";

    $this->actingAs($member)
        ->post(route('review.proposals.store'), [
            'action' => 'update',
            'title' => 'Tabs please',
            'stable_key' => $this->coding->stable_key,
            'path' => 'rules/coding.md',
            'section_anchor' => 'coding-rules/style',
            'base_section_hash' => ItemSection::hashOf($this->style->markdown),
            'body' => $newStyle,
        ])
        ->assertRedirect();

    $proposal = Proposal::query()->sole();

    expect($proposal->api_key_id)->toBeNull()
        ->and($proposal->proposed_by_principal_id)->toBe($member->principals()->sole()->id)
        ->and($proposal->section_anchor)->toBe('coding-rules/style');

    $this->actingAs($member)
        ->get(route('review.proposals.show', $proposal->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('proposal.proposed_by.name', $member->name)
            ->where('proposal.proposed_by.type', 'person')
            ->where('proposal.section_anchor', 'coding-rules/style')
            ->where('diff.lines', fn ($lines): bool => collect($lines)->contains(fn (array $line): bool => $line['type'] === 'added' && $line['text'] === 'Tabs, never spaces.')
                && collect($lines)->contains(fn (array $line): bool => $line['type'] === 'removed')
                && collect($lines)->contains(fn (array $line): bool => $line['type'] === 'same' && $line['text'] === '## Style')));
});

it('proposes a new item from the tree', function (): void {
    $this->actingAs(uiPerson($this->organisation, RoleEnum::Owner))
        ->post(route('review.proposals.store'), [
            'action' => 'create',
            'title' => 'Naming rules',
            'stable_key' => 'rules/naming',
            'path' => 'rules/naming.md',
            'area' => 'rules',
            'body' => "# Naming\n\nFull words.",
        ])
        ->assertRedirect();

    $proposal = Proposal::query()->sole();

    expect($proposal->action)->toBe(ProposalActionEnum::Create);

    $this->get(route('review.proposals.show', $proposal->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('diff.current', null)
            ->where('diff.lines', [['type' => 'added', 'text' => '# Naming'], ['type' => 'added', 'text' => ''], ['type' => 'added', 'text' => 'Full words.']]));
});

it('returns a stale or refused proposal to the form as an error', function (): void {
    $this->actingAs(uiPerson($this->organisation, RoleEnum::Member))
        ->from(route('explorer.show', $this->coding->id))
        ->post(route('review.proposals.store'), [
            'action' => 'update',
            'title' => 'Stale',
            'stable_key' => $this->coding->stable_key,
            'path' => 'rules/coding.md',
            'base_content_hash' => str_repeat('a', 64),
            'body' => '# Coding rules',
        ])
        ->assertRedirect(route('explorer.show', $this->coding->id))
        ->assertSessionHasErrors(['body' => 'The content changed since you read it (base_content_hash is stale); re-read and propose again.']);

    expect(Proposal::query()->count())->toBe(0);
});

it('refuses guests', function (): void {
    $guest = uiPerson($this->organisation, RoleEnum::Guest);

    $this->actingAs($guest)
        ->get(route('explorer.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canPropose', false));

    $this->actingAs($guest)
        ->post(route('review.proposals.store'), [
            'action' => 'create',
            'title' => 'Guest idea',
            'stable_key' => 'rules/guest',
            'path' => 'rules/guest.md',
            'body' => '# Guest',
        ])
        ->assertSessionHasErrors(['body' => 'Guests cannot propose changes.']);
});

it('stores the decision note with the decision', function (): void {
    $owner = uiPerson($this->organisation, RoleEnum::Owner);
    $proposal = Proposal::factory()->create([
        'organisation_id' => $this->organisation->id,
        'scope_id' => $this->organisation->id,
        'proposed_by_principal_id' => $owner->principals()->sole()->id,
    ]);

    $this->actingAs($owner)
        ->patch(route('review.proposals.update', $proposal->id), ['status' => 'rejected', 'note' => 'Duplicate of the naming rule.'])
        ->assertRedirect(route('review.proposals.show', $proposal->id));

    expect($proposal->fresh()->status)->toBe(ProposalStatusEnum::Rejected)
        ->and($proposal->fresh()->decision_note)->toBe('Duplicate of the naming rule.');
});
