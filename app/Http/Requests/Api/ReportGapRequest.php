<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Data\ReportGapData;
use App\Enums\GapReportKindEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Trust-boundary validation for POST /api/v1/gaps (#229).
 */
final class ReportGapRequest extends FormRequest
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
            'kind' => ['required', 'string', Rule::enum(GapReportKindEnum::class)],
            'summary' => ['required', 'string', 'max:2000'],
            'question' => ['nullable', 'string', 'max:2000'],
            'related' => ['nullable', 'array', 'list', 'max:10'],
            'related.*.item' => ['required', 'string', 'max:512'],
            'related.*.anchor' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The validated report as the action takes it.
     */
    public function toData(): ReportGapData
    {
        $related = [];

        foreach ((array) $this->validated('related', []) as $entry) {
            if (is_array($entry) && is_string($entry['item'] ?? null)) {
                $anchor = $entry['anchor'] ?? null;
                $related[] = ['item' => $entry['item'], 'anchor' => is_string($anchor) && $anchor !== '' ? $anchor : null];
            }
        }

        $question = trim($this->string('question')->toString());

        return new ReportGapData(
            kind: GapReportKindEnum::from($this->string('kind')->toString()),
            summary: trim($this->string('summary')->toString()),
            question: $question === '' ? null : $question,
            related: $related,
        );
    }
}
