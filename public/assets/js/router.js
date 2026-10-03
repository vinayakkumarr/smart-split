/**
 * Smart Split – Client-Side Hash Router
 */

export class Router {
    constructor() {
        this.routes = [];
        this.currentPath = '';
    }

    /**
     * Register a route handler with path pattern.
     * E.g. '', '/g/:token', '/g/:token/ledger/:memberId'
     * @param {string} pattern
     * @param {Function} handler
     * @returns {this}
     */
    on(pattern, handler) {
        const paramNames = [];
        const normalized = pattern.replace(/^\/+|\/+$/g, '');
        
        let regexStr = '^';
        if (normalized === '') {
            regexStr = '^\\/?$';
        } else {
            const segments = normalized.split('/');
            for (const seg of segments) {
                if (seg.startsWith(':')) {
                    const paramName = seg.substring(1);
                    paramNames.push(paramName);
                    regexStr += '/([^/]+)';
                } else {
                    regexStr += `/${seg}`;
                }
            }
            regexStr += '/?$';
        }

        this.routes.push({
            pattern,
            regex: new RegExp(regexStr),
            paramNames,
            handler,
        });

        return this;
    }

    /**
     * Programmatically change hash route.
     * @param {string} path E.g. '/g/abc123'
     */
    navigate(path) {
        const cleanPath = path.startsWith('#') ? path.substring(1) : path;
        window.location.hash = cleanPath.startsWith('/') ? cleanPath : `/${cleanPath}`;
    }

    /**
     * Resolve current hash route against registered patterns.
     */
    resolve() {
        const rawHash = window.location.hash.slice(1) || '/';
        const [rawPath, rawQuery] = rawHash.split('?');
        const path = rawPath.startsWith('/') ? rawPath : `/${rawPath}`;
        const cleanPath = path === '/' ? '/' : path.replace(/\/+$/, '');

        this.currentPath = cleanPath;

        const queryParams = {};
        if (rawQuery) {
            const searchParams = new URLSearchParams(rawQuery);
            for (const [k, v] of searchParams.entries()) {
                queryParams[k] = v;
            }
        }
        if (typeof window !== 'undefined' && window.location.search) {
            const searchParams = new URLSearchParams(window.location.search);
            for (const [k, v] of searchParams.entries()) {
                if (!(k in queryParams)) queryParams[k] = v;
            }
        }

        for (const route of this.routes) {
            const match = cleanPath.match(route.regex);
            if (match) {
                const params = { ...queryParams, _query: queryParams };
                route.paramNames.forEach((name, index) => {
                    params[name] = decodeURIComponent(match[index + 1]);
                });

                try {
                    route.handler(params);
                } catch (err) {
                    console.error(`Error resolving route ${cleanPath}:`, err);
                }
                return;
            }
        }

        console.warn(`No route matched for: ${cleanPath}`);
        // Fallback to root landing
        if (cleanPath !== '/') {
            this.navigate('/');
        }
    }

    /**
     * Start listening for hash changes.
     */
    start() {
        window.addEventListener('hashchange', () => this.resolve());
        // Handle initial page load
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            this.resolve();
        } else {
            document.addEventListener('DOMContentLoaded', () => this.resolve());
        }
    }
}

export const router = new Router();
