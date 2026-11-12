<?php

declare(strict_types=1);

use App\Brain\Data\ReadRequestData;
use App\Brain\Exceptions\DenylistHitException;
use App\Brain\Readers\BrainReader;
use App\Brain\Scanners\DenylistScanner;
use App\Brain\Scanners\SecretPiiScanner;

function brainFixture(string $name): string
{
    return dirname(__DIR__).'/Brain/Fixtures/'.$name;
}

it('reads a native instituta fixture with sections links and citations', function (): void {
    $reader = BrainReader::withDefaults();
    $result = $reader->read(new ReadRequestData(
        path: brainFixture('native'),
        source: 'fixture-native',
        commit: 'abc1234',
    ));

    expect($result->manifest)->not->toBeNull()
        ->and($result->manifest?->name)->toBe('Native fixture')
        ->and($result->items)->not->toBeEmpty();

    $coding = collect($result->items)->first(fn ($item) => $item->path === 'rules/coding.md');
    expect($coding)->not->toBeNull()
        ->and($coding->adapter)->toBe('instituta')
        ->and($coding->type)->toBe('rule')
        ->and($coding->resolutionKey)->toBe('rules.coding')
        ->and($coding->decisionDate)->toBe('2026-01-10')
        ->and($coding->citation->commit)->toBe('abc1234')
        ->and($coding->sections)->not->toBeEmpty()
        ->and($coding->edges)->not->toBeEmpty();

    $logoSkip = collect($result->skipped)->first(fn ($skip) => str_contains($skip->path, 'logo.png'));
    expect($logoSkip)->not->toBeNull()
        ->and($logoSkip->reason)->toBe('binary_without_sidecar');
});

it('reads basic-memory observations and relation verbs', function (): void {
    $reader = BrainReader::withDefaults();
    $result = $reader->read(new ReadRequestData(path: brainFixture('basic-memory')));

    $voice = collect($result->items)->first(fn ($item) => $item->path === 'voice.md');
    expect($voice)->not->toBeNull()
        ->and($voice->adapter)->toBe('basic-memory')
        ->and($voice->stableKey)->toBe('brand/voice')
        ->and($voice->tags)->toContain('voice')
        ->and($voice->frontmatter['observations'])->toHaveCount(2);

    $clarifies = collect($voice->edges)->first(fn ($edge) => $edge->relation === 'clarifies');
    expect($clarifies)->not->toBeNull()
        ->and($clarifies->target)->toBe('Coding rules');
});

it('reads a shadcn registry into items and a structured table', function (): void {
    $reader = BrainReader::withDefaults();
    $result = $reader->read(new ReadRequestData(path: brainFixture('shadcn-registry')));

    $registry = collect($result->items)->first(fn ($item) => $item->path === 'registry.json');
    expect($registry)->not->toBeNull()
        ->and($registry->adapter)->toBe('shadcn-registry')
        ->and($registry->tables)->toHaveCount(1)
        ->and($registry->tables[0]->values)->toHaveCount(2);

    $button = collect($result->items)->first(fn ($item) => $item->title === 'Button');
    expect($button)->not->toBeNull()
        ->and($button->area)->toBe('design-system')
        ->and($button->type)->toBe('component');
});

it('reads plain markdown via the markdown adapter', function (): void {
    $reader = BrainReader::withDefaults();
    $result = $reader->read(new ReadRequestData(path: brainFixture('markdown')));

    $guide = collect($result->items)->first();
    expect($guide)->not->toBeNull()
        ->and($guide->adapter)->toBe('markdown')
        ->and($guide->title)->toBe('Operator guide')
        ->and($guide->summary)->toContain('how to read the brain')
        ->and($guide->sections)->not->toBeEmpty();
});

it('skips secret and pii content without storing the match', function (): void {
    $reader = BrainReader::withDefaults();
    $result = $reader->read(new ReadRequestData(path: brainFixture('secrets')));

    expect($result->items)->toBeEmpty()
        ->and($result->skipped)->not->toBeEmpty();

    $reasons = collect($result->skipped)->pluck('reason')->all();
    expect($reasons)->toContain('secret_pii:email_address')
        ->and(implode("\n", array_map(
            static fn ($skip) => $skip->path.' '.$skip->reason,
            $result->skipped,
        )))->not->toContain('person@example.com')
        ->and(implode("\n", array_map(
            static fn ($skip) => $skip->reason,
            $result->skipped,
        )))->toContain('secret_pii:private_key');
});

it('fails closed on denylist path hits', function (): void {
    $reader = BrainReader::withDefaults();

    expect(fn () => $reader->read(new ReadRequestData(
        path: brainFixture('denied-path/acme-klover-docs'),
    )))->toThrow(DenylistHitException::class);

    $issues = $reader->validate(new ReadRequestData(
        path: brainFixture('denied-path/acme-klover-docs'),
    ));

    expect($issues)->not->toBeEmpty()
        ->and($issues[0]->code)->toBe('denylist_hit');
});

it('matches denylist tokens case-insensitively on haystacks', function (): void {
    $scanner = new DenylistScanner;
    $match = $scanner->firstMatch(
        ['Klover', 'Cybergrant', 'Zerotredici'],
        ['https://github.com/acme/cyberGrant-docs.git'],
    );

    expect($match)->not->toBeNull()
        ->and(strtolower($match['token']))->toBe('cybergrant');
});

it('reports path hygiene skips for vendor and env files', function (): void {
    $scanner = new SecretPiiScanner;

    expect($scanner->skipReasonForPath('vendor/autoload.php'))->toStartWith('path_hygiene:')
        ->and($scanner->skipReasonForPath('apps/.env.local'))->toStartWith('path_hygiene:')
        ->and($scanner->skipReasonForContent('hello world'))->toBeNull()
        ->and($scanner->skipReasonForContent('TOKEN=ghp_abcdefghijklmnopqrstuvwxyz0123456789'))->toBe('secret_pii:api_token')
        ->and($scanner->skipReasonForContent('export DB_PASSWORD=s3cret'))->toBe('secret_pii:env_assignment')
        ->and($scanner->skipReasonForContent('clone https://user:pass@example.com/repo.git'))->toBe('secret_pii:password_in_url')
        ->and($scanner->skipReasonForContent('Call +1 415-555-2671 tomorrow'))->toBe('secret_pii:phone_number');
});
