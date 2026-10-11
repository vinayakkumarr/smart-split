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

// --- Smart Split V2: Production-Grade Safe Auto-Update & Refresh Coordinator ---
var updateCoordinator = {
    updatePending: false,
    isRefreshing: false,
    inFlightMutations: 0,
    wasControlled: ('serviceWorker' in navigator) ? Boolean(navigator.serviceWorker.controller) : false,

    recordMutationStart: function() {
        this.inFlightMutations++;
    },

    recordMutationEnd: function() {
        if (this.inFlightMutations > 0) {
            this.inFlightMutations--;
        }
        this.checkAndExecutePendingUpdate();
    },

    /**
     * Inspect active barriers and return an array of human-readable diagnostic reasons.
     * @returns {string[]}
     */
    getDeferredReasons: function() {
        var reasons = [];
        if (this.inFlightMutations > 0) {
            reasons.push('IN_FLIGHT_MUTATIONS');
        }
        if (window.SmartSplit && window.SmartSplit.offlineManager && window.SmartSplit.offlineManager.isDraining) {
            reasons.push('OFFLINE_DRAIN_IN_PROGRESS');
        }
        if (window.SmartSplit && window.SmartSplit.store) {
            var state = window.SmartSplit.store.getState();
            if (state && (state.isSyncing || state.draftExpense)) {
                reasons.push('STORE_SYNC_OR_DRAFT');
            }
        }
        var modalOverlay = document.getElementById('modal-overlay');
        if (modalOverlay && modalOverlay.classList.contains('active')) {
            reasons.push('ACTIVE_MODAL');
        }
        if (document.activeElement) {
            var tag = (document.activeElement.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                reasons.push('FOCUSED_INPUT');
            }
        }
        var inputs = document.querySelectorAll('input:not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="reset"]), textarea');
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            if (input.value && input.value.trim() !== '' && input.defaultValue !== input.value) {
                reasons.push('DIRTY_FORM_INPUT');
                break;
            }
        }
        var lastReload = 0;
        try {
            lastReload = parseInt(sessionStorage.getItem('smartsplit_last_sw_reload') || '0', 10);
        } catch (_) {}
        if (Date.now() - lastReload < 10000) {
            reasons.push('DEBOUNCE_WINDOW_ACTIVE');
        }
        return reasons;
    },

    /**
     * Get explicit lifecycle state.
     * @returns {'IDLE'|'PENDING'|'DEFERRED'|'REFRESHING'}
     */
    getState: function() {
        if (this.isRefreshing) return 'REFRESHING';
        if (this.updatePending) {
            return this.isSafeToRefresh() ? 'PENDING' : 'DEFERRED';
        }
        return 'IDLE';
    },

    isSafeToRefresh: function() {
        // 1. Guard: active in-flight network mutation
        if (this.inFlightMutations > 0) return false;

        // 2. Guard: active offline sync / drain in progress
        if (window.SmartSplit && window.SmartSplit.offlineManager && window.SmartSplit.offlineManager.isDraining) {
            return false;
        }

        // 3. Guard: active store ledger sync or active draft expense
        if (window.SmartSplit && window.SmartSplit.store) {
            var state = window.SmartSplit.store.getState();
            if (state && (state.isSyncing || state.draftExpense)) {
                return false;
            }
        }

        // 4. Guard: active modal dialog currently open
        var modalOverlay = document.getElementById('modal-overlay');
        if (modalOverlay && modalOverlay.classList.contains('active')) {
            return false;
        }

        // 5. Guard: active input focus
        if (document.activeElement) {
            var tag = (document.activeElement.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                return false;
            }
        }

        // 6. Guard: dirty form inputs with unsaved user data
        var inputs = document.querySelectorAll('input:not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="reset"]), textarea');
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            if (input.value && input.value.trim() !== '' && input.defaultValue !== input.value) {
                return false;
            }
        }

        return true;
    },

    checkAndExecutePendingUpdate: function() {
        if (!this.updatePending || this.isRefreshing) return;

        if (!this.isSafeToRefresh()) {
            var reasons = this.getDeferredReasons();
            console.log('[SW:Update] Refresh deferred (' + reasons.join(', ') + '). Waiting for safe transition.');
            return;
        }

        // Debounce & reload-loop protection: only reload once per 10s window
        var lastReload = 0;
        try {
            lastReload = parseInt(sessionStorage.getItem('smartsplit_last_sw_reload') || '0', 10);
        } catch (_) {}

        var now = Date.now();
        if (now - lastReload < 10000) {
            console.warn('[SW:Update] Reload loop prevention engaged. Deferring reload.');
            return;
        }

        this.isRefreshing = true;
        try {
            sessionStorage.setItem('smartsplit_last_sw_reload', now.toString());
        } catch (_) {}

        console.log('[SW:Update] Refreshing client to active service worker release.');
        this.executeReload();
    },

    executeReload: function() {
        window.location.reload();
    },

    onControllerChange: function() {
        // Do not reload uncontrolled pages on first install
        if (!this.wasControlled) {
            console.log('[SW:Update] Service worker claimed first-time client. Retaining fresh session.');
            this.wasControlled = true;
            return;
        }

        console.log('[SW:Update] New service worker took control. Queuing client update.');
        this.updatePending = true;
        this.checkAndExecutePendingUpdate();
    }
};

window.__smartSplitUpdateCoordinator = updateCoordinator;

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('controllerchange', function() {
        updateCoordinator.onControllerChange();
    });

    window.addEventListener('hashchange', function() {
        updateCoordinator.checkAndExecutePendingUpdate();
    });

    // Reconsider pending update when focus leaves form inputs
    document.addEventListener('focusout', function() {
        setTimeout(function() {
            updateCoordinator.checkAndExecutePendingUpdate();
        }, 100);
    });

    // Reconsider pending update when tab returns to visible or window gains focus
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            updateCoordinator.checkAndExecutePendingUpdate();
        }
    });

    window.addEventListener('focus', function() {
        updateCoordinator.checkAndExecutePendingUpdate();
    });

    // Reconsider pending update when a form is cleared/reset
    document.addEventListener('reset', function() {
        setTimeout(function() {
            updateCoordinator.checkAndExecutePendingUpdate();
        }, 100);
    });

    // Reconsider pending update when returning online
    window.addEventListener('online', function() {
        setTimeout(function() {
            updateCoordinator.checkAndExecutePendingUpdate();
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (e.target && (e.target.classList.contains('modal-close') || e.target.classList.contains('modal-btn-cancel') || e.target.id === 'modal-overlay')) {
            setTimeout(function() {
                updateCoordinator.checkAndExecutePendingUpdate();
            }, 250);
        }
    });

    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' })
            .then(function(registration) {
                registration.addEventListener('updatefound', function() {
                    var newWorker = registration.installing;
                    if (newWorker) {
                        console.log('[SW:Update] New worker detected in installing state.');
                        newWorker.addEventListener('statechange', function() {
                            console.log('[SW:Update] Worker state changed to:', newWorker.state);
                        });
                    }
                });

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
