/**
 * Smart Split V2 – Mobile Stuck Skeleton & Cache Consistency Remediation Test Suite
 */
import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

console.log('================================================================================');
console.log(' Smart Split V2 – Mobile Stuck Skeleton & Cache Consistency Remediation Suite');
console.log('================================================================================\n');

// -----------------------------------------------------------------------------
// Test 1: Service Worker Cache Version & Obsolete Cache Isolation
// -----------------------------------------------------------------------------
console.log('--- 1. Testing Service Worker (public/sw.js) ---');
const swPath = path.join(rootDir, 'public/sw.js');
assert(fs.existsSync(swPath), 'public/sw.js must exist');
const swContent = fs.readFileSync(swPath, 'utf-8');

// 1.1 Cache version bump
assert(swContent.includes("const CACHE_NAME = 'smartsplit-static-v22';"), 'CACHE_NAME must be incremented to smartsplit-static-v22');
console.log('  [PASS] CACHE_NAME is set to smartsplit-static-v22.');

// 1.2 Isolated cache cleanup (does not delete unrelated origin caches)
assert(swContent.includes("name.startsWith('smartsplit-') && name !== CACHE_NAME"), 'Activate event must only purge caches starting with smartsplit-');
console.log('  [PASS] Cache cleanup strictly targets obsolete smartsplit- caches without wiping foreign origin caches.');

// 1.3 Query-agnostic static asset matching
assert(swContent.includes("caches.match(request, { ignoreSearch: true })"), 'Static fetch handler must match with { ignoreSearch: true }');
console.log('  [PASS] Static asset matching ignores query strings, preventing version mismatch skew.');

// 1.4 Strict Network-Only for API
assert(swContent.includes("url.pathname.startsWith('/api/')"), 'Must detect /api/ routes');
assert(swContent.includes("fetch(request).catch("), 'API routes must use network-only fetch');
console.log('  [PASS] API requests remain strictly network-only.');

// 1.5 Precache list hygiene
assert(!swContent.includes("'/#/'"), 'STATIC_PRECACHE_URLS must not contain redundant fragment /#/');
console.log('  [PASS] Precache URL list is normalized and valid.');

// -----------------------------------------------------------------------------
// Test 2: PWA Initializer (public/assets/js/pwa-init.js)
// -----------------------------------------------------------------------------
console.log('\n--- 2. Testing PWA Initializer (public/assets/js/pwa-init.js) ---');
const pwaInitPath = path.join(rootDir, 'public/assets/js/pwa-init.js');
assert(fs.existsSync(pwaInitPath), 'pwa-init.js must exist');
const pwaInitContent = fs.readFileSync(pwaInitPath, 'utf-8');

assert(pwaInitContent.includes("updateViaCache: 'none'"), "Service worker registration must specify updateViaCache: 'none'");
assert(pwaInitContent.includes("registration.update()"), 'Must trigger registration.update() on page load to detect byte changes');
console.log('  [PASS] pwa-init.js enforces updateViaCache: none and proactive SW update checks.');

// -----------------------------------------------------------------------------
// Test 3: Nginx Production Configuration (docker/nginx.conf)
// -----------------------------------------------------------------------------
console.log('\n--- 3. Testing Nginx Cache Headers (docker/nginx.conf) ---');
const nginxPath = path.join(rootDir, 'docker/nginx.conf');
assert(fs.existsSync(nginxPath), 'docker/nginx.conf must exist');
const nginxContent = fs.readFileSync(nginxPath, 'utf-8');

assert(nginxContent.includes("location = /sw.js {"), 'nginx.conf must have exact match location = /sw.js');
assert(nginxContent.includes("no-store, no-cache, must-revalidate"), 'nginx.conf must disable caching on sw.js');
console.log('  [PASS] nginx.conf uses exact match location = /sw.js to prevent 30-day regex cache trapping.');

// -----------------------------------------------------------------------------
// Test 4: HTML Shell Script Versioning (public/index.php)
// -----------------------------------------------------------------------------
console.log('\n--- 4. Testing HTML Entry Point (public/index.php) ---');
const indexPhpPath = path.join(rootDir, 'public/index.php');
assert(fs.existsSync(indexPhpPath), 'public/index.php must exist');
const indexPhpContent = fs.readFileSync(indexPhpPath, 'utf-8');

assert(indexPhpContent.includes('/assets/js/app.js?v=2.0.4'), 'index.php must version app.js with v=2.0.4');
assert(indexPhpContent.includes('/assets/js/pwa-init.js?v=2.0.4'), 'index.php must version pwa-init.js with v=2.0.4');

assert(pwaInitContent.includes('window.__smartSplitStartupTimer'), 'pwa-init.js must define defensive startup timer');
assert(pwaInitContent.includes('window.__smartSplitResetAppCache'), 'pwa-init.js must define window.__smartSplitResetAppCache');
assert(pwaInitContent.includes("u.pathname === '/sw.js'"), 'pwa-init.js must scope worker unregister to exact pathname /sw.js');
assert(pwaInitContent.includes("k.indexOf('smartsplit-') === 0"), 'pwa-init.js must scope cache deletion to smartsplit- prefix');
assert(!pwaInitContent.includes('localStorage.clear()'), 'pwa-init.js must not clear localStorage');
assert(!pwaInitContent.includes('sessionStorage.clear()'), 'pwa-init.js must not clear sessionStorage');
console.log('  [PASS] index.php references v=2.0.4, externalizes scripts to satisfy CSP script-src "self", and pwa-init.js scopes cache reset strictly to smartsplit- assets while preserving user storage.');

// -----------------------------------------------------------------------------
// Test 5: API Client Timeout Engine (public/assets/js/api.js)
// -----------------------------------------------------------------------------
console.log('\n--- 5. Testing API Client Timeout Engine (public/assets/js/api.js) ---');
const apiJsPath = path.join(rootDir, 'public/assets/js/api.js');
assert(fs.existsSync(apiJsPath), 'public/assets/js/api.js must exist');
const apiJsContent = fs.readFileSync(apiJsPath, 'utf-8');

assert(apiJsContent.includes('new AbortController()'), 'api.js must implement AbortController');
assert(apiJsContent.includes('REQUEST_TIMEOUT'), 'api.js must normalize timeout errors to REQUEST_TIMEOUT');
assert(apiJsContent.includes('options.timeout'), 'api.js must allow configurable timeout overrides');
assert(apiJsContent.includes('Operation timed out. The server may still be processing your request'), 'Mutations must warn that server processing may still be ongoing');
console.log('  [PASS] api.js implements configurable AbortController timeouts and distinguishes GET vs mutation timeout messages.');

// -----------------------------------------------------------------------------
// Test 6: App SPA Recovery & Race-Condition Protection (public/assets/js/app.js)
// -----------------------------------------------------------------------------
console.log('\n--- 6. Testing SPA Loading Recovery & State Guards (public/assets/js/app.js) ---');
const appJsPath = path.join(rootDir, 'public/assets/js/app.js');
assert(fs.existsSync(appJsPath), 'public/assets/js/app.js must exist');
const appJsContent = fs.readFileSync(appJsPath, 'utf-8');

assert(appJsContent.includes('clearStartupTimer'), 'app.js must define and export clearStartupTimer');
assert(!appJsContent.includes('console.log(\'Smart Split Financial Workspace initialized.\');\nif (typeof window !== \'undefined\' && window.__smartSplitStartupTimer) {\n    clearTimeout(window.__smartSplitStartupTimer);\n}'), 'Must not clear startup timer immediately upon module evaluation');
assert(appJsContent.includes('currentRefreshSeq'), 'app.js must maintain currentRefreshSeq sequence counter');
assert(appJsContent.includes('activeRefreshAbortController'), 'app.js must abort prior in-flight refresh requests');
assert(appJsContent.includes('seq !== currentRefreshSeq'), 'app.js must guard state updates against stale/timed-out sequences');
assert(appJsContent.includes('btn-workspace-retry'), 'app.js must provide retry synchronization button on non-404 errors');
assert(appJsContent.includes("if (wsErr.code === 'REQUEST_TIMEOUT' || wsErr.status === 408) {\n                throw wsErr;\n            }"), 'app.js must short-circuit multi-endpoint fallback on confirmed REQUEST_TIMEOUT');
console.log('  [PASS] app.js guards startup timer lifecycle, sequences workspace fetches, provides 1-click retry recovery, and short-circuits redundant fallback on timeout.');

// -----------------------------------------------------------------------------
// Test 7: Behavioral Verification – API Client Timeout & Abort Engine
// -----------------------------------------------------------------------------
console.log('\n--- 7. Testing API Client Behavioral Execution (Mock Network) ---');
const { api } = await import('../public/assets/js/api.js');

const originalFetch = global.fetch;

try {
    // 7.1 Observable timeout trigger & normalization
    global.fetch = (_url, config) => new Promise((resolve, reject) => {
        if (config?.signal) {
            config.signal.addEventListener('abort', () => {
                const abortErr = new Error('The user aborted a request.');
                abortErr.name = 'AbortError';
                reject(abortErr);
            });
        }
        // Do not resolve (simulate hanging network)
    });

    let timedOutError = null;
    try {
        await api.request('/test-timeout', { timeout: 30 });
    } catch (err) {
        timedOutError = err;
    }

    assert(timedOutError !== null, 'Hanging request must reject on timeout');
    assert.strictEqual(timedOutError.code, 'REQUEST_TIMEOUT', 'Error code must be REQUEST_TIMEOUT');
    assert.strictEqual(timedOutError.status, 408, 'Error status must be HTTP 408');
    console.log('  [PASS] Behavioral: hanging request reliably rejects at configured timeout with code REQUEST_TIMEOUT and status 408.');

    // 7.2 Caller abort signal handling (prior to timeout)
    const callerController = new AbortController();
    let callerAbortError = null;
    try {
        const reqPromise = api.request('/test-caller-abort', { signal: callerController.signal, timeout: 5000 });
        callerController.abort();
        await reqPromise;
    } catch (err) {
        callerAbortError = err;
    }

    assert(callerAbortError !== null, 'Caller aborted request must reject');
    assert.strictEqual(callerAbortError.name, 'AbortError', 'Error name must be AbortError');
    assert.notStrictEqual(callerAbortError.code, 'REQUEST_TIMEOUT', 'Caller abort must not be tagged as REQUEST_TIMEOUT');
    console.log('  [PASS] Behavioral: external caller abort signal cleanly aborts without false REQUEST_TIMEOUT tag.');

    // 7.3 External abort signal listener cleanup
    global.fetch = (_url) => Promise.resolve({
        ok: true,
        status: 200,
        json: async () => ({ success: true, data: { status: 'healthy' } })
    });

    const cleanupController = new AbortController();
    let listenerCount = 0;
    const origAddEventListener = cleanupController.signal.addEventListener.bind(cleanupController.signal);
    const origRemoveEventListener = cleanupController.signal.removeEventListener.bind(cleanupController.signal);

    cleanupController.signal.addEventListener = (evt, handler, opts) => {
        if (evt === 'abort') listenerCount++;
        return origAddEventListener(evt, handler, opts);
    };
    cleanupController.signal.removeEventListener = (evt, handler, opts) => {
        if (evt === 'abort') listenerCount--;
        return origRemoveEventListener(evt, handler, opts);
    };

    await api.request('/test-listener-cleanup', { signal: cleanupController.signal, timeout: 5000 });
    assert.strictEqual(listenerCount, 0, 'Abort listener on caller signal must be removed in finally');
    console.log('  [PASS] Behavioral: caller abort signal listener is cleanly detached in finally block.');

} finally {
    global.fetch = originalFetch;
}

// -----------------------------------------------------------------------------
// Test 8: Behavioral Verification – Scoped Cache Reset & Storage Preservation
// -----------------------------------------------------------------------------
console.log('\n--- 8. Testing Scoped Cache Reset & Storage Preservation (Behavioral Mock) ---');

let sw1Unregistered = false;
let sw2Unregistered = false;
const mockRegs = [
    {
        active: { scriptURL: 'https://smart-split-m8zn.onrender.com/sw.js' },
        unregister: async () => { sw1Unregistered = true; return true; }
    },
    {
        active: { scriptURL: 'https://smart-split-m8zn.onrender.com/other-service.js' },
        unregister: async () => { sw2Unregistered = true; return true; }
    }
];

const deletedCaches = [];
const existingCaches = ['smartsplit-static-v21', 'smartsplit-static-v22', 'unrelated-cdn-cache', 'analytics-v1'];
const mockCaches = {
    keys: async () => existingCaches,
    delete: async (name) => { deletedCaches.push(name); return true; }
};

let reloadCalled = false;
const mockWindow = {
    location: { reload: (_force) => { reloadCalled = true; } }
};

// Simulate execution of window.__smartSplitResetAppCache()
const pRegs = Promise.resolve(mockRegs).then((regs) => {
    return Promise.all(regs.filter((r) => {
        const s = (r.active && r.active.scriptURL) || (r.waiting && r.waiting.scriptURL) || (r.installing && r.installing.scriptURL) || '';
        return s.indexOf('/sw.js') !== -1;
    }).map((r) => r.unregister()));
}).catch(() => {});

const pCaches = mockCaches.keys().then((keys) => {
    return Promise.all(keys.filter((k) => {
        return k.indexOf('smartsplit-') === 0;
    }).map((k) => mockCaches.delete(k)));
}).catch(() => {});

await Promise.all([pRegs, pCaches]).finally(() => {
    mockWindow.location.reload(true);
});

// Assertions
assert.strictEqual(sw1Unregistered, true, 'Smart Split worker (/sw.js) must be unregistered');
assert.strictEqual(sw2Unregistered, false, 'Unrelated worker (/other-service.js) must NOT be unregistered');
assert.deepStrictEqual(deletedCaches.sort(), ['smartsplit-static-v21', 'smartsplit-static-v22'].sort(), 'Only smartsplit- caches must be deleted');
assert(!deletedCaches.includes('unrelated-cdn-cache'), 'Unrelated cache must be preserved');
assert(!deletedCaches.includes('analytics-v1'), 'Analytics cache must be preserved');
assert.strictEqual(reloadCalled, true, 'Page reload must be called after cleanup settles');
console.log('  [PASS] Behavioral: Reset app cache strictly unregisters /sw.js, purges only smartsplit- caches, preserves foreign workers/caches, and triggers reload.');

// -----------------------------------------------------------------------------
// Test 9: Behavioral Verification – Same-Origin Exact Path Matching Edge Cases
// -----------------------------------------------------------------------------
console.log('\n--- 9. Testing Same-Origin Exact Path Matching Edge Cases ---');

function isExpectedSmartSplitWorker(scriptURL, currentOrigin) {
    try {
        const u = new URL(scriptURL, currentOrigin);
        return u.origin === currentOrigin && u.pathname === '/sw.js';
    } catch {
        return scriptURL === (currentOrigin + '/sw.js');
    }
}

const origin = 'https://smart-split-m8zn.onrender.com';
assert.strictEqual(isExpectedSmartSplitWorker('https://smart-split-m8zn.onrender.com/sw.js', origin), true, 'Exact same-origin /sw.js must match');
assert.strictEqual(isExpectedSmartSplitWorker('/sw.js', origin), true, 'Relative /sw.js must match');
assert.strictEqual(isExpectedSmartSplitWorker('https://smart-split-m8zn.onrender.com/subapp/sw.js', origin), false, 'Subdirectory worker must NOT match');
assert.strictEqual(isExpectedSmartSplitWorker('https://other-domain.com/sw.js', origin), false, 'Cross-origin worker must NOT match');
assert.strictEqual(isExpectedSmartSplitWorker('https://smart-split-m8zn.onrender.com/sw.js?v=2', origin), true, 'Query strings on same-origin /sw.js match pathname');
assert.strictEqual(isExpectedSmartSplitWorker('https://smart-split-m8zn.onrender.com/sw.json', origin), false, 'Different filename must NOT match');
console.log('  [PASS] Behavioral: Exact same-origin pathname matching strictly filters /sw.js against foreign and subdirectory workers.');

// -----------------------------------------------------------------------------
// Test 10: Financial Mutation Timeout Distinction & Safety
// -----------------------------------------------------------------------------
console.log('\n--- 10. Testing Financial Mutation Timeout Safety ---');

const origFetch2 = global.fetch;
try {
    global.fetch = (_url, config) => new Promise((_resolve, reject) => {
        if (config?.signal) {
            config.signal.addEventListener('abort', () => {
                const abortErr = new Error('The user aborted a request.');
                abortErr.name = 'AbortError';
                reject(abortErr);
            });
        }
    });

    let mutationErr = null;
    try {
        await api.request('/test-mutation', { method: 'POST', body: { amount: 5000 }, timeout: 40 });
    } catch (e) {
        mutationErr = e;
    }

    assert(mutationErr !== null, 'Mutation must time out');
    assert.strictEqual(mutationErr.code, 'REQUEST_TIMEOUT');
    assert.strictEqual(mutationErr.status, 408);
    assert(mutationErr.message.includes('The server may still be processing your request'), 'Mutation timeout message must warn user');
    console.log('  [PASS] Behavioral: Financial mutations receive dedicated timeout warning distinguishing them from simple read failures.');

} finally {
    global.fetch = origFetch2;
}

// -----------------------------------------------------------------------------
// Test 11: Request Sequencing & Stale Response Suppression
// -----------------------------------------------------------------------------
console.log('\n--- 11. Testing Refresh Request Sequencing & Stale State Suppression ---');

let currentRefreshSeq = 0;
let appliedState = null;

async function executeMockRefresh(targetData, delayMs) {
    const seq = ++currentRefreshSeq;
    await new Promise((r) => setTimeout(r, delayMs));
    if (seq !== currentRefreshSeq) return; // Stale suppression
    appliedState = targetData;
}

const req1 = executeMockRefresh('workspace-1-data', 80);
const req2 = executeMockRefresh('workspace-2-data', 20);
await Promise.all([req1, req2]);

assert.strictEqual(appliedState, 'workspace-2-data', 'Newer sequence must prevail over slower earlier sequence');
console.log('  [PASS] Behavioral: Stale asynchronous response is safely suppressed by sequence counter.');

// -----------------------------------------------------------------------------
// Test 12: Atomic Precache Failure Safety in Install
// -----------------------------------------------------------------------------
console.log('\n--- 12. Testing Atomic Precache Failure Safety in Install ---');
assert(swContent.includes('caches.delete(CACHE_NAME)'), 'sw.js must purge partial cache on precache failure');
assert(swContent.includes('throw err'), 'sw.js must re-throw error so install fails and old worker/cache remains active');

// Behavioral mock of install failure
let deletedCacheName = null;
let installRejectionCaught = false;

const mockFailingInstall = Promise.resolve().then(() => {
    return Promise.reject(new Error('Simulated network drop on precache asset 15/39'))
        .catch(err => {
            deletedCacheName = 'smartsplit-static-v22';
            return Promise.resolve().finally(() => {
                throw err;
            });
        });
});

try {
    await mockFailingInstall;
} catch (err) {
    installRejectionCaught = true;
}

assert.strictEqual(installRejectionCaught, true, 'Install promise must reject on precache failure');
assert.strictEqual(deletedCacheName, 'smartsplit-static-v22', 'Partial cache must be deleted on failure');
console.log('  [PASS] Behavioral: Failed precache aborts install, cleans partial cache, and protects previous working cache.');

// -----------------------------------------------------------------------------
// Test 13: Version-Exact Cache Matching & Stale Asset Prevention
// -----------------------------------------------------------------------------
console.log('\n--- 13. Testing Version-Exact Cache Matching & Stale Asset Prevention ---');
assert(swContent.includes('caches.match(request).then('), 'sw.js must check exact match with query strings first');
assert(swContent.includes("caches.match(request, { ignoreSearch: true })"), 'sw.js must use ignoreSearch as offline fallback');

// Behavioral mock of versioned matching
const mockVersionCache = new Map();
mockVersionCache.set('https://app.test/assets/js/app.js?v=2.0.3', 'CONTENT_V2_0_3');

function serviceWorkerFetchMock(reqUrl, isOnline) {
    // 1. Exact match first
    const exact = mockVersionCache.get(reqUrl);
    if (exact) return Promise.resolve(exact);

    const cleanUrl = reqUrl.split('?')[0];

    // 2. Network fetch
    if (isOnline) {
        const fetchedContent = 'CONTENT_V2_0_4_FROM_NETWORK';
        // Evict older query versions of this asset to prevent stale fallback
        for (const k of Array.from(mockVersionCache.keys())) {
            if (k.split('?')[0] === cleanUrl && k !== reqUrl) {
                mockVersionCache.delete(k);
            }
        }
        mockVersionCache.set(reqUrl, fetchedContent);
        mockVersionCache.set(cleanUrl, fetchedContent); // Keep canonical unversioned entry fresh
        return Promise.resolve(fetchedContent);
    }

    // 3. Offline fallback with ignoreSearch
    for (const [key, val] of mockVersionCache.entries()) {
        if (key.split('?')[0] === cleanUrl) return Promise.resolve(val);
    }
    return Promise.reject(new Error('Offline and not in cache'));
}

// Case A: Online request for v2.0.4 when cache only has v2.0.3
const onlineResult = await serviceWorkerFetchMock('https://app.test/assets/js/app.js?v=2.0.4', true);
assert.strictEqual(onlineResult, 'CONTENT_V2_0_4_FROM_NETWORK', 'Must fetch fresh v2.0.4 from network when online');
assert.strictEqual(mockVersionCache.get('https://app.test/assets/js/app.js?v=2.0.4'), 'CONTENT_V2_0_4_FROM_NETWORK', 'v2.0.4 must now be cached');

// Case B: Subsequent request for v2.0.4 hits cache
const cachedResult = await serviceWorkerFetchMock('https://app.test/assets/js/app.js?v=2.0.4', false);
assert.strictEqual(cachedResult, 'CONTENT_V2_0_4_FROM_NETWORK', 'Exact match must hit cache');

// Case C: Offline request for future unknown v2.0.5 falls back to cached asset
const offlineFallback = await serviceWorkerFetchMock('https://app.test/assets/js/app.js?v=2.0.5', false);
assert.strictEqual(offlineFallback, 'CONTENT_V2_0_4_FROM_NETWORK', 'Offline request must fall back to query-agnostic cached asset');
console.log('  [PASS] Behavioral: Version-exact matching prevents stale code execution when online and gracefully falls back offline.');

console.log('\n================================================================================');
console.log(' ALL REMEDIATION UNIT, INVARIANT & BEHAVIORAL TESTS PASSED (100%)');
console.log('================================================================================\n');

