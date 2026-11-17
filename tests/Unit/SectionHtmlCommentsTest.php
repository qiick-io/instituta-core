<?php

declare(strict_types=1);

use App\Services\SectionHtmlRendererService;

it('hides html comments but keeps them inside code fences', function (): void {
    $markdown = "<!-- qiick-standards:begin. Generated, do not edit. -->\n@AGENTS.md\n\nRead and follow it.\n<!-- qiick-standards:end -->\n\n```html\n<!-- kept as code -->\n```\n";

    $html = (new SectionHtmlRendererService)->render($markdown);

    expect($html)
        ->not->toContain('qiick-standards')
        ->toContain('Read and follow it.')
        ->toContain('&lt;!-- kept as code --&gt;');
});

it('reads generated-block markers with their note', function (): void {
    $markdown = "<!-- qiick-standards:begin. Generated from qiick-io/app-ui standards: do not edit inside this block. -->\ntext\n<!-- qiick-standards:end -->\n\n```\n<!-- other:begin. shown as code -->\n```";

    expect(SectionHtmlRendererService::generatedBlocks($markdown))->toBe([
        ['source' => 'qiick-standards', 'note' => 'Generated from qiick-io/app-ui standards: do not edit inside this block.'],
    ]);
});
