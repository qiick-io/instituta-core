<?php

declare(strict_types=1);

use App\Brain\Enums\VisibilityEnum;
use App\Enums\CompanyKindEnum;
use App\Enums\ProposalStatusEnum;
use App\Models\AgentSession;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\ContextFreeze;
use App\Models\ContractCall;
use App\Models\Grant;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Principal;
use App\Models\Proposal;
use App\Models\Source;
use App\Models\SourceReference;
use App\Models\StructuredRow;
use App\Models\StructuredTable;

/*
 * Lazy factory definitions must build rows the schema accepts so tests can call
 * `::factory()->create()` without seeding a hidden parent graph by hand.
 */

test('the api key factory stores a chosen secret hash and stays active by default', function (): void {
    $secret = ApiKey::factory()->makeSecret();
    $apiKey = ApiKey::factory()->withSecret($secret)->create();

    expect($apiKey->hash)->toBe(hash('sha256', $secret['plain']))
        ->and($apiKey->principal)->toBeInstanceOf(Principal::class)
        ->and($apiKey->scope_id)->toBe($apiKey->principal->organisation_id)
        ->and($apiKey->revoked_at)->toBeNull()
        ->and(ApiKey::factory()->revoked()->create()->revoked_at)->not->toBeNull()
        ->and(ApiKey::factory()->expired()->create()->expires_at)->not->toBeNull();
});

test('the item factory inherits organisation from its source and supports visibility states', function (): void {
    $item = Item::factory()->create();

    expect($item->organisation_id)->toBe($item->source->organisation_id)
        ->and($item->visibility)->toBe(VisibilityEnum::Internal)
        ->and(Item::factory()->public()->create()->visibility)->toBe(VisibilityEnum::Public)
        ->and(Item::factory()->decision()->create()->type)->toBe('decision');
});

test('grant proposal and contract call factories share the principal organisation', function (): void {
    $grant = Grant::factory()->owner()->create();
    $proposal = Proposal::factory()->accepted()->create();
    $contractCall = ContractCall::factory()->create();

    expect($grant->scope_id)->toBe($grant->principal->organisation_id)
        ->and($proposal->organisation_id)->toBe($proposal->proposedByPrincipal->organisation_id)
        ->and($proposal->status)->toBe(ProposalStatusEnum::Accepted)
        ->and($contractCall->organisation_id)->toBe($contractCall->principal->organisation_id);
});

test('section table row and link factories copy tenancy from the parent item', function (): void {
    $section = ItemSection::factory()->create();
    $table = StructuredTable::factory()->create();
    $row = StructuredRow::factory()->create();
    $link = ItemLink::factory()->resolved()->create();

    expect($section->organisation_id)->toBe($section->item->organisation_id)
        ->and($table->organisation_id)->toBe($table->item->organisation_id)
        ->and($row->structured_table_id)->toBe($row->structuredTable->id)
        ->and($row->organisation_id)->toBe($row->structuredTable->organisation_id)
        ->and($link->to_item_id)->not->toBeNull()
        ->and($link->target)->toBe(Item::query()->findOrFail($link->to_item_id)->stable_key);
});

test('agent session context freeze and source reference factories create usable defaults', function (): void {
    $session = AgentSession::factory()->invalidated()->create();
    $freeze = ContextFreeze::factory()->lifted()->create();
    $reference = SourceReference::factory()->create();
    $pinned = SourceReference::factory()->pinnedCommit('abc123')->create();
    $company = Company::factory()->client()->create();

    expect($session->invalidated_at)->not->toBeNull()
        ->and($session->api_key_id)->not->toBeEmpty()
        ->and($freeze->lifted_at)->not->toBeNull()
        ->and($freeze->organisation_id)->toBe($freeze->frozenByPrincipal->organisation_id)
        ->and($reference->source_version_id)->not->toBeNull()
        ->and($reference->source_id)->toBe($reference->sourceVersion->source_id)
        ->and(Source::query()->findOrFail($reference->source_id)->is_shared)->toBeTrue()
        ->and($pinned->source_version_id)->toBeNull()
        ->and($pinned->pinned_commit)->toBe('abc123')
        ->and($pinned->source_id)->not->toBeEmpty()
        ->and($company->kind)->toBe(CompanyKindEnum::Client);
});
