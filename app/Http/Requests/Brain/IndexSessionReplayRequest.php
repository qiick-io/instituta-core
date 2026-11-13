<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Http\Requests\Concerns\ValidatesListPerPageTrait;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Trust-boundary validation for the session replay contract_calls list query.
 *
 * `per_page` uses the shared list sizes; identity filters stay soft in
 * ContractCallReplayQueryService.
 */
final class IndexSessionReplayRequest extends FormRequest
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
