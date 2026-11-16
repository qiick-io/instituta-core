<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum;
use App\Enums\ProposalStatusEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\User;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

/**
 * Propose a one-section change from the reading view, review the diff, accept it (#200).
 * Runs in the `ui-quality` browser job, not in `php artisan test`.
 */
it('proposes a section change, shows the diff and accepts it, in light and dark', function (string $mode): void {
    Queue::fake();
    $sourcePath = storage_path('framework/testing/browser-propose-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $sourcePath);

    $organisation = Organisation::factory()->create();
    $source = app(SourceRegistryService::class)->add(
        organisation: $organisation,
        name: 'Browser fixture',
        provider: SourceProviderEnum::Local,
        localPath: $sourcePath,
        ref: null,
        path: null,
        isShared: true,
    );
    app(SourceSyncService::class)->sync($source->fresh());

    $owner = User::factory()->create();
    app(GrantOrganisationAccessAction::class)->execute($owner, $organisation, RoleEnum::Owner);
    $this->actingAs($owner);

    $coding = Item::query()->where('path', 'rules/coding.md')->sole();
    $start = visit(route('brain.reading', $coding->id, absolute: false));
    $page = $mode === 'dark' ? $start->inDarkMode() : $start->inLightMode();

    $page->click('[aria-label="Propose a change to Style"]')
        ->assertSee('Propose a change')
        ->fill('#proposal_body', "## Style\n\nTabs, never spaces.")
        ->press('Open proposal')
        ->assertSee('Changes')
        ->assertSee('Tabs, never spaces.')
        ->assertNoAccessibilityIssues(1)
        ->fill('#decision_note', 'Matches the editor config.')
        ->press('Accept')
        ->assertSee('Matches the editor config.')
        ->assertNoJavaScriptErrors();

    $proposal = Proposal::query()->sole();

    expect($proposal->status)->toBe(ProposalStatusEnum::Accepted)
        ->and($proposal->section_anchor)->toBe('coding-rules/style');

    File::deleteDirectory($sourcePath);
})->with(['light', 'dark']);
