<?php

declare(strict_types=1);

use App\Services\SectionHtmlRendererService;

/**
 * Sections are read as written, and the HTML is safe to insert (#241).
 */
it('renders lists, emphasis and inline code, without the section heading', function (): void {
    $html = (new SectionHtmlRendererService)->render("## Observations\n\n- [rule] Read the notes first.\n- Use `git` for **everything**.\n");

    expect($html)->not->toContain('Observations')
        ->and($html)->toContain('<ul>')
        ->and($html)->toContain('<li>[rule] Read the notes first.</li>')
        ->and($html)->toContain('<code>git</code>')
        ->and($html)->toContain('<strong>everything</strong>');
});

it('links resolved wiki and relative links to the reading view and keeps the text of the others', function (): void {
    $html = (new SectionHtmlRendererService)->render(
        "## Relations\n\npart_of [[Knowledge Base]], follows [[Missing Note|the missing note]], see [coding](../rules/coding.md).",
        ['Knowledge Base' => '/brain/items/01kb', '../rules/coding.md' => '/brain/items/01coding'],
    );

    expect($html)->toContain('<a href="/brain/items/01kb">Knowledge Base</a>')
        ->and($html)->toContain('the missing note')
        ->and($html)->not->toContain('[[')
        ->and($html)->toContain('<a href="/brain/items/01coding">coding</a>');
});

it('escapes raw html and drops unsafe links', function (): void {
    $html = (new SectionHtmlRendererService)->render("## X\n\n<script>alert(1)</script>\n\n[click](javascript:alert(1)) and ![x](data:text/html;base64,AAAA)");

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('data:text/html');
});

it('renders nothing for an empty section', function (): void {
    expect((new SectionHtmlRendererService)->render(null))->toBe('')
        ->and((new SectionHtmlRendererService)->render("## Only a heading\n"))->toBe('');
});
