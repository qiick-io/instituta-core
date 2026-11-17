<?php

declare(strict_types=1);

namespace App\Brain\Support;

use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Reads the text out of PDFs (#321), Office and OpenDocument files (#317), locally: no file leaves
 * the app and no model is involved. OOXML (`docx`, `pptx`, `xlsx`) and ODF (`odt`, `odp`, `ods`) are zip
 * archives of XML, so the text is the XML's text nodes with paragraph and slide breaks kept.
 * Legacy binary formats (`doc`, `xls`, `ppt`) are not read.
 *
 * Safety: the XML is never parsed as a document (no entity expansion, no XXE): tags are turned into
 * breaks or dropped with patterns. An entry larger than MAX_ENTRY_BYTES uncompressed is not read,
 * so a zip bomb cannot fill memory; the text is capped at MAX_TEXT_CHARS.
 */
final class DocumentTextExtractor
{
    private const int MAX_ENTRY_BYTES = 50 * 1024 * 1024;

    public const int MAX_TEXT_CHARS = 500_000;

    /** Pages read from a PDF; the rest stays in the file. */
    private const int MAX_PDF_PAGES = 500;

    /**
     * The file's text as parts in reading order (pages, slides, or one part), or null when the
     * format is not read or nothing could be read.
     *
     * @return list<array{heading: string, text: string}>|null
     */
    public function extract(string $absolutePath, string $extension): ?array
    {
        if ($extension === 'pdf') {
            $pages = $this->pdfPages($absolutePath);

            return $pages === null ? null : $this->capped($pages);
        }

        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $parts = match ($extension) {
                'docx' => $this->single($this->wordText($this->entry($zip, 'word/document.xml'))),
                'pptx' => $this->slides($zip),
                'xlsx' => $this->single($this->sharedStrings($this->entry($zip, 'xl/sharedStrings.xml'))),
                'odt', 'odp', 'ods' => $this->single($this->odfText($this->entry($zip, 'content.xml'))),
                default => null,
            };
        } finally {
            $zip->close();
        }

        return $parts === null ? null : $this->capped($parts);
    }

    /**
     * Whether the extension is a format this class reads.
     */
    public static function supports(string $extension): bool
    {
        return in_array($extension, ['pdf', 'docx', 'pptx', 'xlsx', 'odt', 'odp', 'ods'], true);
    }

    /**
     * One part per PDF page that has text, read with smalot/pdfparser (LGPL-3.0, used unmodified,
     * #321). A scanned PDF has no text layer and gives nothing; a malformed or encrypted one gives
     * nothing either: the file stays viewable and downloadable.
     *
     * @return list<array{heading: string, text: string}>|null
     */
    private function pdfPages(string $absolutePath): ?array
    {
        try {
            $document = (new PdfParser)->parseFile($absolutePath);
            $parts = [];

            foreach (array_slice($document->getPages(), 0, self::MAX_PDF_PAGES) as $index => $page) {
                $text = $this->clean($page->getText());

                if ($text !== '') {
                    $parts[] = ['heading' => 'Page '.($index + 1), 'text' => $text];
                }
            }

            return $parts === [] ? null : $parts;
        } catch (Throwable) {
            return null;
        }
    }

    private function entry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);

        if ($stat === false || $stat['size'] > self::MAX_ENTRY_BYTES) {
            return null;
        }

        $contents = $zip->getFromName($name);

        return $contents === false ? null : $contents;
    }

    /**
     * @return list<array{heading: string, text: string}>|null
     */
    private function single(?string $text): ?array
    {
        return $text === null || trim($text) === '' ? null : [['heading' => 'Text', 'text' => $text]];
    }

    /**
     * One part per slide, in slide order.
     *
     * @return list<array{heading: string, text: string}>|null
     */
    private function slides(ZipArchive $zip): ?array
    {
        $slides = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $match) === 1) {
                $slides[(int) $match[1]] = $name;
            }
        }

        ksort($slides);
        $parts = [];

        foreach ($slides as $number => $name) {
            $text = $this->drawingText($this->entry($zip, $name));

            if ($text !== null && trim($text) !== '') {
                $parts[] = ['heading' => 'Slide '.$number, 'text' => $text];
            }
        }

        return $parts === [] ? null : $parts;
    }

    private function wordText(?string $xml): ?string
    {
        if ($xml === null) {
            return null;
        }

        $xml = preg_replace(['#<w:tab/>#', '#<w:br[^>]*/>#', '#</w:p>#'], ["\t", "\n", "\n\n"], $xml) ?? $xml;

        return $this->textNodes($xml, '#<w:t(?:\s[^>]*)?>(.*?)</w:t>|(\n\n|\n|\t)#s');
    }

    private function drawingText(?string $xml): ?string
    {
        if ($xml === null) {
            return null;
        }

        $xml = preg_replace('#</a:p>#', "\n", $xml) ?? $xml;

        return $this->textNodes($xml, '#<a:t(?:\s[^>]*)?>(.*?)</a:t>|(\n)#s');
    }

    private function sharedStrings(?string $xml): ?string
    {
        if ($xml === null) {
            return null;
        }

        $xml = preg_replace('#</si>#', "\n", $xml) ?? $xml;

        return $this->textNodes($xml, '#<t(?:\s[^>]*)?>(.*?)</t>|(\n)#s');
    }

    private function odfText(?string $xml): ?string
    {
        if ($xml === null) {
            return null;
        }

        $xml = preg_replace(['#<text:tab/>#', '#<text:line-break/>#', '#</text:(?:p|h)>#'], ["\t", "\n", "\n\n"], $xml) ?? $xml;
        $xml = preg_replace('#<[^>]+>#', '', $xml) ?? $xml;

        return $this->clean(html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /**
     * The text captured by `$pattern` (group 1 a text node, group 2 a kept break), entity-decoded.
     */
    private function textNodes(string $xml, string $pattern): string
    {
        preg_match_all($pattern, $xml, $matches, PREG_SET_ORDER);
        $text = '';

        foreach ($matches as $match) {
            $text .= ($match[2] ?? '') !== '' ? $match[2] : html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return $this->clean($text);
    }

    private function clean(string $text): string
    {
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /**
     * @param  list<array{heading: string, text: string}>  $parts
     * @return list<array{heading: string, text: string}>
     */
    private function capped(array $parts): array
    {
        $remaining = self::MAX_TEXT_CHARS;
        $capped = [];

        foreach ($parts as $part) {
            if ($remaining <= 0) {
                break;
            }

            $text = mb_substr($part['text'], 0, $remaining);
            $remaining -= mb_strlen($text);
            $capped[] = ['heading' => $part['heading'], 'text' => $text];
        }

        return $capped;
    }
}
