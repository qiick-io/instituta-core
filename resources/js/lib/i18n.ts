import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import de from '@/locales/de.json';
import en from '@/locales/en.json';
import it from '@/locales/it.json';

const LOCALE_STORAGE_KEY = 'instituta.locale';

/** Locale code → label for the user-menu language submenu. */
export const uiLocaleOptions: Record<string, string> = {
    en: 'English',
    it: 'Italiano',
    de: 'Deutsch',
};

/**
 * Keep `<html lang>` aligned with the active UI locale (BCP 47).
 */
function syncDocumentLanguage(locale: string): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.lang = locale.replace('_', '-');
}

/**
 * Resolve the stored UI locale, falling back to English.
 */
export function resolveStoredLocale(): string {
    if (typeof window === 'undefined') {
        return 'en';
    }

    const stored = window.localStorage.getItem(LOCALE_STORAGE_KEY);

    if (stored !== null && Object.hasOwn(uiLocaleOptions, stored)) {
        return stored;
    }

    return 'en';
}

/**
 * Persist and apply a UI locale on the client (no server round-trip in beta).
 */
export function changeUiLocale(code: string): void {
    if (!Object.hasOwn(uiLocaleOptions, code)) {
        return;
    }

    if (typeof window !== 'undefined') {
        window.localStorage.setItem(LOCALE_STORAGE_KEY, code);
    }

    void i18n.changeLanguage(code).then(() => {
        syncDocumentLanguage(code);
    });
}

/**
 * Initialize (or sync) react-i18next with the active UI locale.
 */
export function initI18n(locale = resolveStoredLocale()): typeof i18n {
    if (!i18n.isInitialized) {
        void i18n.use(initReactI18next).init({
            resources: {
                en: { translation: en },
                it: { translation: it },
                de: { translation: de },
            },
            lng: locale,
            fallbackLng: 'en',
            interpolation: { escapeValue: false },
        });
        i18n.on('languageChanged', syncDocumentLanguage);
    } else if (i18n.language !== locale) {
        void i18n.changeLanguage(locale);
    }

    syncDocumentLanguage(i18n.language || locale);

    return i18n;
}
