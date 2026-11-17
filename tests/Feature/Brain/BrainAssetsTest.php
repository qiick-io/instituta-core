<?php

declare(strict_types=1);

use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceProviderEnum;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\User;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Images and PDFs of a source are files of the brain (#311): synced as assets, served to people
 * inside the perimeter only, shown in the documents that embed them.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->withoutVite();

    $this->brainPath = storage_path('framework/testing/assets-'.uniqid());
    File::makeDirectory($this->brainPath.'/brand', 0755, true);
    File::makeDirectory($this->brainPath.'/notes', 0755, true);

    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);
    File::put($this->brainPath.'/brand/logo.png', (string) ob_get_clean());
    File::put($this->brainPath.'/brand/mark.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script></svg>');
    File::put($this->brainPath.'/notes/kit.zip', "PK\x03\x04fake");
    $docx = new ZipArchive;
    $docx->open($this->brainPath.'/notes/spec.docx', ZipArchive::CREATE);
    $docx->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Ship on Fridays.</w:t></w:r></w:p></w:body></w:document>');
    $docx->close();
    File::put($this->brainPath.'/notes/brand.md', "# Brand\n\nOur logo:\n\n![The logo](../brand/logo.png)\n");

    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create();
    $principal = Principal::factory()->create([
        'organisation_id' => $this->organisation->id,
        'user_id' => $this->user->id,
        'type' => 'person',
        'name' => $this->user->name,
    ]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $this->organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);

    $source = app(SourceRegistryService::class)->add($this->organisation, 'Knowledge', SourceProviderEnum::Local, localPath: $this->brainPath, ref: null, isShared: true);
    app(SourceSyncService::class)->sync($source->fresh());

    $this->logo = Item::query()->where('path', 'brand/logo.png')->firstOrFail();
    $this->mark = Item::query()->where('path', 'brand/mark.svg')->firstOrFail();
});

afterEach(function (): void {
    File::deleteDirectory($this->brainPath);
});

it('syncs images as asset items and keeps a copy of their bytes', function (): void {
    $sha256 = $this->logo->frontmatter['asset']['sha256'];

    expect($this->logo->type)->toBe('image')
        ->and($this->logo->area)->toBe('style')
        ->and(Storage::disk('local')->exists('brain-assets/'.substr($sha256, 0, 2).'/'.$sha256))->toBeTrue();
});

it('serves an asset to a person inside the perimeter, with its own type and no sniffing', function (): void {
    $this->actingAs($this->user)
        ->get(route('brain.assets.show', $this->logo->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($this->user)
        ->get(route('brain.assets.show', ['item' => $this->logo->id, 'download' => 1]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=logo.png');
});

it('serves an SVG under a sandboxing CSP, so its scripts never run', function (): void {
    $response = $this->actingAs($this->user)->get(route('brain.assets.show', $this->mark->id))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')->toContain("default-src 'none'");
});

it('does not serve an asset outside the perimeter', function (): void {
    $stranger = User::factory()->create();
    $otherOrganisation = Organisation::factory()->create();
    $principal = Principal::factory()->create(['organisation_id' => $otherOrganisation->id, 'user_id' => $stranger->id, 'type' => 'person', 'name' => $stranger->name]);
    Grant::factory()->create(['principal_id' => $principal->id, 'scope_type' => ScopeTypeEnum::Organisation, 'scope_id' => $otherOrganisation->id, 'role' => RoleEnum::Owner, 'max_visibility' => VisibilityEnum::Restricted]);

    $this->actingAs($stranger)->get(route('brain.assets.show', $this->logo->id))->assertNotFound();
});

it('shows embedded pictures through the asset route, and the asset knows where it is used', function (): void {
    $assetUrl = route('brain.assets.show', $this->logo->id, absolute: false);

    $this->actingAs($this->user)
        ->get(route('explorer.show', 'notes/brand'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('item.sections.0.html', fn (string $html): bool => str_contains($html, 'src="'.$assetUrl.'"')));

    $this->actingAs($this->user)
        ->get(route('explorer.show', 'brand/logo.png'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('item.asset.mime', 'image/png')
            ->where('item.asset.width', 4)
            ->where('item.asset.url', $assetUrl)
            ->where('item.asset.used_in.0.stable_key', 'notes/brand'));
});

it('always sends download-only files as attachments', function (): void {
    $kit = Item::query()->where('path', 'notes/kit.zip')->firstOrFail();

    expect($kit->type)->toBe('file');

    $this->actingAs($this->user)
        ->get(route('brain.assets.show', $kit->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertHeader('Content-Disposition', 'attachment; filename=kit.zip');
});

it('stores the text of an Office file as a section the Explorer shows', function (): void {
    $spec = Item::query()->where('path', 'notes/spec.docx')->firstOrFail();

    expect($spec->sections()->value('body'))->toBe('Ship on Fridays.');

    $this->actingAs($this->user)
        ->get(route('explorer.show', 'notes/spec.docx'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('item.asset.kind', 'text')
            ->where('item.sections.0.body', 'Ship on Fridays.'));
});
