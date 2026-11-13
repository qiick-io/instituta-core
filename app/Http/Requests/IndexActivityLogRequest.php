<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesListPerPageTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Trust-boundary validation for the organisation activity log list query.
 *
 * `per_page` is resolved through {@see ValidatesListPerPageTrait}; filter
 * allowlists stay soft in ActivityLogQueryService so stale URLs keep working.
 */
final class IndexActivityLogRequest extends FormRequest
{
    use ValidatesListPerPageTrait;

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
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * One of the "Rows per page" sizes; anything else falls back to the default.
     */
    public function perPage(): int
    {
        return $this->validatedListPerPage($this);
    }
}
