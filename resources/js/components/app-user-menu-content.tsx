import { useTranslation } from 'react-i18next';
import type { NavUserData } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { changeUiLocale, uiLocaleOptions } from '@/lib/i18n';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

/**
 * Wires the registry user-menu body to Instituta's locale and appearance hooks.
 * Locale is client-persisted for beta (no locale route yet).
 */
export function AppUserMenuContent({ user }: { user: NavUserData }) {
    const cleanup = useMobileNavigation();
    const { i18n } = useTranslation();
    const { appearance, updateAppearance } = useAppearance();

    return (
        <UserMenuContent
            user={user}
            settingsHref={editProfile()}
            logoutHref={logout()}
            onNavigate={cleanup}
            locale={{
                current: i18n.language || 'en',
                options: uiLocaleOptions,
                onChange: changeUiLocale,
            }}
            appearance={{
                value: appearance,
                onChange: updateAppearance,
            }}
        />
    );
}
