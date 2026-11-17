<?php

declare(strict_types=1);

namespace App\Brain\Support;

use App\Brain\Data\BlockData;
use App\Brain\Data\LinkEdgeData;
use App\Brain\Data\SectionData;
use App\Brain\Enums\BlockTypeEnum;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Deterministic section / block / link extraction for markdown bodies.
 */
final class MarkdownStructureParser
{
    private MarkdownParser $parser;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $this->parser = new MarkdownParser($environment);
    }

    /**
     * @return array{sections: list<SectionData>, edges: list<LinkEdgeData>, issues: list<array{path: string, code: string, message: string}>}
     */
    public function parse(string $body, string $path): array
    {
        $document = $this->parser->parse($body);

        $sections = [];
        $edges = [];
        $issues = [];
        $position = 0;

        $currentHeading = '';
        $currentLevel = 1;
        /** @var list<string> $currentAnchorParts */
        $currentAnchorParts = [];
        /** @var list<string> $currentBodyLines */
        $currentBodyLines = [];
        /** @var list<BlockData> $currentBlocks */
        $currentBlocks = [];
        /** @var list<string> $pendingText */
        $pendingText = [];
        $currentStartLine = 1;
        /** @var list<int> $sectionStartLines */
        $sectionStartLines = [];

        foreach ($document->children() as $child) {
            if ($child instanceof Heading) {
                $this->flushSection(
                    $sections,
                    $position,
                    $currentHeading,
                    $currentLevel,
                    $currentAnchorParts,
                    $currentBodyLines,
                    $currentBlocks,
                    $pendingText,
                );
                $this->recordSectionStart($sections, $sectionStartLines, $currentStartLine);
                $currentStartLine = $child->getStartLine() ?? $currentStartLine;

                $headingText = $this->plainText($child);
                $level = $child->getLevel();
                $currentHeading = $headingText;
                $currentLevel = $level;
                $currentAnchorParts = array_slice($currentAnchorParts, 0, max(0, $level - 1));
                $currentAnchorParts[] = $this->slug($headingText);
                $currentBodyLines[] = str_repeat('#', $level).' '.$headingText;

                continue;
            }

            $currentBodyLines[] = $this->nodeSourceHint($child);
            $sectionAnchor = $this->anchorFor(
                $currentAnchorParts,
                $currentHeading !== '' ? $currentHeading : 'body',
            );

            if ($child instanceof FencedCode) {
                $this->flushPendingText($pendingText, $currentBlocks);
                $currentBlocks[] = new BlockData(BlockTypeEnum::Code, [
                    'language' => $child->getInfo() !== '' ? $child->getInfo() : null,
                    'source' => $child->getLiteral(),
                ]);

                continue;
            }

            if ($child instanceof Paragraph) {
                $paragraphText = trim($this->plainText($child));
                if ($paragraphText !== '') {
                    $pendingText[] = $paragraphText;
                }
                $this->collectInlineLinks(
                    $child,
                    $path,
                    $sectionAnchor,
                    $edges,
                    $issues,
                    $currentBlocks,
                    $pendingText,
                );

                continue;
            }

            $this->collectInlineLinks(
                $child,
                $path,
                $sectionAnchor,
                $edges,
                $issues,
                $currentBlocks,
                $pendingText,
            );
        }

        $this->flushSection(
            $sections,
            $position,
            $currentHeading,
            $currentLevel,
            $currentAnchorParts,
            $currentBodyLines,
            $currentBlocks,
            $pendingText,
        );
        $this->recordSectionStart($sections, $sectionStartLines, $currentStartLine);
        $sections = $this->withSourceMarkdown($sections, $sectionStartLines, $body);

        foreach ($this->wikiLinks($body) as $target) {
            $edges[] = new LinkEdgeData(
                fromPath: $path,
                target: $target,
                relation: 'links_to',
            );
        }

        return [
            'sections' => $sections,
            'edges' => $this->uniqueEdges($edges),
            'issues' => $issues,
        ];
    }

    /**
     * Remembers where the section just flushed starts, keeping one start line per section.
     *
     * @param  list<SectionData>  $sections
     * @param  list<int>  $sectionStartLines
     */
    private function recordSectionStart(array $sections, array &$sectionStartLines, int $startLine): void
    {
        if (count($sections) > count($sectionStartLines)) {
            $sectionStartLines[] = $startLine;
        }
    }

    /**
     * Adds each section's source markdown and line span: from its heading (or line 1 for the text
     * before the first heading) to the line before the next section. Writers edit a section by
     * replacing exactly these lines, so the other sections stay byte for byte as they were.
     *
     * @param  list<SectionData>  $sections
     * @param  list<int>  $sectionStartLines
     * @return list<SectionData>
     */
    private function withSourceMarkdown(array $sections, array $sectionStartLines, string $body): array
    {
        $bodyLines = preg_split('/\r\n|\n|\r/', $body) ?: [];
        $lineCount = count($bodyLines);
        $withMarkdown = [];

        foreach ($sections as $index => $section) {
            $startLine = $sectionStartLines[$index] ?? 1;
            $endLine = isset($sectionStartLines[$index + 1]) ? $sectionStartLines[$index + 1] - 1 : $lineCount;
            $markdown = rtrim(implode("\n", array_slice($bodyLines, $startLine - 1, max(0, $endLine - $startLine + 1))));

            $withMarkdown[] = new SectionData(
                anchor: $section->anchor,
                heading: $section->heading,
                level: $section->level,
                position: $section->position,
                body: $section->body,
                blocks: $section->blocks,
                markdown: $markdown,
                startLine: $startLine,
                endLine: $endLine,
            );
        }

        return $withMarkdown;
    }

    public function firstParagraphSummary(string $body, int $maxLength = 240): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        $withoutHeadings = preg_replace('/^#{1,6}\s+.*$/m', '', $trimmed) ?? $trimmed;
        $paragraph = trim(explode("\n\n", trim($withoutHeadings), 2)[0]);
        $paragraph = preg_replace('/\s+/', ' ', $paragraph) ?? $paragraph;

        if (strlen($paragraph) <= $maxLength) {
            return $paragraph;
        }

        return rtrim(substr($paragraph, 0, $maxLength - 1)).'…';
    }

    /**
     * @param  list<SectionData>  $sections
     * @param  list<string>  $currentAnchorParts
     * @param  list<string>  $currentBodyLines
     * @param  list<BlockData>  $currentBlocks
     * @param  list<string>  $pendingText
     */
    private function flushSection(
        array &$sections,
        int &$position,
        string &$currentHeading,
        int $currentLevel,
        array $currentAnchorParts,
        array &$currentBodyLines,
        array &$currentBlocks,
        array &$pendingText,
    ): void {
        $this->flushPendingText($pendingText, $currentBlocks);

        if ($currentHeading === '' && $currentBodyLines === [] && $currentBlocks === []) {
            return;
        }

        $anchor = $this->anchorFor(
            $currentAnchorParts,
            $currentHeading !== '' ? $currentHeading : 'body',
        );

        $sections[] = new SectionData(
            anchor: $anchor,
            heading: $currentHeading !== '' ? $currentHeading : 'Body',
            level: $currentLevel,
            position: $position,
            body: trim(implode("\n", $currentBodyLines)),
            blocks: $currentBlocks,
        );

        $position++;
        $currentBodyLines = [];
        $currentBlocks = [];
    }

    /**
     * @param  list<string>  $pendingText
     * @param  list<BlockData>  $currentBlocks
     */
    private function flushPendingText(array &$pendingText, array &$currentBlocks): void
    {
        if ($pendingText === []) {
            return;
        }

        $text = trim(implode("\n\n", $pendingText));
        $pendingText = [];
        if ($text === '') {
            return;
        }

        $currentBlocks[] = new BlockData(BlockTypeEnum::Text, ['text' => $text]);
    }

    /**
     * @param  list<string>  $parts
     */
    private function anchorFor(array $parts, string $fallback): string
    {
        if ($parts === []) {
            return $this->slug($fallback);
        }

        return implode('/', $parts);
    }

    private function slug(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-') ?: 'section';
    }

    private function plainText(Node $node): string
    {
        $parts = [];
        $this->walk($node, function (Node $descendant) use (&$parts): void {
            if ($descendant instanceof Text) {
                $parts[] = $descendant->getLiteral();
            }
        });

        return trim(implode('', $parts));
    }

    private function nodeSourceHint(Node $node): string
    {
        if ($node instanceof FencedCode) {
            $info = $node->getInfo();

            return '```'.($info !== '' ? $info : '')."\n".$node->getLiteral().'```';
        }

        return $this->plainText($node);
    }

    /**
     * @param  list<LinkEdgeData>  $edges
     * @param  list<array{path: string, code: string, message: string}>  $issues
     * @param  list<BlockData>  $currentBlocks
     * @param  list<string>  $pendingText
     */
    private function collectInlineLinks(
        Node $node,
        string $path,
        string $section,
        array &$edges,
        array &$issues,
        array &$currentBlocks,
        array &$pendingText,
    ): void {
        $this->walk($node, function (Node $descendant) use (
            $path,
            $section,
            &$edges,
            &$issues,
            &$currentBlocks,
            &$pendingText,
        ): void {
            if ($descendant instanceof Link) {
                $url = $descendant->getUrl();
                if ($url !== '' && ! str_starts_with($url, '#')) {
                    $edges[] = new LinkEdgeData(
                        fromPath: $path,
                        target: $url,
                        relation: 'links_to',
                        fromSection: $section,
                    );
                }
            }

            if ($descendant instanceof Image) {
                $this->flushPendingText($pendingText, $currentBlocks);
                $alt = $descendant->getTitle() ?: $this->plainText($descendant);
                if (trim($alt) === '') {
                    $issues[] = [
                        'path' => $path,
                        'code' => 'image_missing_alt',
                        'message' => sprintf('Image "%s" is missing mandatory alt text.', $descendant->getUrl()),
                    ];
                    $alt = '';
                }
                $currentBlocks[] = new BlockData(BlockTypeEnum::Image, [
                    'path' => $descendant->getUrl(),
                    'alt' => $alt,
                    'caption' => $descendant->getTitle() !== '' ? $descendant->getTitle() : null,
                ]);

                // A picture in the repo is an asset of the brain: the edge lets the sync resolve it,
                // so the page can show it and the asset knows where it is used (#311).
                $url = $descendant->getUrl();
                if ($url !== '' && preg_match('#^(?:[a-z][a-z\d+.-]*:|//|\#)#i', $url) !== 1) {
                    $edges[] = new LinkEdgeData(
                        fromPath: $path,
                        target: $url,
                        relation: 'embeds',
                        fromSection: $section,
                    );
                }
            }
        });
    }

    /**
     * @param  callable(Node): void  $visitor
     */
    private function walk(Node $node, callable $visitor): void
    {
        $visitor($node);
        foreach ($node->children() as $child) {
            $this->walk($child, $visitor);
        }
    }

    /**
     * @return list<string>
     */
    private function wikiLinks(string $body): array
    {
        preg_match_all('/\[\[([^\]|#]+)(?:\|[^\]]+)?\]\]/', $body, $matches);
        $targets = $matches[1];

        return array_values(array_unique(array_map(trim(...), $targets)));
    }

    /**
     * @param  list<LinkEdgeData>  $edges
     * @return list<LinkEdgeData>
     */
    private function uniqueEdges(array $edges): array
    {
        $seen = [];
        $unique = [];
        foreach ($edges as $edge) {
            $key = $edge->fromPath.'|'.$edge->relation.'|'.$edge->target.'|'.($edge->fromSection ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $edge;
        }

        return $unique;
    }
}
