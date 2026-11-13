<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One command-palette search hit: a record the user may open (title only, never bodies).
 */
final readonly class GlobalSearchResultData
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $description,
        public string $url,
    ) {}

    /**
     * @return array{id: string, label: string, description: string|null, url: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'url' => $this->url,
        ];
    }
}
