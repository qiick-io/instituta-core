<?php

declare(strict_types=1);

namespace App\Enums;

enum SourceAdapterEnum: string
{
    case Instituta = 'instituta';
    case BasicMemory = 'basic-memory';
    case ShadcnRegistry = 'shadcn-registry';
    case Markdown = 'markdown';
}
