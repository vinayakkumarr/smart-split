/**
 * Automated Verification Suite for Smart Split Dedicated Settings View & Preferences
 */
import assert from 'assert';
import { SettingsView } from '../public/assets/js/components/SettingsView.js';
import { ThemeManager } from '../public/assets/js/utils/theme.js';
import { PreferencesManager } from '../public/assets/js/utils/preferences.js';
import { formatCurrency, formatDate } from '../public/assets/js/utils/formatters.js';
import { store } from '../public/assets/js/state.js';
import { router } from '../public/assets/js/router.js';

// Setup Mock DOM & LocalStorage
const mockStorage = new Map();
let mockBodyClasses = new Set();
let mockBodyAttributes = new Map();
let mockDocAttributes = new Map();

global.window = {
    localStorage: {
        getItem: (k) => mockStorage.get(k) || null,
        setItem: (k, v) => mockStorage.set(k, String(v)),
        removeItem: (k) => mockStorage.delete(k),
        clear: () => mockStorage.clear(),
        get length() { return mockStorage.size; },
        key: (i) => Array.from(mockStorage.keys())[i] || null,
    },
    location: { href: 'http://localhost/#/settings', hash: '#/settings' },
    matchMedia: (query) => ({
        matches: false,
        addEventListener: () => {},
    }),
};
global.localStorage = global.window.localStorage;
global.document = {
    createElement: (tag) => ({
        tag,
        style: {},
        innerHTML: '',
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener: () => {},
        appendChild: () => {},
        remove: () => {},
        setAttribute: () => {},
        getAttribute: () => null,
    }),
    querySelector: () => null,
    querySelectorAll: () => [],
    getElementById: () => null,
    documentElement: {
        setAttribute: (k, v) => mockDocAttributes.set(k, v),
        getAttribute: (k) => mockDocAttributes.get(k) || null,
    },
    body: {
        classList: {
            toggle: (cls, force) => {
                if (force === undefined) {
                    mockBodyClasses.has(cls) ? mockBodyClasses.delete(cls) : mockBodyClasses.add(cls);
                } else if (force) {
                    mockBodyClasses.add(cls);
                } else {
                    mockBodyClasses.delete(cls);
                }
            },
            contains: (cls) => mockBodyClasses.has(cls),
        },
        setAttribute: (k, v) => mockBodyAttributes.set(k, v),
        getAttribute: (k) => mockBodyAttributes.get(k) || null,
        appendChild: () => {},
    },
};

console.log('=== Running Settings View Automated Verification ===\n');

// --- Test 1: Verify Cache Statistics Calculation ---
console.log('--- Test 1: Verify Cache Statistics Calculation ---');
mockStorage.clear();
mockStorage.set('smartsplit_theme', 'dark');
mockStorage.set('smartsplit_pref_currency', 'USD');
mockStorage.set('smartsplit_pref_density', 'compact');
mockStorage.set('smartsplit_workspaces', JSON.stringify([{ token: 'ws_alpha', name: 'Trip' }, { token: 'ws_beta', name: 'Apartment' }]));
mockStorage.set('smartsplit_cache_ws_alpha', JSON.stringify({ group: { name: 'Trip' }, expenses: [] }));
mockStorage.set('smartsplit_budget_ws_alpha', '50000');
mockStorage.set('smartsplit_avatars_ws_alpha', JSON.stringify({ 1: { emoji: '🦉', paletteId: 'sage' } }));

const stats = SettingsView.getLocalCacheStats();
assert.strictEqual(stats.workspaceCount, 1, 'Should find 1 cached workspace snapshot');
assert.strictEqual(stats.recentCount, 2, 'Should find 2 recent workspaces in history');
assert.strictEqual(stats.totalKeys, 4, 'Should count 4 Smart Split data keys');
assert(stats.approxKb >= 0, 'Approximate KB must be non-negative');
console.log('✓ Cache stats computed accurately:', stats);

// --- Test 2: Verify Selective Cache Purge Safety ---
console.log('\n--- Test 2: Verify Selective Cache Purge Safety ---');
const removedCount = SettingsView.clearLocalCache();
assert.strictEqual(removedCount, 4, 'Should remove 4 cache keys');
assert.strictEqual(mockStorage.get('smartsplit_theme'), 'dark', 'Theme preference MUST be preserved');
assert.strictEqual(mockStorage.get('smartsplit_pref_currency'), 'USD', 'Currency preference MUST be preserved');
assert.strictEqual(mockStorage.get('smartsplit_pref_density'), 'compact', 'Density preference MUST be preserved');
assert.strictEqual(mockStorage.has('smartsplit_cache_ws_alpha'), false, 'Workspace cache must be removed');
assert.strictEqual(mockStorage.has('smartsplit_workspaces'), false, 'Recent workspaces cache must be removed');
console.log('✓ Selective cache purge safely cleared only cache keys and preserved theme & preference settings.');

// --- Test 3: Verify In-Place Profile & Avatar Customizer in Authenticated Settings ---
console.log('\n--- Test 3: Verify In-Place Profile & Avatar Customizer ---');
store.setState({
    currentUser: {
        id: 42,
        email: 'alex@smartsplit.io',
        display_name: 'Alex Mercer',
        avatar_emoji: '🛡️',
        avatar_color: '#3730a3',
    },
    isAuthenticated: true,
});

const mockContainer = {
    innerHTML: '',
    querySelector: (sel) => ({
        addEventListener: () => {},
        setAttribute: () => {},
        style: {},
    }),
    querySelectorAll: () => [],
};

SettingsView.render(mockContainer);
assert(mockContainer.innerHTML.includes('Alex Mercer'), 'Rendered HTML must contain user display name');
assert(mockContainer.innerHTML.includes('alex@smartsplit.io'), 'Rendered HTML must contain user email');
assert(mockContainer.innerHTML.includes('Cloud ID #42'), 'Rendered HTML must contain Cloud ID badge');
assert(mockContainer.innerHTML.includes('settings-avatar-preview'), 'Rendered HTML must contain live avatar preview badge');
assert(mockContainer.innerHTML.includes('settings-profile-name'), 'Rendered HTML must contain display name input');
assert(mockContainer.innerHTML.includes('settings-emoji-btn'), 'Rendered HTML must contain avatar emoji picker buttons');
assert(mockContainer.innerHTML.includes('settings-color-btn'), 'Rendered HTML must contain avatar color picker buttons');
assert(mockContainer.innerHTML.includes('btn-settings-save-profile'), 'Rendered HTML must contain save profile button');
console.log('✓ In-Place Profile & Avatar Customizer layout verified successfully.');

// --- Test 4: Verify Default Currency Preference Manager ---
console.log('\n--- Test 4: Verify Default Currency Preference Manager ---');
mockStorage.clear();
assert.strictEqual(PreferencesManager.getDefaultCurrency(), 'INR', 'Default fallback currency must be INR');
PreferencesManager.setDefaultCurrency('EUR');
assert.strictEqual(PreferencesManager.getDefaultCurrency(), 'EUR', 'Should set and return EUR');
assert.strictEqual(mockStorage.get('smartsplit_pref_currency'), 'EUR', 'Should persist EUR to localStorage');
PreferencesManager.setDefaultCurrency('INVALID');
assert.strictEqual(PreferencesManager.getDefaultCurrency(), 'INR', 'Invalid currency must safely fallback to INR');
console.log('✓ Default currency preference manager verified successfully.');

// --- Test 5: Verify Date & Number Format Preferences & Formatters Integration ---
console.log('\n--- Test 5: Verify Date & Number Format Preferences ---');
mockStorage.clear();
PreferencesManager.setDateFormat('YYYY-MM-DD');
assert.strictEqual(PreferencesManager.getDateFormat(), 'YYYY-MM-DD');
assert.strictEqual(formatDate('2026-09-21'), '2026-09-21', 'formatDate must respect YYYY-MM-DD');

PreferencesManager.setDateFormat('MM/DD/YYYY');
assert.strictEqual(formatDate('2026-09-21'), '09/21/2026', 'formatDate must respect MM/DD/YYYY');

PreferencesManager.setDateFormat('DD/MM/YYYY');
assert.strictEqual(formatDate('2026-09-21'), '21/09/2026', 'formatDate must respect DD/MM/YYYY');

PreferencesManager.setNumberFormat('dot_decimal');
assert.strictEqual(PreferencesManager.getNumberFormat(), 'dot_decimal');
const dotFormatted = formatCurrency(123456, 'EUR');
assert(dotFormatted.includes('1.234,56') || dotFormatted.includes('1.234'), `European formatting: ${dotFormatted}`);

PreferencesManager.setNumberFormat('comma_decimal');
assert.strictEqual(PreferencesManager.getNumberFormat(), 'comma_decimal');
const commaFormatted = formatCurrency(123456, 'USD');
assert(commaFormatted.includes('1,234.56'), `Standard formatting: ${commaFormatted}`);
console.log('✓ Date & Number format preferences and formatters integration verified successfully.');

// --- Test 6: Verify Display Density (Compact Mode) Toggle ---
console.log('\n--- Test 6: Verify Display Density (Compact Mode) Toggle ---');
mockBodyClasses.clear();
mockStorage.clear();

PreferencesManager.setDensity('compact');
assert.strictEqual(PreferencesManager.getDensity(), 'compact');
assert.strictEqual(mockStorage.get('smartsplit_pref_density'), 'compact');
assert.strictEqual(mockBodyClasses.has('density-compact'), true, 'Body must have density-compact class');
assert.strictEqual(mockDocAttributes.get('data-density'), 'compact', 'Document must have data-density=compact');

PreferencesManager.setDensity('standard');
assert.strictEqual(PreferencesManager.getDensity(), 'standard');
assert.strictEqual(mockBodyClasses.has('density-compact'), false, 'Body must not have density-compact class');
assert.strictEqual(mockDocAttributes.get('data-density'), 'standard', 'Document must have data-density=standard');
console.log('✓ Display density (Compact Mode) toggle and DOM integration verified successfully.');

// --- Test 7: Verify Guest Mode Settings Rendering ---
console.log('\n--- Test 7: Verify Guest Mode Settings Rendering ---');
store.setState({
    currentUser: null,
    isAuthenticated: false,
});

SettingsView.render(mockContainer);
assert(mockContainer.innerHTML.includes('Guest Mode'), 'Rendered HTML must contain Guest Mode badge');
assert(mockContainer.innerHTML.includes('Exploring as Guest'), 'Rendered HTML must contain guest explanation card');
assert(mockContainer.innerHTML.includes('Sign In / Create Account'), 'Rendered HTML must contain sign in CTA');
assert(mockContainer.innerHTML.includes('Appearance &amp; Display Density') || mockContainer.innerHTML.includes('Appearance & Display Density'), 'Rendered HTML must contain theme & density controls for guest');
assert(mockContainer.innerHTML.includes('Regional &amp; Formatting Preferences') || mockContainer.innerHTML.includes('Regional & Formatting Preferences'), 'Rendered HTML must contain regional preferences for guest');
assert(mockContainer.innerHTML.includes('Global Keyboard Shortcuts'), 'Rendered HTML must contain keyboard shortcuts for guest');
assert(!mockContainer.innerHTML.includes('Permanently Delete Account'), 'Guest view must NOT show account deletion');
console.log('✓ Guest Mode Settings layout verified successfully.');

// --- Test 8: Verify Router Resolution for /settings ---
console.log('\n--- Test 8: Verify Router Resolution for /settings ---');
let routeResolved = false;
router.on('/settings', () => {
    routeResolved = true;
    store.setState({ activeView: 'settings', currentGroup: null });
});

router.resolve();
assert.strictEqual(routeResolved, true, 'Router must resolve /settings successfully');
assert.strictEqual(store.getState().activeView, 'settings', 'Active view must be set to settings');
console.log('✓ Router resolution for /settings verified successfully.');

console.log('\n=================================================');
console.log(' ALL SETTINGS VIEW TESTS PASSED SUCCESSFULLY! (8/8)');
console.log('=================================================');
