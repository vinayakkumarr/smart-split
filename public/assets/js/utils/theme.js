/**
 * Smart Split – Production Theme Controller & Dark Mode Persistence Manager
 */

const STORAGE_KEY = 'smartsplit_theme';

const ICONS = {
    sun: `<svg class="theme-toggle-svg theme-toggle-sun" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="3.75"/><path d="M10 2.5v2"/><path d="M10 15.5v2"/><path d="M16.5 6.25l-1.74 1"/><path d="M16.5 13.75l-1.74-1"/><path d="M3.5 6.25l1.74 1"/><path d="M3.5 13.75l1.74-1"/></svg>`,
    moon: `<svg class="theme-toggle-svg theme-toggle-moon" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 12.8A6.5 6.5 0 0 1 7.2 4.8 6.5 6.5 0 1 0 15.2 12.8z"/></svg>`,
};

export class ThemeManager {
    static getSystemTheme() {
        if (typeof window !== 'undefined' && window.matchMedia) {
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return 'light';
    }

    static getSavedTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch {
            return null;
        }
    }

    static getCurrentTheme() {
        const saved = ThemeManager.getSavedTheme();
        if (saved === 'dark' || saved === 'light') {
            return saved;
        }
        return ThemeManager.getSystemTheme();
    }

    static applyTheme(theme, save = false) {
        const targetTheme = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', targetTheme);

        if (save) {
            try {
                localStorage.setItem(STORAGE_KEY, targetTheme);
            } catch {}
        }

        ThemeManager.updateToggleUI(targetTheme);
    }

    static toggleTheme() {
        const current = ThemeManager.getCurrentTheme();
        const next = current === 'dark' ? 'light' : 'dark';
        ThemeManager.applyTheme(next, true);
        return next;
    }

    static updateToggleUI(theme) {
        const btn = document.getElementById('btn-navbar-theme');
        if (!btn) return;

        const isDark = theme === 'dark';
        const iconHtml = isDark ? ICONS.sun : ICONS.moon;
        const iconSpan = btn.querySelector('#theme-toggle-icon');
        if (iconSpan) {
            iconSpan.innerHTML = iconHtml;
        } else {
            btn.innerHTML = `<span class="theme-toggle-icon-wrap" id="theme-toggle-icon" aria-hidden="true">${iconHtml}</span>`;
        }

        const title = isDark ? 'Switch to Light Theme' : 'Switch to Dark Theme';
        btn.setAttribute('title', title);
        btn.setAttribute('aria-label', title);
    }

    static init() {
        const current = ThemeManager.getCurrentTheme();
        ThemeManager.applyTheme(current, false);

        // Listen for OS system theme changes if user hasn't explicitly set preference
        if (typeof window !== 'undefined' && window.matchMedia) {
            const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            mediaQuery.addEventListener('change', (e) => {
                if (!ThemeManager.getSavedTheme()) {
                    ThemeManager.applyTheme(e.matches ? 'dark' : 'light', false);
                }
            });
        }

        // Attach navbar button listener if present
        const btn = document.getElementById('btn-navbar-theme');
        if (btn && !btn.dataset.themeBound) {
            btn.dataset.themeBound = 'true';
            btn.addEventListener('click', () => {
                ThemeManager.toggleTheme();
            });
        }
    }
}
