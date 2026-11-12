<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Brain\Data\PerimeterData;
use App\Brain\Enums\ScopeTypeEnum;
use App\Brain\Enums\VisibilityEnum;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single query scope that applies PerimeterData to models with scope columns.
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

        if ($perimeter->area !== null && $perimeter->area !== '') {
            $query->where($query->qualifyColumn('area'), $perimeter->area);
        }

        if ($perimeter->pathPrefix !== null && $perimeter->pathPrefix !== '') {
            $prefix = ltrim(str_replace('\\', '/', $perimeter->pathPrefix), '/');
            $query->where(function (Builder $builder) use ($prefix): void {
                $column = $builder->qualifyColumn('path');
                $builder->where($column, $prefix)
                    ->orWhere($column, 'like', $prefix.'/%');
            });
        }

        return $query;
    }
}
