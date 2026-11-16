<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use App\Brain\Enums\RoleEnum;
use App\Http\Requests\Brain\Concerns\ValidatesSourceFormTrait;
use App\Services\SessionPerimeterService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An owner registers a source from the Sources page (#201).
 */
class StoreSourceRequest extends FormRequest
{
    use ValidatesSourceFormTrait;

    public function authorize(): bool
    {
        return app(SessionPerimeterService::class)->optionalPerimeter($this)?->role === RoleEnum::Owner;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->sourceFormRules();
    }
}
