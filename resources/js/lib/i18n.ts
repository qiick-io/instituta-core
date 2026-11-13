import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import de from '@/locales/de.json';
import en from '@/locales/en.json';
import it from '@/locales/it.json';

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
 * Initialize (or sync) react-i18next with the active UI locale from the server.
 */
export function initI18n(locale = 'en'): typeof i18n {
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

export default i18n;
