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
