/** Password policies accepted by Settings → Authentication & security. */
export type PasswordPolicy = 'weak' | 'medium' | 'strong';

/** Light/dark/system theme. */
export type DefaultAppearance = 'system' | 'light' | 'dark';

/** Instance settings shared with every page (HandleInertiaRequests). */
export type SharedInstanceSettings = {
    reportBugUrl: string | null;
};

/**
 * Instance settings as edited on the settings pages (SettingsRepositoryService); each page
 * receives a subset. Copied from Signaculum, trimmed for Instituta.
 */
export type InstanceSettingsForm = {
    app_name: string | null;
    description: string | null;
    report_bug_url: string | null;
    activity_retention_days: number;
    default_language: string;
    default_appearance: DefaultAppearance;
    password_policy: PasswordPolicy;
    login_max_attempts: number;
    login_lockout_threshold: number;
    login_lockout_minutes: number;
    registration_enabled: boolean;
    two_factor_required: boolean;
    oidc_enabled: boolean;
    oidc_issuer: string | null;
    oidc_client_id: string | null;
    /** The secret itself never reaches the page, only whether one is stored. */
    has_oidc_client_secret: boolean;
    oidc_button_label: string | null;
    oidc_jit_provisioning: boolean;
    oidc_auto_link: boolean;
    oidc_only: boolean;
    oidc_role_mapping_enabled: boolean;
    oidc_organization: string | null;
};
