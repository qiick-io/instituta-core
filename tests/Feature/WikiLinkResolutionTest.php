<?php

declare(strict_types=1);

use App\Enums\SourceProviderEnum;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Organisation;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;

/**
 * Wiki links name an entry's title more often than its file (#242).
 */
it('resolves links by title, stable key, file name and path, ignoring case, headings and labels', function (): void {
    $brainPath = storage_path('framework/testing/wiki-links-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $brainPath);
    File::put($brainPath.'/rules/linker.md', <<<'MARKDOWN'
        ---
        title: Linker
        area: rules
        ---

        # Linker

        By title [[Coding rules]], lower case [[coding RULES]], with a heading [[Coding rules#Style]],
        with a label [[Coding rules|the rules]], by stable key [[decisions/one-repo]], by file name
        [[one-repo]], by relative path [the decision](../decisions/one-repo.md), and nowhere [[No such entry]].
        MARKDOWN);

    $organisation = Organisation::factory()->create();
    $source = app(SourceRegistryService::class)->add($organisation, 'Links', SourceProviderEnum::Local, localPath: $brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($source->fresh());

    $coding = Item::query()->where('stable_key', 'rules/coding')->sole()->id;
    $decision = Item::query()->where('stable_key', 'decisions/one-repo')->sole()->id;
    $targets = ItemLink::query()
        ->where('from_item_id', Item::query()->where('stable_key', 'rules/linker')->sole()->id)
        ->pluck('to_item_id', 'target')
        ->all();

    expect($targets['Coding rules'])->toBe($coding)
        ->and($targets['coding RULES'])->toBe($coding)
        ->and($targets['decisions/one-repo'])->toBe($decision)
        ->and($targets['one-repo'])->toBe($decision)
        ->and($targets['../decisions/one-repo.md'])->toBe($decision)
        ->and($targets['No such entry'])->toBeNull();

    expect(ItemLink::query()->where('from_item_id', $decision)->where('target', 'Coding rules')->sole()->to_item_id)->toBe($coding);

    File::deleteDirectory($brainPath);
});
