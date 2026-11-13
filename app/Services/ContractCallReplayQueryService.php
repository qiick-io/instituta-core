<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContractCall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Owner-scoped contract_calls listing for session replay (no content or secrets).
 */
final class ContractCallReplayQueryService
{
    public function __construct(
        private readonly ActivityLogQueryService $activityLogs,
    ) {}

    public function organisationIdForOwner(User $user): ?string
    {
        return $this->activityLogs->organisationIdForOwner($user);
    }

    /**
     * @return array{
     *     search: string,
     *     principal_id: string,
     *     api_key_id: string,
     *     agent_session_id: string,
     *     tool_or_endpoint: string,
     *     date_from: string,
     *     date_to: string
     * }
     */
    public function resolvedFilters(Request $request): array
    {
        return [
            'search' => trim((string) $request->string('search')),
            'principal_id' => trim((string) $request->string('principal_id')),
            'api_key_id' => trim((string) $request->string('api_key_id')),
            'agent_session_id' => trim((string) $request->string('agent_session_id')),
            'tool_or_endpoint' => trim((string) $request->string('tool_or_endpoint')),
            'date_from' => trim((string) $request->string('date_from')),
            'date_to' => trim((string) $request->string('date_to')),
        ];
    }

    /**
     * @param  array{
     *     search: string,
     *     principal_id: string,
     *     api_key_id: string,
     *     agent_session_id: string,
     *     tool_or_endpoint: string,
     *     date_from: string,
     *     date_to: string
     * }  $filters
     * @return LengthAwarePaginator<int, ContractCall>
     */
    public function paginate(string $organisationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ContractCall::query()
            ->where('organisation_id', $organisationId)
            ->with(['principal:id,name', 'apiKey:id,name,display_prefix', 'agentSession:id'])
            ->orderByDesc('called_at');

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * @param  Builder<ContractCall>  $query
     * @param  array{
     *     search: string,
     *     principal_id: string,
     *     api_key_id: string,
     *     agent_session_id: string,
     *     tool_or_endpoint: string,
     *     date_from: string,
     *     date_to: string
     * }  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('tool_or_endpoint', 'like', $term)
                    ->orWhere('scope_id', 'like', $term);
            });
        }

        if ($filters['principal_id'] !== '') {
            $query->where('principal_id', $filters['principal_id']);
        }

        if ($filters['api_key_id'] !== '') {
            $query->where('api_key_id', $filters['api_key_id']);
        }

        if ($filters['agent_session_id'] !== '') {
            $query->where('agent_session_id', $filters['agent_session_id']);
        }

        if ($filters['tool_or_endpoint'] !== '') {
            $query->where('tool_or_endpoint', $filters['tool_or_endpoint']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('called_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('called_at', '<=', $filters['date_to']);
        }
    }
}
