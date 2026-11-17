<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Brain\Enums\RoleEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\User;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

/**
 * The Explorer's reading view of a markdown file. Runs in the `ui-quality` browser job, not in
 * `php artisan test`.
 */
it('shows a heading-only section as its heading, never as raw markdown', function (): void {
    Queue::fake();
    $sourcePath = storage_path('framework/testing/browser-reading-'.uniqid());
    File::ensureDirectoryExists($sourcePath.'/rules');
    File::put($sourcePath.'/rules/coding.md', "---\ntitle: Portal coding rules\ntype: rule\n---\n\n# Portal coding rules\n\n## Stack\n\nLaravel with Livewire.\n");

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

    visit(route('explorer.show', $coding->id, absolute: false))
        ->assertSee('Laravel with Livewire.')
        ->assertDontSee('# Portal coding rules')
        ->assertNoJavaScriptErrors();

    File::deleteDirectory($sourcePath);
});
