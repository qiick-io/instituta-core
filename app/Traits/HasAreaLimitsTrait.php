<?php

declare(strict_types=1);

namespace App\Traits;

use App\Brain\Data\PerimeterData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * The `areas` and `path_prefixes` limits (#334) of a grant, an API key or a user group's brain
 * access, as lists. An empty list means no limit and is stored as NULL, so "unlimited" is one
 * `whereNull` on every database engine instead of a JSON comparison.
 *
 * @property list<string> $areas
 * @property list<string> $path_prefixes
 */
trait HasAreaLimitsTrait
{
    /**
     * @return Attribute<list<string>, list<string>>
     */
    protected function areas(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): array => self::decodeLimitList($value),
            set: static fn (array $value): ?string => self::encodeLimitList(array_map(trim(...), $value)),
        );
    }

    /**
     * @return Attribute<list<string>, list<string>>
     */
    protected function pathPrefixes(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): array => self::decodeLimitList($value),
            set: static fn (array $value): ?string => self::encodeLimitList(array_map(PerimeterData::normalisePath(...), $value)),
        );
    }

    /**
     * Whether the row limits nothing: every area and every path of its scope.
     */
    public function isUnlimited(): bool
    {
        return $this->areas === [] && $this->path_prefixes === [];
    }

    /**
     * Rows that limit nothing, for the organisation-wide owner surfaces (clients, exports, the
     * activity log): an owner limited to some areas must not reach them.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnlimited(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('areas'))->whereNull($query->qualifyColumn('path_prefixes'));
    }

    /**
     * @return list<string>
     */
    private static function decodeLimitList(?string $value): array
    {
        $decoded = $value === null ? null : json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }

    /**
     * @param  array<int, string>  $values
     */
    private static function encodeLimitList(array $values): ?string
    {
        $values = array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));

        return $values === [] ? null : (string) json_encode($values);
    }
}
