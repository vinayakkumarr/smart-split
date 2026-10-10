/**
 * Smart Split – Progressive Web App (PWA) & Service Worker Initializer
 */
window.deferredPwaPrompt = null;

window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    window.deferredPwaPrompt = e;
    window.dispatchEvent(new CustomEvent('pwa-prompt-available'));
});

window.addEventListener('appinstalled', function() {
    window.deferredPwaPrompt = null;
    window.dispatchEvent(new CustomEvent('pwa-installed'));
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' })
            .then(function(registration) {
                // Proactively check for service-worker updates on every page load
                if (typeof registration.update === 'function') {
                    registration.update().catch(function() {});
                }
            })
            .catch(function(err) {
                console.warn('[SW] Service Worker registration failed:', err);
            });
    });
}

// Defensive Startup Boundary: If modules fail to initialize within 6s, provide 1-click recovery
window.__smartSplitResetAppCache = function() {
    var pRegs = ('serviceWorker' in navigator)
        ? navigator.serviceWorker.getRegistrations().then(function(regs) {
            return Promise.all(regs.filter(function(r) {
                var s = (r.active && r.active.scriptURL) || (r.waiting && r.waiting.scriptURL) || (r.installing && r.installing.scriptURL) || '';
                try {
                    var u = new URL(s, window.location.origin);
                    return u.origin === window.location.origin && u.pathname === '/sw.js';
                } catch (e) {
                    return s === (window.location.origin + '/sw.js');
                }
            }).map(function(r) { return r.unregister(); }));
        }).catch(function() {})
        : Promise.resolve();

    var pCaches = ('caches' in window)
        ? caches.keys().then(function(keys) {
            return Promise.all(keys.filter(function(k) {
                return k.indexOf('smartsplit-') === 0;
            }).map(function(k) { return caches.delete(k); }));
        }).catch(function() {})
        : Promise.resolve();

    Promise.all([pRegs, pCaches]).finally(function() {
        window.location.reload(true);
    });
};

document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'smartsplit-reset-cache-btn') {
        window.__smartSplitResetAppCache();
    }
});

window.__smartSplitStartupTimer = setTimeout(function() {
    var loader = document.getElementById('app-startup-loader');
    if (loader && loader.parentElement) {
        loader.innerHTML = '<div class="panel" style="text-align: center; max-width: 440px; margin: 30px auto; padding: 24px; border: 1px solid var(--border-color, #334155); border-radius: 8px;">' +
            '<p style="font-weight: 700; font-size: 0.95rem; margin-bottom: 8px; color: var(--text-primary, #f8fafc);">Workspace Taking Longer Than Usual</p>' +
            '<p style="color: var(--text-muted, #94a3b8); font-size: 0.8rem; margin-bottom: 16px; line-height: 1.4;">A cached browser asset may need refreshing to sync with the latest ledger engine.</p>' +
            '<div style="display: flex; gap: 8px; justify-content: center;">' +
            '<button type="button" id="smartsplit-reset-cache-btn" class="btn btn-primary btn-sm" onclick="window.__smartSplitResetAppCache();" style="padding: 6px 14px; font-weight: 700; cursor: pointer;">Reload & Refresh Cache</button>' +
            '</div>' +
        '</div>';
    }
}, 6000);
