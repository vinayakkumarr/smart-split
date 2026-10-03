/**
 * Smart Split – Client Currency & Cross-Exchange Rate Conversion Utilities
 */

export const SUPPORTED_CURRENCIES = [
    { code: 'INR', name: 'Indian Rupee', symbol: '₹', flag: '🇮🇳' },
    { code: 'USD', name: 'US Dollar', symbol: '$', flag: '🇺🇸' },
    { code: 'EUR', name: 'Euro', symbol: '€', flag: '🇪🇺' },
    { code: 'GBP', name: 'British Pound', symbol: '£', flag: '🇬🇧' },
    { code: 'CAD', name: 'Canadian Dollar', symbol: 'CA$', flag: '🇨🇦' },
    { code: 'AUD', name: 'Australian Dollar', symbol: 'AU$', flag: '🇦🇺' },
    { code: 'SGD', name: 'Singapore Dollar', symbol: 'SG$', flag: '🇸🇬' },
    { code: 'AED', name: 'UAE Dirham', symbol: 'AED', flag: '🇦🇪' },
    { code: 'JPY', name: 'Japanese Yen', symbol: '¥', flag: '🇯🇵' },
    { code: 'CHF', name: 'Swiss Franc', symbol: 'CHF', flag: '🇨🇭' },
    { code: 'CNY', name: 'Chinese Yuan', symbol: '¥', flag: '🇨🇳' },
    { code: 'NZD', name: 'New Zealand Dollar', symbol: 'NZ$', flag: '🇳🇿' },
];

export const BENCHMARK_RATES_USD = {
    USD: 1.0,
    INR: 83.50,
    EUR: 0.92,
    GBP: 0.79,
    CAD: 1.36,
    AUD: 1.52,
    SGD: 1.34,
    AED: 3.67,
    JPY: 155.00,
    CHF: 0.90,
    CNY: 7.23,
    NZD: 1.64,
};

/**
 * Calculate exchange rate between two currencies (1 unit of from = X units of to).
 * @param {string} from
 * @param {string} to
 * @returns {number}
 */
export function getExchangeRate(from = 'USD', to = 'INR') {
    const f = (from || 'USD').toUpperCase();
    const t = (to || 'INR').toUpperCase();
    if (f === t) return 1.0;

    const fromRate = BENCHMARK_RATES_USD[f] || 1.0;
    const toRate = BENCHMARK_RATES_USD[t] || 1.0;

    return Number((toRate / fromRate).toFixed(6));
}

/**
 * Convert integer cents between currencies.
 * @param {number} cents
 * @param {string} fromCurrency
 * @param {string} toCurrency
 * @param {number|null} [customRate]
 * @returns {{convertedCents: number, rate: number}}
 */
export function convertCurrency(cents, fromCurrency, toCurrency, customRate = null) {
    const rate = (customRate && customRate > 0) ? customRate : getExchangeRate(fromCurrency, toCurrency);
    const convertedCents = Math.round(cents * rate);
    return { convertedCents, rate };
}
