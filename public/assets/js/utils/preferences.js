/**
 * Smart Split – User & Application Preferences Manager
 * Handles default currency, date/number formatting, and interface density.
 */

const STORAGE_KEYS = {
    CURRENCY: 'smartsplit_pref_currency',
    DATE_FORMAT: 'smartsplit_pref_date_format',
    NUMBER_FORMAT: 'smartsplit_pref_number_format',
    DENSITY: 'smartsplit_pref_density',
};

export const SUPPORTED_PREF_CURRENCIES = [
    { code: 'INR', name: 'Indian Rupee', symbol: '₹' },
    { code: 'USD', name: 'US Dollar', symbol: '$' },
    { code: 'EUR', name: 'Euro', symbol: '€' },
    { code: 'GBP', name: 'British Pound', symbol: '£' },
    { code: 'CAD', name: 'Canadian Dollar', symbol: 'CA$' },
    { code: 'AUD', name: 'Australian Dollar', symbol: 'AU$' },
    { code: 'JPY', name: 'Japanese Yen', symbol: '¥' },
    { code: 'CHF', name: 'Swiss Franc', symbol: 'CHF ' },
];

export const SUPPORTED_DATE_FORMATS = [
    { id: 'DD/MM/YYYY', label: 'DD/MM/YYYY', example: '21/09/2026' },
    { id: 'MM/DD/YYYY', label: 'MM/DD/YYYY', example: '09/21/2026' },
    { id: 'YYYY-MM-DD', label: 'YYYY-MM-DD', example: '2026-09-21' },
];

export const SUPPORTED_NUMBER_FORMATS = [
    { id: 'comma_decimal', label: '1,234.56', desc: 'Standard Comma Thousands, Dot Decimal' },
    { id: 'dot_decimal', label: '1.234,56', desc: 'European Dot Thousands, Comma Decimal' },
];

export const SUPPORTED_DENSITIES = [
    { id: 'standard', label: 'Standard Density', desc: 'Editorial spacing, comfortable touch targets' },
    { id: 'compact', label: 'Compact Mode', desc: 'Tightened spacing, higher information density' },
];

export class PreferencesManager {
    /**
     * Get preferred default workspace currency code.
     * @returns {string} E.g., 'INR', 'USD'
     */
    static getDefaultCurrency() {
        try {
            if (typeof localStorage !== 'undefined') {
                const saved = localStorage.getItem(STORAGE_KEYS.CURRENCY);
                if (saved && SUPPORTED_PREF_CURRENCIES.some(c => c.code === saved)) {
                    return saved;
                }
            }
        } catch {}
        return 'INR';
    }

    /**
     * Set preferred default workspace currency code.
     * @param {string} currencyCode
     * @returns {string}
     */
    static setDefaultCurrency(currencyCode) {
        const code = (currencyCode || '').toUpperCase();
        const valid = SUPPORTED_PREF_CURRENCIES.some(c => c.code === code) ? code : 'INR';
        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem(STORAGE_KEYS.CURRENCY, valid);
            }
        } catch {}
        return valid;
    }

    /**
     * Get preferred date format pattern.
     * @returns {string} 'DD/MM/YYYY' | 'MM/DD/YYYY' | 'YYYY-MM-DD'
     */
    static getDateFormat() {
        try {
            if (typeof localStorage !== 'undefined') {
                const saved = localStorage.getItem(STORAGE_KEYS.DATE_FORMAT);
                if (saved && SUPPORTED_DATE_FORMATS.some(f => f.id === saved)) {
                    return saved;
                }
            }
        } catch {}
        return 'DD/MM/YYYY';
    }

    /**
     * Set preferred date format pattern.
     * @param {string} format 'DD/MM/YYYY' | 'MM/DD/YYYY' | 'YYYY-MM-DD'
     * @returns {string}
     */
    static setDateFormat(format) {
        const valid = SUPPORTED_DATE_FORMATS.some(f => f.id === format) ? format : 'DD/MM/YYYY';
        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem(STORAGE_KEYS.DATE_FORMAT, valid);
            }
        } catch {}
        return valid;
    }

    /**
     * Get preferred number format pattern.
     * @returns {string} 'comma_decimal' | 'dot_decimal'
     */
    static getNumberFormat() {
        try {
            if (typeof localStorage !== 'undefined') {
                const saved = localStorage.getItem(STORAGE_KEYS.NUMBER_FORMAT);
                if (saved && SUPPORTED_NUMBER_FORMATS.some(n => n.id === saved)) {
                    return saved;
                }
            }
        } catch {}
        return 'comma_decimal';
    }

    /**
     * Set preferred number format pattern.
     * @param {string} format 'comma_decimal' | 'dot_decimal'
     * @returns {string}
     */
    static setNumberFormat(format) {
        const valid = SUPPORTED_NUMBER_FORMATS.some(n => n.id === format) ? format : 'comma_decimal';
        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem(STORAGE_KEYS.NUMBER_FORMAT, valid);
            }
        } catch {}
        return valid;
    }

    /**
     * Get interface display density.
     * @returns {string} 'standard' | 'compact'
     */
    static getDensity() {
        try {
            if (typeof localStorage !== 'undefined') {
                const saved = localStorage.getItem(STORAGE_KEYS.DENSITY);
                if (saved === 'compact' || saved === 'standard') {
                    return saved;
                }
            }
        } catch {}
        return 'standard';
    }

    /**
     * Set interface display density and apply to DOM.
     * @param {string} density 'standard' | 'compact'
     * @returns {string}
     */
    static setDensity(density) {
        const valid = density === 'compact' ? 'compact' : 'standard';
        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem(STORAGE_KEYS.DENSITY, valid);
            }
        } catch {}
        PreferencesManager.applyDensity(valid);
        return valid;
    }

    /**
     * Apply density class and data-attribute to document body/documentElement.
     * @param {string} density 'standard' | 'compact'
     */
    static applyDensity(density) {
        if (typeof document === 'undefined') return;
        const isCompact = density === 'compact';
        if (document.body) {
            document.body.classList.toggle('density-compact', isCompact);
            document.body.setAttribute('data-density', isCompact ? 'compact' : 'standard');
        }
        if (document.documentElement) {
            document.documentElement.setAttribute('data-density', isCompact ? 'compact' : 'standard');
        }
    }

    /**
     * Initialize preferences on app startup.
     */
    static init() {
        const density = PreferencesManager.getDensity();
        PreferencesManager.applyDensity(density);
    }
}
