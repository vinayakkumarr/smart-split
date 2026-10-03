/**
 * Smart Split – Step 14 Client Multi-Currency & FX Test Suite
 */

import {
    SUPPORTED_CURRENCIES,
    BENCHMARK_RATES_USD,
    getExchangeRate,
    convertCurrency,
} from '../public/assets/js/utils/currency.js';

import {
    formatCurrency,
    getCurrencySymbol,
    CURRENCY_SYMBOLS,
} from '../public/assets/js/utils/formatters.js';

let passed = 0;
let failed = 0;

function assert(condition, message) {
    if (condition) {
        console.log(`  [PASS] ${message}`);
        passed++;
    } else {
        console.error(`  [FAIL] ${message}`);
        failed++;
    }
}

console.log('\n====================================================================');
console.log(' STEP 14: CLIENT MULTI-CURRENCY & FX ENGINE UNIT TESTS');
console.log('====================================================================\n');

try {
    // 1. Supported currencies count & structure
    assert(Array.isArray(SUPPORTED_CURRENCIES), 'SUPPORTED_CURRENCIES is an array');
    assert(SUPPORTED_CURRENCIES.length === 12, `SUPPORTED_CURRENCIES contains 12 benchmark currencies (found: ${SUPPORTED_CURRENCIES.length})`);

    const inrMeta = SUPPORTED_CURRENCIES.find(c => c.code === 'INR');
    assert(inrMeta && inrMeta.symbol === '₹' && inrMeta.flag === '🇮🇳', 'INR metadata verified');

    const usdMeta = SUPPORTED_CURRENCIES.find(c => c.code === 'USD');
    assert(usdMeta && usdMeta.symbol === '$' && usdMeta.flag === '🇺🇸', 'USD metadata verified');

    const eurMeta = SUPPORTED_CURRENCIES.find(c => c.code === 'EUR');
    assert(eurMeta && eurMeta.symbol === '€' && eurMeta.flag === '🇪🇺', 'EUR metadata verified');

    // 2. Exchange rate calculation tests
    const rateUsdInr = getExchangeRate('USD', 'INR');
    assert(Math.abs(rateUsdInr - 83.50) < 0.001, `getExchangeRate('USD', 'INR') is 83.50 (got: ${rateUsdInr})`);

    const rateInrUsd = getExchangeRate('INR', 'USD');
    assert(Math.abs(rateInrUsd - 0.011976) < 0.0001, `getExchangeRate('INR', 'USD') is ~0.011976 (got: ${rateInrUsd})`);

    const rateSame = getExchangeRate('GBP', 'GBP');
    assert(rateSame === 1.0, `getExchangeRate for same currency is exactly 1.0 (got: ${rateSame})`);

    const rateEurGbp = getExchangeRate('EUR', 'GBP');
    assert(rateEurGbp > 0.8 && rateEurGbp < 0.9, `getExchangeRate('EUR', 'GBP') is within expected cross-range (got: ${rateEurGbp})`);

    // 3. Currency conversion tests
    const conv1 = convertCurrency(10000, 'USD', 'INR', 83.50);
    assert(conv1.convertedCents === 835000, `convertCurrency $100.00 to INR = 835,000 paise (got: ${conv1.convertedCents})`);
    assert(conv1.rate === 83.50, 'convertCurrency preserves rate');

    const conv2 = convertCurrency(835000, 'INR', 'USD', 0.011976);
    assert(conv2.convertedCents === 9999 || conv2.convertedCents === 10000, `convertCurrency ₹8,350.00 to USD ~ 10,000 cents (got: ${conv2.convertedCents})`);

    // 4. Currency symbol and formatting tests
    assert(getCurrencySymbol('USD') === '$', "getCurrencySymbol('USD') returns '$'");
    assert(getCurrencySymbol('EUR') === '€', "getCurrencySymbol('EUR') returns '€'");
    assert(getCurrencySymbol('GBP') === '£', "getCurrencySymbol('GBP') returns '£'");
    assert(getCurrencySymbol('INR') === '₹', "getCurrencySymbol('INR') returns '₹'");
    assert(getCurrencySymbol('CAD') === 'CA$', "getCurrencySymbol('CAD') returns 'CA$'");
    assert(getCurrencySymbol('XYZ') === 'XYZ ', "getCurrencySymbol('XYZ') fallback returns 'XYZ '");

    const formattedUsd = formatCurrency(15000, 'USD');
    assert(formattedUsd.includes('$') && formattedUsd.includes('150.00'), `formatCurrency(15000, 'USD') -> ${formattedUsd}`);

    const formattedEur = formatCurrency(20000, 'EUR');
    assert(formattedEur.includes('€') && formattedEur.includes('200.00'), `formatCurrency(20000, 'EUR') -> ${formattedEur}`);

    const formattedInr = formatCurrency(1260000, 'INR');
    assert(formattedInr.includes('₹') && formattedInr.includes('12,600.00'), `formatCurrency(1260000, 'INR') -> ${formattedInr}`);

    // 5. Multi-Currency expense ledger item filtering simulation
    const mockExpenses = [
        {
            id: 1,
            title: 'Louvre Tickets',
            amount_cents: 1820000,
            original_currency_code: 'EUR',
            original_amount_cents: 20000,
            exchange_rate: 91.00,
            payers: [{ member_name: 'Aditya', amount_paid_cents: 1820000 }],
            splits: [{ member_name: 'Aditya' }, { member_name: 'Bianca' }],
        },
        {
            id: 2,
            title: 'Local Lunch',
            amount_cents: 25000,
            original_currency_code: null,
            original_amount_cents: null,
            exchange_rate: null,
            payers: [{ member_name: 'Bianca', amount_paid_cents: 25000 }],
            splits: [{ member_name: 'Aditya' }, { member_name: 'Bianca' }],
        },
    ];

    const searchEUR = mockExpenses.filter(e => {
        const q = 'eur';
        const origCur = (e.original_currency_code || '').toLowerCase();
        return (e.title.toLowerCase().includes(q) || origCur.includes(q));
    });
    assert(searchEUR.length === 1 && searchEUR[0].id === 1, 'Search query "eur" matches EUR expense by currency code');

    const search200 = mockExpenses.filter(e => {
        const q = '200.00';
        const origAmtStr = e.original_amount_cents ? (e.original_amount_cents / 100).toFixed(2) : '';
        const amountStr = (e.amount_cents / 100).toFixed(2);
        return origAmtStr.includes(q) || amountStr.includes(q);
    });
    assert(search200.length === 1 && search200[0].id === 1, 'Search query "200.00" matches foreign original amount');

} catch (err) {
    console.error('EXCEPTION:', err);
    failed++;
}

console.log('\n--------------------------------------------------------------------');
console.log(` CLIENT STEP 14 SUMMARY: ${passed} Passed, ${failed} Failed`);
console.log('--------------------------------------------------------------------\n');

if (failed > 0) {
    process.exit(1);
}
