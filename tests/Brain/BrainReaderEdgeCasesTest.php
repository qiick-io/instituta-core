<?php

declare(strict_types=1);

use App\Brain\Data\BlockData;
use App\Brain\Data\CitationData;
use App\Brain\Data\LinkEdgeData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Data\SectionData;
use App\Brain\Data\SkippedFileData;
use App\Brain\Data\StructuredTableData;
use App\Brain\Data\ValidationIssueData;
use App\Brain\Enums\BlockTypeEnum;
use App\Brain\Exceptions\UnsupportedManifestVersionException;
use App\Brain\Readers\BrainReader;
use App\Brain\Support\FrontmatterParser;
use App\Brain\Support\MarkdownStructureParser;
use App\Brain\Support\PathHelper;

function edgeFixture(string $name): string
{
    return dirname(__DIR__).'/Brain/Fixtures/'.$name;
}

it('validates a healthy native tree with no denylist issues', function (): void {
    $issues = BrainReader::withDefaults()->validate(new ReadRequestData(
        path: edgeFixture('native'),
        source: 'fixture',
        commit: 'deadbeef',
    ));

    expect($issues)->toBeArray();
});

it('reports unsupported manifest versions through validate', function (): void {
    $root = sys_get_temp_dir().'/instituta-manifest-'.uniqid();
    mkdir($root);
    file_put_contents($root.'/instituta.json', json_encode([
        'format' => 'instituta',
        'format_version' => 99,
    ]));

    $issues = BrainReader::withDefaults()->validate(new ReadRequestData(path: $root));

    expect($issues)->not->toBeEmpty()
        ->and($issues[0]->code)->toBe('manifest_version_unsupported');

    expect(fn () => BrainReader::withDefaults()->read(new ReadRequestData(path: $root)))
        ->toThrow(UnsupportedManifestVersionException::class);

    @unlink($root.'/instituta.json');
    @rmdir($root);
});

it('returns a path_not_directory issue for missing roots', function (): void {
    $result = BrainReader::withDefaults()->read(new ReadRequestData(
        path: sys_get_temp_dir().'/instituta-missing-'.uniqid(),
    ));

    expect($result->issues)->not->toBeEmpty()
        ->and($result->issues[0]->code)->toBe('path_not_directory')
        ->and($result->items)->toBeEmpty();
});

it('skips invalid registry json and records an issue', function (): void {
    $root = sys_get_temp_dir().'/instituta-registry-'.uniqid();
    mkdir($root);
    file_put_contents($root.'/registry.json', '{not-json');

    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: $root));

    expect(collect($result->skipped)->pluck('reason')->all())->toContain('invalid_registry_json')
        ->and(collect($result->issues)->pluck('code')->all())->toContain('registry_json_invalid');

    @unlink($root.'/registry.json');
    @rmdir($root);
});

it('flags missing image alt text and invalid visibility', function (): void {
    $root = sys_get_temp_dir().'/instituta-md-'.uniqid();
    mkdir($root);
    file_put_contents($root.'/note.md', <<<'MD'
---
title: Broken note
visibility: no-such-level
---

# Broken note

![](./chart.png)

Some text with a [[Wiki Target]] link.
MD);

    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: $root));
    $codes = collect($result->issues)->pluck('code')->all();

    expect($codes)->toContain('image_missing_alt')
        ->and($codes)->toContain('visibility_invalid');

    @unlink($root.'/note.md');
    @rmdir($root);
});

it('flags unparseable frontmatter fences', function (): void {
    $root = sys_get_temp_dir().'/instituta-fm-'.uniqid();
    mkdir($root);
    file_put_contents($root.'/bad.md', "---\n: not valid yaml: [[\n---\n\n# Body\n");

    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: $root));

    expect(collect($result->issues)->pluck('code')->all())->toContain('frontmatter_unparseable');

    @unlink($root.'/bad.md');
    @rmdir($root);
});

it('skips vendor paths via path hygiene', function (): void {
    $root = sys_get_temp_dir().'/instituta-vendor-'.uniqid();
    mkdir($root.'/vendor/pkg', 0777, true);
    file_put_contents($root.'/vendor/pkg/README.md', "# Vendor\n");

    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: $root));

    expect($result->items)->toBeEmpty()
        ->and(collect($result->skipped)->first()->reason)->toStartWith('path_hygiene:');

    @unlink($root.'/vendor/pkg/README.md');
    @rmdir($root.'/vendor/pkg');
    @rmdir($root.'/vendor');
    @rmdir($root);
});

it('parses fenced code blocks and wiki links in structure parser', function (): void {
    $parser = new MarkdownStructureParser;
    $parsed = $parser->parse(<<<'MD'
# Title

Intro paragraph.

```php
echo "hi";
```

See [[Other Note]].
MD, 'demo.md');

    expect($parsed['sections'])->not->toBeEmpty()
        ->and(collect($parsed['sections'])->flatMap->blocks->contains(
            fn (BlockData $block): bool => $block->type === BlockTypeEnum::Code,
        ))->toBeTrue()
        ->and(collect($parsed['edges'])->pluck('target')->all())->toContain('Other Note');

    expect($parser->firstParagraphSummary(''))->toBe('')
        ->and($parser->firstParagraphSummary(str_repeat('word ', 80)))->toEndWith('…');
});

it('parses frontmatter and normalises paths', function (): void {
    $parser = new FrontmatterParser;
    $ok = $parser->parse("---\ntitle: Hi\n---\n\nBody\n");
    expect($ok['frontmatter']['title'])->toBe('Hi')
        ->and($ok['body'])->toContain('Body')
        ->and($ok['parse_error'])->toBeFalse();

    $empty = $parser->parse("No fences\n");
    expect($empty['frontmatter'])->toBe([])
        ->and($empty['parse_error'])->toBeFalse();

    expect(PathHelper::posixRelative('/tmp/root', '/tmp/root/a/b.md'))->toBe('a/b.md')
        ->and(PathHelper::posixRelative('/tmp/root', '/tmp/root'))->toBe('')
        ->and(PathHelper::extension('Foo.MD'))->toBe('md')
        ->and(PathHelper::filename('rules/coding.md'))->toBe('coding');
});

it('serialises contract-shaped data objects', function (): void {
    $citation = new CitationData('src', 'path.md', 'intro', 'abc');
    $block = new BlockData(BlockTypeEnum::Text, ['text' => 'hi']);
    $section = new SectionData('intro', 'Intro', 2, 0, 'body', [$block]);
    $table = new StructuredTableData('t', [['key' => 'a']], [['a' => 1]], 0);
    $edge = new LinkEdgeData('a.md', 'b.md', 'links_to', 'intro');
    $skip = new SkippedFileData('x.md', 'reason');
    $issue = new ValidationIssueData('x.md', 'code', 'msg');

    expect($citation->toArray()['commit'])->toBe('abc')
        ->and($block->toArray()['type'])->toBe('text')
        ->and($section->toArray()['anchor'])->toBe('intro')
        ->and($table->toArray()['table_key'])->toBe('t')
        ->and($edge->toArray()['relation'])->toBe('links_to')
        ->and($skip->toArray()['reason'])->toBe('reason')
        ->and($issue->toArray()['code'])->toBe('code');
});
