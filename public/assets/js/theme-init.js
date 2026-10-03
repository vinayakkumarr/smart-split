/**
 * Smart Split – Theme Initializer
 * Evaluated synchronously in <head> to prevent Flash of Unstyled Content (FOUC).
 */
(function() {
    try {
        var stored = localStorage.getItem('smartsplit_theme');
        var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        var theme = stored || (prefersDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'dark' ? '#090B0E' : '#18352B');
    } catch (e) {}
})();
