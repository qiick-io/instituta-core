<?php

declare(strict_types=1);

use App\Actions\GrantOrganisationAccessAction;
use App\Ai\Agents\ProposalDraftWriter;
use App\Brain\Enums\RoleEnum;
use App\Brain\Enums\VisibilityEnum;
use App\Enums\RoleEnum as AppRoleEnum;
use App\Models\Activity;
use App\Models\Item;
use App\Models\ItemSection;
use App\Models\Organisation;
use App\Models\Source;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * "Draft with AI" in the propose drawer (#203): markdown for the form, from the user's
 * perimeter, counted in the daily budget. Never a real provider.
 */
beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    config(['ai.assistant.enabled' => true]);

    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create()->assignRole(AppRoleEnum::Admin->value);
    app(GrantOrganisationAccessAction::class)->execute($this->user, $this->organisation, RoleEnum::Member);

    $source = Source::factory()->create(['organisation_id' => $this->organisation->id]);
    $this->item = Item::factory()->create([
        'organisation_id' => $this->organisation->id,
        'source_id' => $source->id,
        'stable_key' => 'rules/brand',
        'visibility' => VisibilityEnum::Internal,
    ]);
    ItemSection::factory()->create([
        'item_id' => $this->item->id,
        'anchor' => 'brand/colours',
        'markdown' => "## Colours\n\nPrimary: teal.",
    ]);
});

it('drafts one section from the current markdown and strips a markdown fence', function (): void {
    ProposalDraftWriter::fake(["```markdown\n## Colours\n\nPrimary: blue.\n```"]);

    $this->actingAs($this->user)
        ->postJson(route('ai.drafts'), ['request' => 'Switch the primary colour to blue', 'stable_key' => 'rules/brand', 'section_anchor' => 'brand/colours'])
        ->assertOk()
        ->assertJsonPath('markdown', "## Colours\n\nPrimary: blue.");

    ProposalDraftWriter::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Primary: teal.')
        && str_contains($prompt->prompt, 'Switch the primary colour to blue'));

    $prompt = Activity::query()->where('log_name', 'ai')->where('event', 'ai_prompt')->sole();
    expect($prompt->properties['surface'])->toBe('draft');
});

it('drafts a new document without current markdown', function (): void {
    ProposalDraftWriter::fake(["# Naming\n\nUse full words."]);

    $this->actingAs($this->user)
        ->postJson(route('ai.drafts'), ['request' => 'A naming rule: full words'])
        ->assertOk()
        ->assertJsonPath('markdown', "# Naming\n\nUse full words.");

    ProposalDraftWriter::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Write a new brain document.'));
});

it('refuses items outside the perimeter, while off, and over the daily limit', function (): void {
    ProposalDraftWriter::fake(['never']);
    $foreign = Organisation::factory()->create();
    $foreignSource = Source::factory()->create(['organisation_id' => $foreign->id]);
    Item::factory()->create(['organisation_id' => $foreign->id, 'source_id' => $foreignSource->id, 'stable_key' => 'rules/secret']);

    $this->actingAs($this->user)
        ->postJson(route('ai.drafts'), ['request' => 'Change it', 'stable_key' => 'rules/secret'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No item with this stable_key in your perimeter.');

    config(['ai.daily_prompt_limit' => 1]);
    activity('ai')->causedBy($this->user)->event('ai_prompt')->log('ai_prompt');
    $this->actingAs($this->user)->postJson(route('ai.drafts'), ['request' => 'Change it'])->assertStatus(429);

    config(['ai.assistant.enabled' => false]);
    $this->actingAs($this->user)->postJson(route('ai.drafts'), ['request' => 'Change it'])->assertStatus(503);

    ProposalDraftWriter::assertNeverPrompted();
});
