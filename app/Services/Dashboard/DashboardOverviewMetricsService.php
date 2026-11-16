<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Brain\Data\PerimeterData;
use App\Enums\ProposalStatusEnum;
use App\Http\Resources\ActivityLogResource;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemSection;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ActivityLogQueryService;
use App\Services\BrainKeysService;
use App\Services\BrainMapService;
use App\Services\BrainSourcesService;
use App\Services\ProposalQueryService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers and short lists of the dashboard overview, always inside the signed-in user's
 * perimeter: the same item ACL as the brain screens, so the dashboard never counts what the
 * user could not open. Keys and activity are owner-only, like their own pages.
 *
 * Shaped after externa-core `DashboardOverviewMetricsService`; the domain is Instituta's.
 */
final class DashboardOverviewMetricsService
{
    /** Rows in each short list (proposals, activity). */
    private const int RECENT_LIMIT = 5;

    /** An active key expiring within this many days counts as "expiring soon". */
    private const int KEY_EXPIRY_WARNING_DAYS = 30;

    public function __construct(
        private readonly BrainMapService $map,
        private readonly BrainSourcesService $sources,
        private readonly ProposalQueryService $proposals,
        private readonly BrainKeysService $keys,
        private readonly ActivityLogQueryService $activity,
    ) {}

    /**
     * Indexed content in the perimeter.
     *
     * @return array{items_count: int, sections_count: int, links_count: int, areas_covered: int, areas_total: int}
     */
    public function contentStats(PerimeterData $perimeter): array
    {
        $itemIds = Item::query()->withinPerimeter($perimeter)->select('id');
        $areas = $this->map->areas($perimeter);

        return [
            'items_count' => Item::query()->withinPerimeter($perimeter)->count(),
            'sections_count' => ItemSection::query()->whereIn('item_id', $itemIds)->count(),
            'links_count' => ItemLink::query()->whereIn('from_item_id', $itemIds)->count(),
            'areas_covered' => count(array_filter($areas, fn (array $area): bool => ! $area['empty'])),
            'areas_total' => count($areas),
        ];
    }

    /**
     * Areas that hold content, largest first (the empty ones are the map's gaps).
     *
     * @return list<array{area: string, count: int}>
     */
    public function areaCounts(PerimeterData $perimeter): array
    {
        $areas = array_values(array_filter($this->map->areas($perimeter), fn (array $area): bool => ! $area['empty']));
        usort($areas, fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return array_map(fn (array $area): array => ['area' => $area['area'], 'count' => $area['count']], $areas);
    }

    /**
     * Sources with their sync state and how many perimeter items each one indexed.
     *
     * @return list<array{id: string, name: string, provider: string, sync_status: string, last_synced_at: string|null, items_count: int}>
     */
    public function sources(PerimeterData $perimeter): array
    {
        $itemCountsBySourceId = Item::query()
            ->withinPerimeter($perimeter)
            ->select('source_id', DB::raw('count(*) as aggregate'))
            ->groupBy('source_id')
            ->pluck('aggregate', 'source_id');

        return array_map(fn (array $source): array => [
            'id' => (string) $source['id'],
            'name' => (string) $source['name'],
            'provider' => (string) $source['provider'],
            'sync_status' => (string) $source['sync_status'],
            'last_synced_at' => is_string($source['last_synced_at']) ? $source['last_synced_at'] : null,
            'items_count' => (int) ($itemCountsBySourceId[$source['id']] ?? 0),
        ], $this->sources->list($perimeter));
    }

    /**
     * Open write proposals waiting for a decision, newest first.
     *
     * @return array{open_count: int, recent: list<array{id: string, title: string|null, action: string, area: string|null, created_at: string|null}>}
     */
    public function openProposals(PerimeterData $perimeter): array
    {
        $openProposals = $this->proposals
            ->modelsInPerimeter($perimeter, ProposalStatusEnum::Open)
            ->sortByDesc('created_at')
            ->values();

        return [
            'open_count' => $openProposals->count(),
            'recent' => array_values($openProposals
                ->take(self::RECENT_LIMIT)
                ->map(fn (Proposal $proposal): array => [
                    'id' => $proposal->id,
                    'title' => $proposal->title,
                    'action' => $proposal->action->value,
                    'area' => $proposal->area,
                    'created_at' => $proposal->created_at?->toIso8601String(),
                ])
                ->all()),
        ];
    }

    /**
     * Active API keys of the user's principal, and how many expire soon. Null for non-owners,
     * who cannot see the keys page either.
     *
     * @return array{active_count: int, expiring_soon_count: int}|null
     */
    public function apiKeys(User $user, CarbonInterface $now): ?array
    {
        $listing = $this->keys->listFor($user);

        if (! $listing['is_owner']) {
            return null;
        }

        $expiryWarningEnd = $now->copy()->addDays(self::KEY_EXPIRY_WARNING_DAYS);
        $activeCount = 0;
        $expiringSoonCount = 0;

        foreach ($listing['keys'] as $key) {
            $expiresAt = is_string($key['expires_at']) ? Carbon::parse($key['expires_at']) : null;

            if ($key['revoked'] === true || ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($now))) {
                continue;
            }

            $activeCount++;

            if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($expiryWarningEnd)) {
                $expiringSoonCount++;
            }
        }

        return ['active_count' => $activeCount, 'expiring_soon_count' => $expiringSoonCount];
    }

    /**
     * The organisation's latest activity events. Null for non-owners, like the activity page.
     *
     * @return list<array<string, mixed>>|null
     */
    public function latestActivity(User $user): ?array
    {
        $organisationId = $this->activity->organisationIdForOwner($user);

        if ($organisationId === null) {
            return null;
        }

        $events = $this->activity
            ->filteredQuery($organisationId, [
                'search' => '',
                'causer_id' => null,
                'event' => '',
                'log_name' => '',
                'date_from' => '',
                'date_to' => '',
                'subject_type' => '',
                'subject_id' => '',
            ])
            ->limit(self::RECENT_LIMIT)
            ->get();

        /** @var list<array<string, mixed>> $rows */
        $rows = ActivityLogResource::collection($events)->resolve();

        return $rows;
    }
}
