<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain\Concerns;

use App\Brain\Enums\VisibilityEnum;
use App\Enums\SourceAdapterEnum;
use App\Enums\SourceProviderEnum;
use Illuminate\Validation\Rule;

/**
 * The source fields shared by create and edit on the Sources page (#201).
 */
trait ValidatesSourceFormTrait
{
    /**
     * @return array<string, mixed>
     */
    protected function sourceFormRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', Rule::enum(SourceProviderEnum::class)],
            'remote_url' => ['nullable', 'required_unless:provider,local', 'string', 'max:2048'],
            'local_path' => ['nullable', 'required_if:provider,local', 'string', 'max:2048'],
            'ref' => ['nullable', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:512'],
            'adapter' => ['required', Rule::enum(SourceAdapterEnum::class)],
            'default_visibility' => ['required', Rule::enum(VisibilityEnum::class)],
            'is_shared' => ['boolean'],
            'sync_interval_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ];
    }
}
