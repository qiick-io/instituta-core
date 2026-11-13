export type User = {
    id: number;
    first_name: string;
    last_name: string | null;
    name: string;
    email: string;
    avatar?: string;
    locale?: string;
    timezone?: string;
    is_active?: boolean;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/** Shared auth context: user, permissions, roles, and super-admin flag. */
export type Auth = {
    user: User | null;
    permissions: string[];
    roleNames: string[];
    isSuperAdmin: boolean;
    canManageSettings?: boolean;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
