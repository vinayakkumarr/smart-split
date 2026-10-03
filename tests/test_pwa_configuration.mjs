/**
 * Smart Split – PWA (Progressive Web App) Configuration & Service Worker Validation Suite
 */
import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

console.log('=====================================================');
console.log(' Smart Split – PWA Configuration & SW Test Suite');
console.log('=====================================================\n');

// -----------------------------------------------------------------------------
// 1. Manifest Validation
// -----------------------------------------------------------------------------
console.log('--- 1. Testing Web App Manifest (public/manifest.json) ---');
const manifestPath = path.join(rootDir, 'public/manifest.json');
assert(fs.existsSync(manifestPath), 'manifest.json must exist');

const manifestRaw = fs.readFileSync(manifestPath, 'utf-8');
let manifest;
try {
    manifest = JSON.parse(manifestRaw);
} catch (e) {
    assert.fail(`manifest.json is not valid JSON: ${e.message}`);
}

assert.strictEqual(manifest.name, 'Smart Split – Financial Workspace', 'Manifest name must match requirement');
assert.strictEqual(manifest.short_name, 'Smart Split', 'Manifest short_name must match requirement');
assert.strictEqual(manifest.start_url, '/#/', 'start_url must be /#/');
assert.strictEqual(manifest.display, 'standalone', 'display mode must be standalone');
assert.strictEqual(manifest.background_color, '#0f172a', 'background_color must be #0f172a');
assert.strictEqual(manifest.theme_color, '#2563eb', 'theme_color must be #2563eb');
assert(Array.isArray(manifest.icons) && manifest.icons.length >= 2, 'Must have at least 2 icon definitions');

const hasAnyIcon = manifest.icons.some(i => i.src === '/favicon.svg' && (i.purpose === 'any' || i.purpose?.includes('any')));
const hasMaskableIcon = manifest.icons.some(i => i.src === '/favicon.svg' && (i.purpose === 'maskable' || i.purpose?.includes('maskable')));

assert(hasAnyIcon, 'Manifest must have icon with any purpose referencing /favicon.svg');
assert(hasMaskableIcon, 'Manifest must have icon with maskable purpose referencing /favicon.svg');
console.log('  [PASS] manifest.json contains valid JSON, metadata, colors, and icon specifications.');

// -----------------------------------------------------------------------------
// 2. Service Worker Validation
// -----------------------------------------------------------------------------
console.log('\n--- 2. Testing Service Worker (public/sw.js) ---');
const swPath = path.join(rootDir, 'public/sw.js');
assert(fs.existsSync(swPath), 'sw.js must exist');

const swContent = fs.readFileSync(swPath, 'utf-8');

assert(swContent.includes('install'), 'Service worker must handle install event');
assert(swContent.includes('activate'), 'Service worker must handle activate event');
assert(swContent.includes('fetch'), 'Service worker must handle fetch event');
assert(swContent.includes('STATIC_PRECACHE_URLS') || swContent.includes('PRECACHE'), 'Must have precache assets list');

// Verify Strict Constraints
assert(swContent.includes("request.method !== 'GET'") || swContent.includes('request.method === \'GET\''), 'Must strictly restrict interception to GET requests');
assert(swContent.includes('/api/'), 'Must explicitly handle /api/ requests');
assert(swContent.includes('caches.match'), 'Must implement Cache-First matching');
assert(swContent.includes('caches.delete'), 'Must clean up outdated caches on activation');

console.log('  [PASS] sw.js implements install, activate, Cache-First static asset strategy, and GET-only restriction.');

// -----------------------------------------------------------------------------
// 3. Index.php HTML Head & Registration Validation
// -----------------------------------------------------------------------------
console.log('\n--- 3. Testing HTML Shell & Registration (public/index.php) ---');
const indexPhp = fs.readFileSync(path.join(rootDir, 'public/index.php'), 'utf-8');
const pwaInitPath = path.join(rootDir, 'public/assets/js/pwa-init.js');
const pwaInitContent = fs.existsSync(pwaInitPath) ? fs.readFileSync(pwaInitPath, 'utf-8') : '';
const combinedClientContent = indexPhp + '\n' + pwaInitContent;

assert(indexPhp.includes('<link rel="manifest" href="/manifest.json">'), 'index.php must link manifest.json');
assert(indexPhp.includes('name="theme-color"'), 'index.php must declare theme-color meta');
assert(indexPhp.includes('apple-mobile-web-app-capable'), 'index.php must declare apple-mobile-web-app-capable');
assert(combinedClientContent.includes('serviceWorker.register'), 'index.php or pwa-init.js must register service worker');
assert(combinedClientContent.includes('/sw.js'), 'Registration must target /sw.js');
assert(combinedClientContent.includes("window.addEventListener('load'"), 'Registration must be deferred to window load event');

console.log('  [PASS] index.php links manifest.json, sets mobile PWA meta tags, and registers sw.js on window load.');

console.log('\n=====================================================');
console.log(' ALL PWA TESTS PASSED (100%)');
console.log('=====================================================');
