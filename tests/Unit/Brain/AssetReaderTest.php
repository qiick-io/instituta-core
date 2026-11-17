<?php

declare(strict_types=1);

use App\Brain\Data\ItemData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Readers\AssetReader;
use App\Brain\Readers\BrainReader;

/**
 * Images and PDFs enter the brain as assets with metadata only (#311).
 */
function assetBrain(): string
{
    $root = sys_get_temp_dir().'/instituta-assets-'.uniqid();
    mkdir($root.'/brand/excubia', 0777, true);
    mkdir($root.'/notes', 0777, true);

    // A real 3 × 2 PNG.
    $image = imagecreatetruecolor(3, 2);
    ob_start();
    imagepng($image);
    file_put_contents($root.'/brand/excubia/mark.png', (string) ob_get_clean());

    file_put_contents($root.'/brand/excubia/mark.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 40"><rect width="120" height="40"/></svg>');
    file_put_contents($root.'/brand/excubia/mark.svg.meta.yml', "title: Excubia mark\nalt: The Excubia shield\ncaption: Dark variant\ntags: [brand, excubia]\n");
    file_put_contents($root.'/notes/guide.pdf', "%PDF-1.4\n%fake\n");
    file_put_contents($root.'/notes/brand.md', "# Brand\n\nThe mark:\n\n![The Excubia shield](../brand/excubia/mark.svg)\n");

    return $root;
}

/**
 * @param  list<ItemData>  $items
 */
function assetItem(array $items, string $path): ?ItemData
{
    return collect($items)->first(fn (ItemData $item): bool => $item->path === $path);
}

it('reads images and PDFs as asset items with their size and format', function (): void {
    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: assetBrain(), source: 'knowledge', commit: 'abc1234'));

    $png = assetItem($result->items, 'brand/excubia/mark.png');
    expect($png)->not->toBeNull()
        ->and($png->adapter)->toBe('asset')
        ->and($png->type)->toBe('image')
        ->and($png->area)->toBe('style')
        ->and($png->title)->toBe('mark.png')
        ->and($png->frontmatter['asset']['width'])->toBe(3)
        ->and($png->frontmatter['asset']['height'])->toBe(2)
        ->and($png->frontmatter['asset']['sha256'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($png->summary)->toStartWith('PNG image, 3 × 2')
        ->and($png->citation->commit)->toBe('abc1234');

    $pdf = assetItem($result->items, 'notes/guide.pdf');
    expect($pdf?->type)->toBe('document')
        ->and($pdf?->frontmatter['asset']['mime'])->toBe('application/pdf');
});

it('takes title, alt, caption and tags from a sidecar, which is not an item itself', function (): void {
    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: assetBrain()));

    $svg = assetItem($result->items, 'brand/excubia/mark.svg');
    expect($svg?->title)->toBe('Excubia mark')
        ->and($svg?->summary)->toBe('Dark variant')
        ->and($svg?->tags)->toBe(['brand', 'excubia'])
        ->and($svg?->frontmatter['asset']['alt'])->toBe('The Excubia shield')
        ->and($svg?->frontmatter['asset']['width'])->toBe(120)
        ->and($svg?->frontmatter['asset']['height'])->toBe(40)
        ->and(assetItem($result->items, 'brand/excubia/mark.svg.meta.yml'))->toBeNull();
});

it('records a markdown image as an embeds edge', function (): void {
    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: assetBrain()));

    expect(collect($result->edges)->first(fn ($edge) => $edge->relation === 'embeds'))
        ->not->toBeNull()
        ->target->toBe('../brand/excubia/mark.svg')
        ->fromPath->toBe('notes/brand.md');
});

it('skips an asset over the size limit', function (): void {
    $root = assetBrain();
    $handle = fopen($root.'/notes/huge.png', 'wb');
    ftruncate($handle, AssetReader::MAX_BYTES + 1);
    fclose($handle);

    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: $root));

    expect(assetItem($result->items, 'notes/huge.png'))->toBeNull()
        ->and(collect($result->skipped)->first(fn ($skip) => $skip->path === 'notes/huge.png')?->reason)->toBe('asset_too_large');
});

it('reads archives, fonts and Office files as download-only files', function (): void {
    $root = assetBrain();
    file_put_contents($root.'/notes/kit.zip', "PK\x03\x04fake");
    file_put_contents($root.'/brand/inter.woff2', 'wOF2fake');
    file_put_contents($root.'/notes/budget.xlsx', "PK\x03\x04fake");

    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: $root));

    $zip = assetItem($result->items, 'notes/kit.zip');
    $font = assetItem($result->items, 'brand/inter.woff2');
    $sheet = assetItem($result->items, 'notes/budget.xlsx');

    expect($zip?->type)->toBe('file')
        ->and($zip?->frontmatter['asset']['kind'])->toBe('archive')
        ->and($zip?->summary)->toStartWith('ZIP archive')
        ->and($font?->frontmatter['asset']['kind'])->toBe('font')
        ->and($font?->frontmatter['asset']['mime'])->toBe('font/woff2')
        ->and($sheet?->frontmatter['asset']['kind'])->toBe('spreadsheet')
        ->and($sheet?->summary)->toStartWith('XLSX spreadsheet')
        ->and(AssetReader::isDownloadOnly('notes/kit.zip'))->toBeTrue()
        ->and(AssetReader::isDownloadOnly('notes/guide.pdf'))->toBeFalse();
});
