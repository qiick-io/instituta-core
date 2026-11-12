import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import de from '@/locales/de.json';
import en from '@/locales/en.json';
import it from '@/locales/it.json';

/**
 * Initialize (or sync) react-i18next with the active UI locale.
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
    } else if (i18n.language !== locale) {
        void i18n.changeLanguage(locale);
    }

    if (typeof document !== 'undefined') {
        document.documentElement.lang = (i18n.language || locale).replace(
            '_',
            '-',
        );
    }

    return i18n;
}
