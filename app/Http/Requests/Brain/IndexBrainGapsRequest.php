<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Enums\GapReportStatusEnum;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Status filter of the Gaps page (#229); unknown or missing falls back to open.
 */
final class IndexBrainGapsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function statusFilter(): GapReportStatusEnum
    {
        return GapReportStatusEnum::tryFrom($this->string('status')->toString()) ?? GapReportStatusEnum::Open;
    }
}
