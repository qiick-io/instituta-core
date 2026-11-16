/**
 * Props of the dashboard overview, as `DashboardController` sends them. Every number is
 * inside the signed-in user's perimeter.
 */
export type DashboardContentStats = {
    items_count: number;
    sections_count: number;
    links_count: number;
    areas_covered: number;
    areas_total: number;
};

export type DashboardAreaCount = {
    area: string;
    count: number;
};

export type DashboardSource = {
    id: string;
    name: string;
    provider: string;
    sync_status: string;
    last_synced_at: string | null;
    items_count: number;
};

export type DashboardProposal = {
    id: string;
    title: string | null;
    action: string;
    area: string | null;
    created_at: string | null;
};

export type DashboardProposals = {
    open_count: number;
    recent: DashboardProposal[];
};

/** Null when the user is not an owner (no keys page either). */
export type DashboardApiKeys = {
    active_count: number;
    expiring_soon_count: number;
} | null;

export type DashboardActivityEvent = {
    id: number;
    event: string | null;
    description: string;
    log_name: string | null;
    created_at: string | null;
    causer: { id: number; name: string; email: string } | null;
};

/** One problem the brain health check found (#231). */
export type DashboardHealthIssue = {
    code: string;
    severity: 'error' | 'warning';
    item: string | null;
    stable_key: string | null;
    path: string;
    message: string;
    suggested_fix: string;
};

/** Null when the user is not an owner. */
export type DashboardHealth = {
    issues: DashboardHealthIssue[];
    counts: Record<string, number>;
    error_count: number;
    warning_count: number;
} | null;

export type DashboardOverviewMetrics = {
    contentStats: DashboardContentStats;
    areaCounts: DashboardAreaCount[];
    sources: DashboardSource[];
    proposals: DashboardProposals;
    apiKeys: DashboardApiKeys;
    /** Null when the user is not an owner (no activity page either). */
    latestActivity: DashboardActivityEvent[] | null;
    health: DashboardHealth;
};
