<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Services\IndexedContractService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Trust-boundary validation for POST /api/v1/sections (#225): 1 to 20 `{item, anchor}` pairs.
 */
final class GetSectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'list', 'min:1', 'max:'.IndexedContractService::MAX_REQUESTED_SECTIONS],
            'sections.*.item' => ['required', 'string'],
            'sections.*.anchor' => ['required', 'string'],
        ];
    }

    /**
     * @return list<array{item: string, anchor: string}>
     */
    public function requestedSections(): array
    {
        /** @var list<array{item: string, anchor: string}> $sections */
        $sections = array_map(
            static fn (array $section): array => ['item' => (string) $section['item'], 'anchor' => (string) $section['anchor']],
            $this->validated('sections'),
        );

        return $sections;
    }
}
