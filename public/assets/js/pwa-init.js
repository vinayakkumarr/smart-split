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
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then(function(registration) {
                // Service Worker registered successfully
            })
            .catch(function(err) {
                console.warn('[SW] Service Worker registration failed:', err);
            });
    });
}
