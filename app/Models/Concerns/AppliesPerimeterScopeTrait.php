<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single query scope that applies PerimeterData to models with scope columns: the scope, the
 * visibility ceiling, and the area and path limits.
 *
 * @method static Builder<static> withinPerimeter(PerimeterData $perimeter)
 */
trait AppliesPerimeterScopeTrait
{
    /**
     * @var array<string, int>
     */
    private static array $visibilityRank = [
        'public' => 0,
        'internal' => 1,
        'restricted' => 2,
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithinPerimeter(Builder $query, PerimeterData $perimeter): Builder
    {
        $query->where(function (Builder $builder) use ($perimeter): void {
            match ($perimeter->scopeType) {
                ScopeTypeEnum::Organisation => $builder->where(
                    $builder->qualifyColumn('organisation_id'),
                    $perimeter->scopeId,
                ),
                ScopeTypeEnum::Company => $builder->where(
                    $builder->qualifyColumn('company_id'),
                    $perimeter->scopeId,
                ),
                ScopeTypeEnum::Product => $builder->where(
                    $builder->qualifyColumn('product_id'),
                    $perimeter->scopeId,
                ),
            };
        });

        $allowed = array_values(array_filter(
            VisibilityEnum::cases(),
            static fn (VisibilityEnum $case): bool => self::$visibilityRank[$case->value]
                <= self::$visibilityRank[$perimeter->maxVisibility->value],
        ));

        $query->whereIn(
            $query->qualifyColumn('visibility'),
            array_map(static fn (VisibilityEnum $case): string => $case->value, $allowed),
        );

        // Area and path limits (#334): the same rule as PerimeterData::allowsLocation().
        if ($perimeter->areas !== []) {
            $query->whereIn($query->qualifyColumn('area'), $perimeter->areas);
        }

        if ($perimeter->pathPrefixes !== []) {
            self::wherePathWithin($query, $perimeter->pathPrefixes);
        }

        return $query;
    }

    /**
     * Rows whose `path` is one of the folders or inside one. Shared with the rows that store a path
     * without the item columns (proposals). The SQL names the column unqualified: no query of the
     * app joins a second table with a `path`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $pathPrefixes
     */
    public static function wherePathWithin(Builder $query, array $pathPrefixes): void
    {
        $query->where(function (Builder $builder) use ($pathPrefixes): void {
            foreach ($pathPrefixes as $pathPrefix) {
                // A prefix comparison instead of LIKE, where `_` and `%` in a folder name would match anything.
                $builder->orWhere('path', $pathPrefix)
                    ->orWhereRaw('substr(path, 1, ?) = ?', [mb_strlen($pathPrefix) + 1, $pathPrefix.'/']);
            }
        });
    }
}
