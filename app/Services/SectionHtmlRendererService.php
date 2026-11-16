<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Renders a section's stored markdown to HTML for the reading view (#241), so people read the
 * entry as written: lists, emphasis, inline code, code blocks, tables and links. Raw HTML in the
 * source is escaped and unsafe links (javascript:, data:) are dropped, so the output is safe to
 * insert. The section's own heading line is left out (the page shows it). Wiki links and relative
 * links that the sync resolved to an item open that item's reading view; unresolved wiki links
 * show their text.
 */
final class SectionHtmlRendererService
{
    private ?GithubFlavoredMarkdownConverter $converter = null;

    /**
     * @param  array<string, string>  $urlsByLinkTarget  Link targets as the sync stored them (`[[Title]]` text or a relative path), mapped to the reading URL of the item they resolved to.
     */
    public function render(?string $markdown, array $urlsByLinkTarget = []): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        $markdown = preg_replace('/\A#{1,6}[^\n]*\n?/', '', ltrim($markdown, "\n")) ?? $markdown;
        $markdown = $this->replaceWikiLinks($markdown, $urlsByLinkTarget);

        $html = (string) $this->converter()->convert($markdown);

        foreach ($urlsByLinkTarget as $target => $url) {
            $html = str_replace('href="'.e($target).'"', 'href="'.e($url).'"', $html);
        }

        return trim($html);
    }

    /**
     * `[[Target]]` and `[[Target|Label]]` become markdown links when the target resolved, their
     * label as plain text otherwise.
     *
     * @param  array<string, string>  $urlsByLinkTarget
     */
    private function replaceWikiLinks(string $markdown, array $urlsByLinkTarget): string
    {
        return preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/', static function (array $matches) use ($urlsByLinkTarget): string {
            $target = trim(explode('#', $matches[1])[0]);
            $label = trim($matches[2] ?? '') !== '' ? trim($matches[2]) : $target;
            $escapedLabel = str_replace(['[', ']'], ['\[', '\]'], $label);
            $url = $urlsByLinkTarget[$target] ?? null;

            return $url === null ? $escapedLabel : '['.$escapedLabel.']('.$url.')';
        }, $markdown) ?? $markdown;
    }

    private function converter(): GithubFlavoredMarkdownConverter
    {
        return $this->converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
    }
}
