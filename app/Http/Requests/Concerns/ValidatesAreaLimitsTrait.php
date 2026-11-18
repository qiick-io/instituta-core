<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * The area and path limits (#334) a group's brain access or an API key can carry: areas from a
 * known list, and one folder path inside the brain. Shared by the group and the key forms.
 */
trait ValidatesAreaLimitsTrait
{
    /**
     * A folder: letters, digits, spaces, `.`, `_`, `-` and `/`, never a `..` segment.
     */
    public const PATH_PREFIX_PATTERN = '/^(?!.*(^|\/)\.\.(\/|$))[\pL\pN _.\/-]+$/u';

    /**
     * @param  list<string>  $allowedAreas
     * @return array<string, array<int, mixed>>
     */
    protected static function areaLimitRules(string $areasKey, string $pathPrefixKey, array $allowedAreas): array
    {
        return [
            $areasKey => ['sometimes', 'array'],
            $areasKey.'.*' => ['string', 'distinct', Rule::in($allowedAreas)],
            $pathPrefixKey => ['sometimes', 'nullable', 'string', 'max:255', 'regex:'.self::PATH_PREFIX_PATTERN],
        ];
    }
}
