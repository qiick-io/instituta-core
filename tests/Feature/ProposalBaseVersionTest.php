<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\MaterialiseProposalAction;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Brain\Support\FrontmatterParser;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\ProposalMaterialisationStatusEnum;
use App\Enums\ProposalStatusEnum;
use App\Enums\SourceProviderEnum;
use App\Mcp\Servers\HttpBrainServer;
use App\Mcp\Tools\Http\InstitutaProposeTool;
use App\Models\Grant;
use App\Models\Item;
use App\Models\Organisation;
use App\Models\Principal;
use App\Models\Proposal;
use App\Services\PerimeterResolverService;
use App\Services\SourceRegistryService;
use App\Services\SourceSyncService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Proposals carry the version their author read, and one-section edits never touch the rest
 * of the file (#198).
 */
const BRAND_FILE = <<<'MARKDOWN'
---
title: Brand
area: rules
visibility: internal
---

# Brand

The brand of the fixture company.

## Colours

Primary: `#0f766e` (teal), used for **actions**.

## Typography

Inter for the interface, [see the rules](coding.md).
MARKDOWN;

beforeEach(function (): void {
    Queue::fake();
    config(['instituta.api.write_enabled' => true, 'instituta.mcp.write_enabled' => true, 'instituta.mcp.enabled' => true]);

    $this->sourcePath = storage_path('framework/testing/brain-'.uniqid());
    File::copyDirectory(base_path('tests/Unit/Brain/Fixtures/native'), $this->sourcePath);
    File::put($this->sourcePath.'/rules/brand.md', BRAND_FILE."\n");

    $this->organisation = Organisation::factory()->create();
    $this->source = app(SourceRegistryService::class)->add(
        organisation: $this->organisation,
        name: 'Brand fixture',
        provider: SourceProviderEnum::Local,
        localPath: $this->sourcePath,
        ref: null,
        path: null,
        isShared: true,
    );
    app(SourceSyncService::class)->sync($this->source->fresh());

    $principal = Principal::factory()->create(['organisation_id' => $this->organisation->id]);
    Grant::factory()->create([
        'principal_id' => $principal->id,
        'scope_type' => ScopeTypeEnum::Organisation,
        'scope_id' => $this->organisation->id,
        'role' => RoleEnum::Owner,
        'max_visibility' => VisibilityEnum::Restricted,
    ]);
    $this->key = app(CreateApiKeyAction::class)->execute(
        principal: $principal,
        name: 'writer',
        scopeType: ScopeTypeEnum::Organisation,
        scopeId: $this->organisation->id,
        role: RoleEnum::Owner,
        maxVisibility: VisibilityEnum::Restricted,
        scopes: [ApiKeyScopeEnum::BrainRead->value, ApiKeyScopeEnum::BrainWrite->value],
    );

    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'rev-parse')
            ? Process::result(output: "0123456789abcdef0123456789abcdef01234567\n")
            : Process::result();
    });
});

afterEach(function (): void {
    File::deleteDirectory($this->sourcePath);
});

function brandItem(): Item
{
    return Item::query()->where('stable_key', 'rules/brand')->sole();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function proposeBrand(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/proposals', [
        'action' => 'update',
        'stable_key' => 'rules/brand',
        'title' => 'Change the brand',
        'path' => 'rules/brand.md',
        ...$overrides,
    ], ['Authorization' => 'Bearer '.test()->key['plain']]);
}

/**
 * Accepts and materialises a proposal, returning it and the file it wrote in the review workspace.
 *
 * @return array{proposal: Proposal, written: string|null}
 */
function materialiseProposal(string $proposalId): array
{
    $proposal = Proposal::query()->findOrFail($proposalId);
    $proposal->forceFill(['status' => ProposalStatusEnum::Accepted, 'materialisation_status' => ProposalMaterialisationStatusEnum::Pending])->save();
    $updated = app(MaterialiseProposalAction::class)->execute($proposal->fresh());
    $writtenPath = storage_path('app/instituta/proposals/'.$proposalId.'/rules/brand.md');

    return ['proposal' => $updated, 'written' => is_file($writtenPath) ? File::get($writtenPath) : null];
}

it('returns the content hash and each section markdown and hash when reading an item', function (): void {
    $item = brandItem();

    $this->getJson('/api/v1/items/'.$item->id, ['Authorization' => 'Bearer '.$this->key['plain']])
        ->assertOk()
        ->assertJsonPath('origin.content_hash', $item->content_hash)
        ->assertJsonPath('sections.1.anchor', 'brand/colours')
        ->assertJsonPath('sections.1.markdown', "## Colours\n\nPrimary: `#0f766e` (teal), used for **actions**.")
        ->assertJsonPath('sections.1.section_hash', hash('sha256', "## Colours\n\nPrimary: `#0f766e` (teal), used for **actions**."));
});

it('refuses updates without a base, with a stale base, on another path or as a duplicate create', function (array $payload, string $message, string $code): void {
    proposeBrand($payload)->assertUnprocessable()->assertJsonPath('message', $message)->assertJsonPath('error', $code);

    expect(Proposal::query()->count())->toBe(0);
})->with([
    'no base' => [[], 'base_content_hash is required: send the version you read.', 'base_required'],
    'stale base' => [['base_content_hash' => str_repeat('a', 64)], 'The content changed since you read it (base_content_hash is stale); re-read and propose again.', 'stale_base'],
    'other path' => [['path' => 'rules/other.md', 'base_content_hash' => str_repeat('a', 64)], 'path must be the item\'s current path (rules/brand.md).', 'path_mismatch'],
    'duplicate create' => [['action' => 'create'], 'An item with this stable_key already exists; propose an update instead.', 'item_exists'],
    'section on delete' => [['action' => 'delete', 'section_anchor' => 'brand/colours'], 'section_anchor is only allowed on update.', 'invalid_change'],
]);

it('refuses a stale base over MCP too', function (): void {
    $key = $this->key['key']->fresh('principal');
    request()->attributes->set('instituta.api_key', $key);
    request()->attributes->set('instituta.perimeter', app(PerimeterResolverService::class)->fromApiKey($key));

    HttpBrainServer::tool(InstitutaProposeTool::class, [
        'action' => 'update',
        'stable_key' => 'rules/brand',
        'title' => 'Change the brand',
        'path' => 'rules/brand.md',
        'body' => '# Brand',
        'base_content_hash' => str_repeat('b', 64),
    ])->assertHasErrors(['[stale_base] The content changed since you read it (base_content_hash is stale); re-read and propose again.']);
});

it('edits one section and leaves the frontmatter and every other section untouched', function (): void {
    $colours = brandItem()->sections()->where('anchor', 'brand/colours')->sole();

    $proposalId = proposeBrand([
        'section_anchor' => 'brand/colours',
        'base_section_hash' => hash('sha256', (string) $colours->markdown),
        'body' => "## Colours\n\nPrimary: `#2563eb` (blue), used for **actions**.",
    ])->assertCreated()->assertJsonPath('section_anchor', 'brand/colours')->json('id');

    ['proposal' => $proposal, 'written' => $written] = materialiseProposal($proposalId);

    expect($proposal->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::LocalReview)
        ->and($written)->toBe(str_replace('`#0f766e` (teal)', '`#2563eb` (blue)', BRAND_FILE)."\n");
});

it('still applies a section edit when another section changed meanwhile', function (): void {
    $colours = brandItem()->sections()->where('anchor', 'brand/colours')->sole();
    $proposalId = proposeBrand([
        'section_anchor' => 'brand/colours',
        'base_section_hash' => hash('sha256', (string) $colours->markdown),
        'body' => "## Colours\n\nPrimary: `#2563eb`.",
    ])->json('id');

    File::put($this->sourcePath.'/rules/brand.md', str_replace('Inter for the interface', 'Geist for the interface', BRAND_FILE)."\n");

    ['proposal' => $proposal, 'written' => $written] = materialiseProposal($proposalId);

    expect($proposal->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::LocalReview)
        ->and($written)->toContain('Primary: `#2563eb`.')
        ->and($written)->toContain('Geist for the interface');
});

it('ends in conflict without writing when the edited section or the file changed meanwhile', function (array $proposalFields, string $changedFrom, string $changedTo): void {
    $item = brandItem();
    $colours = $item->sections()->where('anchor', 'brand/colours')->sole();
    $proposalId = proposeBrand([
        ...$proposalFields,
        'base_content_hash' => $item->content_hash,
        'base_section_hash' => hash('sha256', (string) $colours->markdown),
    ])->assertCreated()->json('id');

    $changedFile = str_replace($changedFrom, $changedTo, BRAND_FILE)."\n";
    File::put($this->sourcePath.'/rules/brand.md', $changedFile);

    ['proposal' => $proposal, 'written' => $written] = materialiseProposal($proposalId);

    expect($proposal->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::Conflict)
        ->and($proposal->materialisation_error)->toContain('re-read and propose again')
        ->and($written)->toBe($changedFile);
})->with([
    'same section' => [['section_anchor' => 'brand/colours', 'body' => "## Colours\n\nPrimary: red."], '(teal)', '(dark teal)'],
    'whole file' => [['body' => "# Brand\n\nRewritten."], 'Inter for the interface', 'Geist for the interface'],
]);

it('keeps the frontmatter on a whole-file update without fences', function (): void {
    $proposalId = proposeBrand([
        'base_content_hash' => brandItem()->content_hash,
        'body' => "# Brand\n\nRewritten.\n",
    ])->json('id');

    ['written' => $written] = materialiseProposal($proposalId);

    expect($written)->toBe("---\ntitle: Brand\narea: rules\nvisibility: internal\n---\n# Brand\n\nRewritten.\n");
});

it('lists the other open proposals that change the same item or section', function (): void {
    $item = brandItem();
    $colours = $item->sections()->where('anchor', 'brand/colours')->sole();
    $typography = $item->sections()->where('anchor', 'brand/typography')->sole();

    $wholeFileId = proposeBrand(['base_content_hash' => $item->content_hash, 'body' => '# Brand'])
        ->assertJsonPath('overlapping_open_proposals', [])
        ->json('id');

    proposeBrand(['section_anchor' => 'brand/colours', 'base_section_hash' => hash('sha256', (string) $colours->markdown), 'body' => '## Colours'])
        ->assertJsonPath('overlapping_open_proposals', [$wholeFileId]);

    Proposal::query()->whereKey($wholeFileId)->update(['status' => ProposalStatusEnum::Rejected]);

    proposeBrand(['section_anchor' => 'brand/typography', 'base_section_hash' => hash('sha256', (string) $typography->markdown), 'body' => '## Typography'])
        ->assertJsonPath('overlapping_open_proposals', []);
});

it('changes only the frontmatter and keeps the body byte for byte (#219)', function (): void {
    $proposalId = proposeBrand([
        'base_content_hash' => brandItem()->content_hash,
        'frontmatter' => ['status' => 'superseded', 'superseded_by' => 'rules/brand-2027', 'visibility' => null],
    ])->assertCreated()->assertJsonPath('frontmatter_changes.status', 'superseded')->json('id');

    ['proposal' => $proposal, 'written' => $written] = materialiseProposal($proposalId);
    [$frontmatter, $body] = [(new FrontmatterParser)->parse((string) $written)['frontmatter'], (new FrontmatterParser)->parse((string) $written)['body']];

    expect($proposal->materialisation_status)->toBe(ProposalMaterialisationStatusEnum::LocalReview)
        ->and($frontmatter)->toBe(['title' => 'Brand', 'area' => 'rules', 'status' => 'superseded', 'superseded_by' => 'rules/brand-2027'])
        ->and($body)->toBe((new FrontmatterParser)->parse(BRAND_FILE."\n")['body']);
});

it('combines a frontmatter change with a section edit (#219)', function (): void {
    $colours = brandItem()->sections()->where('anchor', 'brand/colours')->sole();
    $proposalId = proposeBrand([
        'section_anchor' => 'brand/colours',
        'base_section_hash' => hash('sha256', (string) $colours->markdown),
        'body' => "## Colours\n\nPrimary: blue.",
        'frontmatter' => ['review_date' => '2027-06-30'],
    ])->assertCreated()->json('id');

    ['written' => $written] = materialiseProposal($proposalId);

    expect($written)->toContain('Primary: blue.')
        ->and((new FrontmatterParser)->parse((string) $written)['frontmatter']['review_date'])->toBe('2027-06-30');
});

it('refuses invalid metadata changes (#219)', function (array $payload, string $message): void {
    proposeBrand($payload)->assertUnprocessable()->assertJsonPath('message', $message);
})->with([
    'unknown status' => [['base_content_hash' => str_repeat('a', 64), 'frontmatter' => ['status' => 'retired']], 'frontmatter "status" has an invalid value.'],
    'bad date' => [['base_content_hash' => str_repeat('a', 64), 'frontmatter' => ['review_date' => 'next year']], 'frontmatter "review_date" has an invalid value.'],
    'on create' => [['action' => 'create', 'stable_key' => 'rules/new', 'path' => 'rules/new.md', 'body' => '# New', 'frontmatter' => ['status' => 'draft']], 'frontmatter is only allowed on update.'],
]);

it('refuses an update that changes nothing (#219)', function (): void {
    proposeBrand(['base_content_hash' => brandItem()->content_hash])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'An update needs a body, frontmatter changes, or both.');
});
