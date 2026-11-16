<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Http\Requests\Brain\Concerns\ValidatesSourceFormTrait;
use App\Models\Source;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An owner edits a source from the Sources page (#201).
 */
class UpdateSourceRequest extends FormRequest
{
    use ValidatesSourceFormTrait;

    public function authorize(): bool
    {
        $source = $this->route('source');

        return $source instanceof Source && (bool) $this->user()?->can('update', $source);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->sourceFormRules();
    }
}
