<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Data\PerimeterData;
use App\Data\GlobalSearchResultData;
use App\Enums\PermissionEnum;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Command palette server search: brain item titles inside the session perimeter only.
 *
 * Never returns bodies or section text. Empty when there is no perimeter.
 */
final class GlobalSearchService
{
    /** Results per group: enough to pick from, small enough to read at a glance. */
    public const int LIMIT_PER_GROUP = 6;

    public function __construct(
        private readonly SessionPerimeterService $sessionPerimeters = new SessionPerimeterService,
    ) {}

    /**
     * @return array<string, list<GlobalSearchResultData>>
     */
    public function search(User $user, string $query, ?PerimeterData $perimeter): array
    {
        if ($perimeter === null) {
            return [];
        }

        // ponytail: `%` / `_` in the query act as LIKE wildcards (SQLite has no default escape).
        $pattern = '%'.$query.'%';
        $groups = [
            'items' => $this->items($perimeter, $pattern),
            'users' => $user->can(PermissionEnum::CanShowUsers->value)
                ? $this->users($pattern)
                : [],
        ];

        return array_filter($groups, static fn (array $results): bool => $results !== []);
    }

    /**
     * Resolve the UI session perimeter for the signed-in user (or null).
     */
    public function perimeterFor(User $user): ?PerimeterData
    {
        $principal = $this->sessionPerimeters->personPrincipal($user);

        if ($principal === null) {
            return null;
        }

        $scopes = $this->sessionPerimeters->visibleScopes($principal);

        return $this->sessionPerimeters->resolve(request(), $principal, $scopes);
    }

    /**
     * @return list<GlobalSearchResultData>
     */
    private function items(PerimeterData $perimeter, string $pattern): array
    {
        $likeOperator = $this->caseInsensitiveLikeOperator();

        return array_values(Item::query()
            ->withinPerimeter($perimeter)
            ->where(function (Builder $query) use ($pattern, $likeOperator): void {
                $query->where('title', $likeOperator, $pattern)
                    ->orWhere('stable_key', $likeOperator, $pattern)
                    ->orWhere('path', $likeOperator, $pattern);
            })
            ->orderBy('title')
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'title', 'area', 'stable_key'])
            ->map(static fn (Item $item): GlobalSearchResultData => new GlobalSearchResultData(
                id: 'item:'.$item->id,
                label: $item->title,
                description: $item->area,
                url: route('explorer.show', ['item' => $item->id], false),
            ))
            ->all());
    }

    /**
     * @return list<GlobalSearchResultData>
     */
    private function users(string $pattern): array
    {
        $likeOperator = $this->caseInsensitiveLikeOperator();

        return array_values(User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($pattern, $likeOperator): void {
                $query->where('first_name', $likeOperator, $pattern)
                    ->orWhere('last_name', $likeOperator, $pattern)
                    ->orWhere('email', $likeOperator, $pattern);
            })
            ->orderBy('first_name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(static fn (User $foundUser): GlobalSearchResultData => new GlobalSearchResultData(
                id: 'user:'.$foundUser->id,
                label: $foundUser->name,
                description: $foundUser->email,
                url: route('users.index', absolute: false),
            ))
            ->all());
    }

    /**
     * PostgreSQL `LIKE` is case-sensitive; SQLite/MySQL treat ASCII case-insensitively.
     */
    private function caseInsensitiveLikeOperator(): string
    {
        return Item::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
