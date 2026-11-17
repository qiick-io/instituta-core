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
        ->and(DocumentTextExtractor::supports('docx'))->toBeTrue()
        ->and(DocumentTextExtractor::supports('pdf'))->toBeTrue();
});

/**
 * A minimal, valid PDF with one line of text per page.
 *
 * @param  list<string>  $lines
 */
function textPdf(array $lines): string
{
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>'];
    $kids = [];
    $pageObjects = [];

    foreach ($lines as $index => $line) {
        $pageNumber = 4 + $index * 2;
        $kids[] = $pageNumber.' 0 R';
        $stream = 'BT /F1 18 Tf 40 700 Td ('.$line.') Tj ET';
        $pageObjects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents '.($pageNumber + 1).' 0 R >>';
        $pageObjects[] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
    }

    $objects[] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($lines).' >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects = [...$objects, ...$pageObjects];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $number => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($number + 1)." 0 obj\n".$object."\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}

it('reads the text of a PDF, one section per page', function (): void {
    $root = officeBrain();
    file_put_contents($root.'/notes/handbook.pdf', textPdf(['Welcome to the handbook', 'Holidays are booked in advance']));

    $result = BrainReader::withDefaults()->read(new ReadRequestData(path: $root));
    $handbook = officeItem($result->items, 'notes/handbook.pdf');

    expect($handbook->type)->toBe('document')
        ->and(array_map(fn ($section) => $section->heading, $handbook->sections))->toBe(['Page 1', 'Page 2'])
        ->and($handbook->sections[0]->body)->toContain('Welcome to the handbook')
        ->and($handbook->sections[1]->body)->toContain('Holidays are booked in advance')
        ->and($handbook->frontmatter['asset']['has_text'])->toBeTrue();
});

it('keeps a PDF it cannot read as a file without text', function (): void {
    $root = officeBrain();
    file_put_contents($root.'/notes/broken.pdf', "%PDF-1.4\nnot really a pdf\n");

    $broken = officeItem(BrainReader::withDefaults()->read(new ReadRequestData(path: $root))->items, 'notes/broken.pdf');

    expect($broken->sections)->toBe([])
        ->and($broken->frontmatter['asset']['has_text'])->toBeFalse();
});
