<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\Enums\RoleEnum;
use App\Models\Activity;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Owner-scoped activity log listing with filters.
 */
final class ActivityLogQueryService
{
    /**
     * @return list<string>
     */
    public function logNames(): array
    {
        return ['default', 'auth', 'settings', 'keys', 'ingest', 'sync', 'security', 'tenancy', 'brain', 'ai'];
    }

    public function organisationIdForOwner(User $user): ?string
    {
        $principal = $user->principals()
            ->active()
            ->where('type', 'person')
            ->whereHas('grants', function (Builder $query): void {
                $query->active()
                    // Organisation-wide surfaces: an owner limited to some areas does not reach them (#334).
                    ->unlimited()
                    ->where('role', RoleEnum::Owner->value);
            })
            ->orderBy('id')
            ->first();

        if (! $principal instanceof Principal) {
            return null;
        }

        return $principal->organisation_id;
    }

    /**
     * @return array{
     *     search: string,
     *     causer_id: int|null,
     *     event: string,
     *     log_name: string,
     *     date_from: string,
     *     date_to: string,
     *     subject_type: string,
     *     subject_id: string
     * }
     */
    public function resolvedFilters(Request $request): array
    {
        $search = trim($request->string('search')->toString());
        $event = $request->string('event')->toString();
        $logName = $request->string('log_name')->toString();
        $dateFrom = $request->string('date_from')->toString();
        $dateTo = $request->string('date_to')->toString();
        $subjectType = $request->string('subject_type')->trim()->toString();
        $subjectId = $request->string('subject_id')->trim()->toString();
        $causerId = $request->integer('causer_id') ?: null;

        return [
            'search' => $search,
            'causer_id' => $causerId,
            'event' => FilterableActivityEventsService::isValid($event) ? $event : '',
            'log_name' => in_array($logName, $this->logNames(), true) ? $logName : '',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ];
    }

    /**
     * @param  array{
     *     search: string,
     *     causer_id: int|null,
     *     event: string,
     *     log_name: string,
     *     date_from: string,
     *     date_to: string,
     *     subject_type: string,
     *     subject_id: string
     * }  $filters
     * @return Builder<Activity>
     */
    public function filteredQuery(string $organisationId, array $filters): Builder
    {
        $query = Activity::query()
            ->forOrganisation($organisationId)
            ->with(['causer' => fn ($query) => $query->withTrashed(), 'subject'])
            ->latest('id');

        if ($filters['causer_id'] !== null) {
            $query->where('causer_type', (new User)->getMorphClass())
                ->where('causer_id', $filters['causer_id']);
        }

        if ($filters['event'] !== '') {
            $query->forEvent($filters['event']);
        }

        if ($filters['log_name'] !== '') {
            $query->inLog($filters['log_name']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if ($filters['subject_type'] !== '' && $filters['subject_id'] !== '') {
            $query->where('subject_type', $filters['subject_type'])
                ->where('subject_id', $filters['subject_id']);
        }

        if ($filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('description', 'like', $term)
                    ->orWhere('subject_type', 'like', $term)
                    ->orWhere('event', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * @param  array{
     *     search: string,
     *     causer_id: int|null,
     *     event: string,
     *     log_name: string,
     *     date_from: string,
     *     date_to: string,
     *     subject_type: string,
     *     subject_id: string
     * }  $filters
     * @return LengthAwarePaginator<int, Activity>
     */
    public function paginate(string $organisationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filteredQuery($organisationId, $filters)->paginate($perPage);
    }

    /**
     * Filter options: users who hold a principal in the organisation.
     *
     * @return list<array{id: int|string, name: string, email: string}>
     */
    public function causersForOrganisation(string $organisationId): array
    {
        /** @var list<array{id: int|string, name: string, email: string}> $rows */
        $rows = User::query()
            ->whereHas('principals', function ($query) use ($organisationId): void {
                $query->where('organisation_id', $organisationId);
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(100)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (User $causer): array => [
                'id' => $causer->id,
                'name' => $causer->name,
                'email' => $causer->email,
            ])
            ->values()
            ->all();

        return $rows;
    }
}
