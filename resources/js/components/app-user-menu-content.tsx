import { router, usePage } from '@inertiajs/react';
import type { NavUserData } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useAppearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import i18n from '@/lib/i18n';
import { logout } from '@/routes';
import { update as updateLocale } from '@/routes/locale';
import { edit as editProfile } from '@/routes/profile';

/**
 * Wires the registry user-menu body to Instituta's locale and appearance hooks.
 * Locale is persisted on the user (and mirrored in a cookie) via locale.update.
 */
export function AppUserMenuContent({ user }: { user: NavUserData }) {
    const cleanup = useMobileNavigation();
    const { locale, availableLocales } = usePage().props;
    const { appearance, updateAppearance } = useAppearance();

    const changeLocale = (next: string): void => {
        if (next === locale) {
            return;
        }

        router.patch(
            updateLocale.url(),
            { locale: next },
            {
                preserveScroll: true,
                onSuccess: () => {
                    void i18n.changeLanguage(next).then(() => {
                        document.documentElement.lang = next.replace('_', '-');
                    });
                },
            },
        );
    };

    return (
        <UserMenuContent
            user={user}
            settingsHref={editProfile()}
            logoutHref={logout()}
            onNavigate={cleanup}
            locale={{
                current: locale,
                options: availableLocales,
                onChange: changeLocale,
            }}
            appearance={{
                value: appearance,
                onChange: updateAppearance,
            }}
        />
    );
}
