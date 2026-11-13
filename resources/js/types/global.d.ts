import type { ProjectAppearance } from '@/types/appearance';
import type { Auth } from '@/types/auth';
import type { SharedInstanceSettings } from '@/types/instance-settings';
import type { InstitutaShared } from '@/types/instituta';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            appVersion: string;
            auth: Auth;
            locale: string;
            availableLocales: Record<string, string>;
            sidebarOpen: boolean;
            instituta: InstitutaShared;
            instanceSettings: SharedInstanceSettings;
            projectAppearance: ProjectAppearance;
            [key: string]: unknown;
        };
    }
}
