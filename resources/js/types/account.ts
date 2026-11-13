/** A signed-in browser of the current user (Settings → Sessions). */
export type BrowserSession = {
    publicId: string;
    ipAddress: string | null;
    browser: string;
    platform: string;
    isCurrentDevice: boolean;
    /** Unix seconds. */
    lastActiveAt: number;
};
