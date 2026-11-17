<?php

declare(strict_types=1);

use App\Brain\Data\ItemData;
use App\Brain\Data\ReadRequestData;
use App\Brain\Readers\BrainReader;
use App\Brain\Support\DocumentTextExtractor;

/**
 * Office and OpenDocument text is read locally and becomes searchable sections (#317).
 *
 * @param  array<string, string>  $entries
 */
function officeFile(string $path, array $entries): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
}

function officeBrain(): string
{
    $root = sys_get_temp_dir().'/instituta-office-'.uniqid();
    mkdir($root.'/notes', 0777, true);

    officeFile($root.'/notes/spec.docx', [
        'word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Release &amp; rollout</w:t></w:r></w:p><w:p><w:r><w:t xml:space="preserve">Ship on </w:t></w:r><w:r><w:t>Fridays.</w:t></w:r></w:p></w:body></w:document>',
    ]);
    officeFile($root.'/notes/deck.pptx', [
        'ppt/slides/slide2.xml' => '<p:sld><a:p><a:r><a:t>Second slide</a:t></a:r></a:p></p:sld>',
        'ppt/slides/slide1.xml' => '<p:sld><a:p><a:r><a:t>First slide</a:t></a:r></a:p></p:sld>',
    ]);
    officeFile($root.'/notes/budget.xlsx', [
        'xl/sharedStrings.xml' => '<sst><si><t>Hosting</t></si><si><t>Licences</t></si></sst>',
    ]);
    officeFile($root.'/notes/minutes.odt', [
        'content.xml' => '<office:document-content><office:body><office:text><text:h>Minutes</text:h><text:p>We agreed<text:tab/>to ship.</text:p></office:text></office:body></office:document-content>',
    ]);
    officeFile($root.'/notes/contacts.docx', [
        'word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Write to jane.doe@example.com</w:t></w:r></w:p></w:body></w:document>',
    ]);

    return $root;
}

/**
 * @param  list<ItemData>  $items
 */
function officeItem(array $items, string $path): ItemData
{
    $item = collect($items)->first(fn (ItemData $candidate): bool => $candidate->path === $path);
    expect($item)->not->toBeNull();

    return $item;
}

it('reads the text of Word, PowerPoint, Excel and OpenDocument files into sections', function (): void {
    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: officeBrain()));

    $docx = officeItem($result->items, 'notes/spec.docx');
    expect($docx->sections)->toHaveCount(1)
        ->and($docx->sections[0]->body)->toBe("Release & rollout\n\nShip on Fridays.")
        ->and($docx->frontmatter['asset']['has_text'])->toBeTrue()
        ->and($docx->body)->toContain('Ship on Fridays.');

    $deck = officeItem($result->items, 'notes/deck.pptx');
    expect(array_map(fn ($section) => [$section->heading, $section->body], $deck->sections))
        ->toBe([['Slide 1', 'First slide'], ['Slide 2', 'Second slide']]);

    expect(officeItem($result->items, 'notes/budget.xlsx')->sections[0]->body)->toBe("Hosting\nLicences")
        ->and(officeItem($result->items, 'notes/minutes.odt')->sections[0]->body)->toBe("Minutes\n\nWe agreed\tto ship.");
});

it('keeps the file but withholds text that holds personal data, reported without the match', function (): void {
    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: officeBrain()));

    $contacts = officeItem($result->items, 'notes/contacts.docx');
    $skip = collect($result->skipped)->first(fn ($skipped) => $skipped->path === 'notes/contacts.docx');

    expect($contacts->sections)->toBe([])
        ->and($contacts->body)->not->toContain('jane.doe')
        ->and($contacts->frontmatter['asset']['has_text'])->toBeFalse()
        ->and($skip?->reason)->toBe('text_withheld:secret_pii:email_address');
});

it('does not read legacy binary Office formats', function (): void {
    expect(DocumentTextExtractor::supports('doc'))->toBeFalse()
        ->and(DocumentTextExtractor::supports('docx'))->toBeTrue();
});
