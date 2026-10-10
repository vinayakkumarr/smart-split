/**
 * Smart Split – Progressive Web App (PWA) Service Worker
 * 
 * Strategy:
 * - Static Assets (CSS, JS, SVG, Fonts, Shell): Cache-First with Background Revalidation
 * - API Endpoints (/api/*): Network-Only to guarantee fresh real-time financial ledger state
 * - Mutations (POST, PUT, DELETE): Unintercepted native network pass-through
 */

const CACHE_NAME = 'smartsplit-static-v22';

const STATIC_PRECACHE_URLS = [
    '/',
    '/manifest.json',
    '/favicon.svg',
    '/assets/css/brand.css',
    '/assets/css/style.css',
    '/assets/css/variables.css',
    '/assets/css/layout.css',
    '/assets/css/components.css',
    '/assets/css/print.css',
    '/assets/js/app.js',
    '/assets/js/api.js',
    '/assets/js/router.js',
    '/assets/js/state.js',
    '/assets/js/utils/formatters.js',
    '/assets/js/utils/math.js',
    '/assets/js/utils/qrcode.js',
    '/assets/js/utils/currency.js',
    '/assets/js/utils/theme.js',
    '/assets/js/utils/preferences.js',
    '/assets/js/utils/icons.js',
    '/assets/js/utils/offline.js',
    '/assets/js/components/Modal.js',
    '/assets/js/components/Toast.js',
    '/assets/js/components/LandingView.js',
    '/assets/js/components/SettingsView.js',
    '/assets/js/components/GroupHeader.js',
    '/assets/js/components/ExpenseList.js',
    '/assets/js/components/ExpenseModal.js',
    '/assets/js/components/MemberList.js',
    '/assets/js/components/BalanceSummary.js',
    '/assets/js/components/SettlementPlan.js',
    '/assets/js/components/ActivityTimeline.js',
    '/assets/js/components/AuthModal.js',
    '/assets/js/components/Footer.js',
    '/assets/js/components/ReportModal.js',
    '/assets/js/components/FailedMutationsModal.js',
    '/assets/js/theme-init.js',
    '/assets/js/pwa-init.js',
    '/assets/js/components/ReceiptLightbox.js',
];

// 1. Install Event – Pre-cache core shell & assets atomically
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_PRECACHE_URLS))
            .catch((err) => {
                console.warn('[SW] Pre-cache failed, aborting install and purging partial cache:', err);
                return caches.delete(CACHE_NAME).finally(() => {
                    throw err; // Re-throw to fail install and keep previous working cache/worker active
                });
            })
            .then(() => self.skipWaiting())
    );
});

// 2. Activate Event – Clean up obsolete Smart Split caches & take control immediately
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((name) => {
                        if (name.startsWith('smartsplit-') && name !== CACHE_NAME) {
                            return caches.delete(name);
                        }
                    })
                );
            })
            .then(() => self.clients.claim())
    );
});

// 3. Fetch Event – Cache-First for static assets, Network-Only for API & mutations
self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Strict Constraint: Only handle GET requests. Never intercept or cache POST/PUT/DELETE
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Dynamic API Endpoints: Network-Only to ensure fresh, zero-drift financial data
    if (url.pathname.startsWith('/api/')) {
        event.respondWith(
            fetch(request).catch((err) => {
                return new Response(JSON.stringify({
                    success: false,
                    error: { code: 'NETWORK_OFFLINE', message: 'You are currently offline. Check your network connection.' }
                }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json' }
                });
            })
        );
        return;
    }

    // Static Assets & App Shell: Exact-Match Cache-First with Offline Query-Agnostic Fallback
    event.respondWith(
        caches.match(request).then((cachedResponse) => {
            if (cachedResponse) {
                // Background revalidation to refresh cache
                fetch(request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
                        const responseClone = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(request, responseClone);
                            if (url.search) {
                                cache.put(url.pathname, networkResponse.clone());
                            }
                        });
                    }
                }).catch(() => {
                    // Offline - continue using cached copy
                });

                return cachedResponse;
            }

            // Version query mismatch or not in cache: fetch fresh from network
            return fetch(request).then((networkResponse) => {
                if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== 'basic') {
                    return networkResponse;
                }

                const responseClone = networkResponse.clone();
                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(request, responseClone);
                    if (url.search) {
                        cache.put(url.pathname, networkResponse.clone());
                    }
                });

                return networkResponse;
            }).catch(() => {
                // Offline fallback: try query-agnostic cached asset
                return caches.match(request, { ignoreSearch: true }).then((fallbackResponse) => {
                    if (fallbackResponse) {
                        return fallbackResponse;
                    }
                    if (request.mode === 'navigate') {
                        return caches.match('/', { ignoreSearch: true });
                    }
                });
            });
        })
    );
});
