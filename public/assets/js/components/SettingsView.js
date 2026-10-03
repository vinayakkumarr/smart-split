/**
 * Smart Split V2 – Dedicated Settings & Application Preferences View
 * Principles: Swiss FinTech Restraint, Explicit Data Ownership, Zero Clutter
 */

import { api } from '../api.js';
import { store } from '../state.js';
import { router } from '../router.js';
import { Toast } from './Toast.js';
import { Modal } from './Modal.js';
import { AuthModal } from './AuthModal.js';
import { LandingView } from './LandingView.js';
import { ThemeManager } from '../utils/theme.js';
import {
    PreferencesManager,
    SUPPORTED_PREF_CURRENCIES,
    SUPPORTED_DATE_FORMATS,
    SUPPORTED_NUMBER_FORMATS,
    SUPPORTED_DENSITIES,
} from '../utils/preferences.js';
import { renderIcon } from '../utils/icons.js';
import { escapeHtml, formatDate, formatCurrency, isValidUpiVpa } from '../utils/formatters.js';

export const PRESET_AVATAR_EMOJIS = [
    { emoji: '👤', label: 'Classic User' },
    { emoji: '💼', label: 'Executive' },
    { emoji: '🛡️', label: 'Security' },
    { emoji: '🧭', label: 'Navigator' },
    { emoji: '⚡', label: 'Lightning' },
    { emoji: '💎', label: 'Prestige' },
    { emoji: '🌟', label: 'Stellar' },
    { emoji: '🚀', label: 'Pioneer' },
    { emoji: '👑', label: 'Crown' },
    { emoji: '🎯', label: 'Target' },
    { emoji: '🍕', label: 'Food & Dining' },
    { emoji: '☕', label: 'Coffee & Daily' },
];

export const PRESET_AVATAR_COLORS = [
    { color: '#18352b', label: 'Forest Green' },
    { color: '#2e563e', label: 'Emerald Slate' },
    { color: '#3730a3', label: 'Indigo Authority' },
    { color: '#28534e', label: 'Teal Deep' },
    { color: '#8c6017', label: 'Warm Bronze' },
    { color: '#5c436d', label: 'Purple Plum' },
    { color: '#9c4127', label: 'Crimson Rust' },
    { color: '#087A5B', label: 'Vibrant Green' },
    { color: '#C43D3D', label: 'Ruby Red' },
    { color: '#1f2937', label: 'Charcoal Noir' },
];

export class SettingsView {
    /**
     * Compute local storage cache statistics safely.
     * @returns {{ workspaceCount: number, recentCount: number, totalKeys: number, approxKb: number }}
     */
    static getLocalCacheStats() {
        let workspaceCount = 0;
        let recentCount = 0;
        let totalBytes = 0;
        let totalKeys = 0;

        if (typeof localStorage === 'undefined') {
            return { workspaceCount: 0, recentCount: 0, totalKeys: 0, approxKb: 0 };
        }

        try {
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (!key) continue;

                if (key.startsWith('smartsplit_cache_')) {
                    workspaceCount++;
                    totalKeys++;
                    const val = localStorage.getItem(key) || '';
                    totalBytes += key.length + val.length;
                } else if (key === 'smartsplit_workspaces') {
                    totalKeys++;
                    const raw = localStorage.getItem(key) || '';
                    totalBytes += key.length + raw.length;
                    try {
                        const parsed = JSON.parse(raw);
                        if (Array.isArray(parsed)) recentCount = parsed.length;
                    } catch {}
                } else if (key.startsWith('smartsplit_budget_') || key.startsWith('smartsplit_avatars_')) {
                    totalKeys++;
                    const val = localStorage.getItem(key) || '';
                    totalBytes += key.length + val.length;
                }
            }
        } catch {}

        return {
            workspaceCount,
            recentCount,
            totalKeys,
            approxKb: Math.max(1, Math.round(totalBytes / 1024)),
        };
    }

    /**
     * Safely purge only Smart Split local cache snapshot keys.
     * Guaranteed never to clear theme preference, format preferences, auth cookies, or server data.
     */
    static clearLocalCache() {
        if (typeof localStorage === 'undefined') return 0;
        const keysToRemove = [];

        try {
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (!key) continue;

                if (
                    key.startsWith('smartsplit_cache_') ||
                    key.startsWith('smartsplit_budget_') ||
                    key.startsWith('smartsplit_avatars_') ||
                    key === 'smartsplit_workspaces'
                ) {
                    keysToRemove.push(key);
                }
            }

            keysToRemove.forEach((k) => localStorage.removeItem(k));
        } catch (err) {
            console.error('Error clearing local cache:', err);
        }

        return keysToRemove.length;
    }

    /**
     * Main render function for Settings & Preferences page.
     * @param {HTMLElement} container
     */
    static render(container) {
        if (!container) return;

        const state = store.getState();
        const isAuthenticated = Boolean(state.isAuthenticated && state.currentUser);
        const currentUser = state.currentUser || null;

        const savedTheme = ThemeManager.getSavedTheme(); // 'light' | 'dark' | null (System)
        const currentActiveTheme = ThemeManager.getCurrentTheme(); // 'light' | 'dark'
        const themeMode = savedTheme === null ? 'system' : savedTheme;

        const currentCurrency = PreferencesManager.getDefaultCurrency();
        const currentDateFormat = PreferencesManager.getDateFormat();
        const currentNumberFormat = PreferencesManager.getNumberFormat();
        const currentDensity = PreferencesManager.getDensity();

        const cacheStats = SettingsView.getLocalCacheStats();

        // Active profile customizer values
        const activeEmoji = currentUser?.avatar_emoji || '👤';
        const activeColor = currentUser?.avatar_color || '#18352b';
        const activeDisplayName = currentUser?.display_name || '';

        container.innerHTML = `
            <div class="settings-page-wrapper" style="max-width: 820px; margin: 0 auto; padding: var(--space-4) var(--space-3) var(--space-8);">
                <!-- Settings Header Navigation -->
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-6); gap: var(--space-3); flex-wrap: wrap;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <a href="#/" class="btn btn-ghost btn-sm" id="btn-settings-back" title="Return to Workspaces" style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; font-weight: 600; color: var(--text-secondary); text-decoration: none;">
                                ${renderIcon('arrowRight', { size: 13, style: 'transform: rotate(180deg);' })}
                                <span>Workspaces</span>
                            </a>
                            <span style="color: var(--text-muted); font-size: 0.8rem;">/</span>
                            <span style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">Preferences</span>
                        </div>
                        <h1 style="font-size: 1.5rem; font-weight: 800; color: var(--text-primary); margin: 0; letter-spacing: -0.02em;">
                            Settings & Preferences
                        </h1>
                    </div>
                    <div style="display: flex; align-items: center; gap: var(--space-2);">
                        ${isAuthenticated ? `
                            <span class="badge badge-credit badge-mono" style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px;">
                                ${renderIcon('shieldCheck', { size: 12 })}
                                <span>Signed In</span>
                            </span>
                        ` : `
                            <span class="badge badge-settled badge-mono" style="display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px;">
                                ${renderIcon('user', { size: 12 })}
                                <span>Guest Mode</span>
                            </span>
                        `}
                    </div>
                </div>

                <!-- 1. In-Place Profile & Avatar Customizer -->
                <div class="panel" style="margin-bottom: var(--space-5);">
                    <div class="panel-header">
                        <div class="panel-title-text">
                            ${renderIcon('user', { size: 15 })}
                            <span>Account Identity & Avatar</span>
                        </div>
                        ${isAuthenticated ? `
                            <span class="badge badge-settled badge-mono">Cloud ID #${currentUser.id}</span>
                        ` : ''}
                    </div>
                    <div class="panel-body">
                        ${isAuthenticated ? `
                            <form id="settings-profile-form" novalidate>
                                <div style="display: flex; align-items: flex-start; gap: var(--space-4); flex-wrap: wrap; margin-bottom: var(--space-4);">
                                    <!-- Live Avatar Badge Preview -->
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 6px;">
                                        <div id="settings-avatar-preview" style="width: 64px; height: 64px; border-radius: var(--radius-sm); background: ${escapeHtml(activeColor)}; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 2rem; border: 2px solid var(--border-color); flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.2s ease;">
                                            ${escapeHtml(activeEmoji)}
                                        </div>
                                        <span style="font-size: 0.68rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.05em;">Live Preview</span>
                                    </div>

                                    <!-- In-Place Name and Email Info -->
                                    <div style="flex: 1; min-width: 240px;">
                                        <div class="form-group" style="margin-bottom: var(--space-3);">
                                            <label class="form-label" for="settings-profile-name" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; margin-bottom: 4px; display: block;">Display Name</label>
                                            <input type="text" id="settings-profile-name" class="form-input" value="${escapeHtml(activeDisplayName)}" placeholder="e.g. Jay" required maxlength="80" style="width: 100%; box-sizing: border-box;">
                                            <span style="font-size: 0.72rem; color: var(--text-muted); margin-top: 3px; display: block;">Your public name across shared group ledgers.</span>
                                        </div>

                                        <div style="font-size: var(--font-size-xs); color: var(--text-muted); font-family: var(--font-mono); margin-bottom: 4px;">
                                            ${escapeHtml(currentUser.email || '')}
                                        </div>
                                    </div>
                                </div>

                                <!-- Avatar Emoji Customizer Picker -->
                                <div style="margin-bottom: var(--space-4); padding-top: var(--space-3); border-top: 1px solid var(--border-color);">
                                    <label class="form-label" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; margin-bottom: 6px; display: block;">Choose Avatar Emoji</label>
                                    <div class="settings-emoji-grid" style="display: flex; gap: 6px; flex-wrap: wrap;">
                                        ${PRESET_AVATAR_EMOJIS.map(e => `
                                            <button type="button" class="settings-emoji-btn ${e.emoji === activeEmoji ? 'active' : ''}" data-emoji="${e.emoji}" title="${e.label}" style="font-size: 1.25rem; padding: 6px 10px; border-radius: var(--radius-xs); border: 1px solid ${e.emoji === activeEmoji ? 'var(--brand-primary)' : 'var(--border-color)'}; background: ${e.emoji === activeEmoji ? 'var(--brand-primary-soft)' : 'var(--surface-primary)'}; cursor: pointer; transition: all 0.15s ease;">
                                                ${e.emoji}
                                            </button>
                                        `).join('')}
                                    </div>
                                </div>

                                <!-- Avatar Color Customizer Picker -->
                                <div style="margin-bottom: var(--space-4);">
                                    <label class="form-label" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; margin-bottom: 6px; display: block;">Choose Badge Color</label>
                                    <div class="settings-color-grid" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                        ${PRESET_AVATAR_COLORS.map(c => `
                                            <button type="button" class="settings-color-btn ${c.color.toLowerCase() === activeColor.toLowerCase() ? 'active' : ''}" data-color="${c.color}" title="${c.label}" style="width: 28px; height: 28px; border-radius: 9999px; background: ${c.color}; border: 2px solid ${c.color.toLowerCase() === activeColor.toLowerCase() ? 'var(--text-primary)' : 'transparent'}; cursor: pointer; transform: ${c.color.toLowerCase() === activeColor.toLowerCase() ? 'scale(1.15)' : 'scale(1)'}; box-shadow: 0 1px 3px rgba(0,0,0,0.15); transition: all 0.15s ease;"></button>
                                        `).join('')}
                                    </div>
                                </div>

                                <!-- Personal UPI Identity Section -->
                                <div style="margin-bottom: var(--space-4); padding-top: var(--space-3); border-top: 1px solid var(--border-color);">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                        <label class="form-label" for="settings-profile-upi" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; margin: 0; display: block;">
                                            Personal UPI ID / VPA
                                        </label>
                                        ${currentUser?.upi_id ? `
                                            <span class="badge badge-credit badge-mono" style="font-size: 0.65rem; padding: 2px 6px;">Active</span>
                                        ` : `
                                            <span class="badge badge-settled badge-mono" style="font-size: 0.65rem; padding: 2px 6px;">Not Set</span>
                                        `}
                                    </div>
                                    <div style="display: flex; gap: var(--space-2); align-items: center;">
                                        <input
                                            type="text"
                                            id="settings-profile-upi"
                                            class="form-input"
                                            value="${escapeHtml(currentUser?.upi_id || '')}"
                                            placeholder="e.g. name@okaxis, mobile@paytm"
                                            maxlength="80"
                                            style="flex: 1; font-size: var(--font-size-xs); font-family: var(--font-mono);"
                                        >
                                        ${currentUser?.upi_id ? `
                                            <button type="button" class="btn btn-ghost btn-sm" id="btn-settings-copy-upi" title="Copy UPI ID" style="padding: 4px 8px;">
                                                ${renderIcon('copy', { size: 14 })}
                                            </button>
                                            <button type="button" class="btn btn-ghost btn-sm" id="btn-settings-clear-upi" title="Clear UPI ID" style="padding: 4px 8px; color: var(--financial-debt);">
                                                ${renderIcon('trash2', { size: 14 })}
                                            </button>
                                        ` : ''}
                                    </div>
                                    <span style="font-size: 0.72rem; color: var(--text-muted); margin-top: 4px; display: block;">
                                        Your UPI ID is used to generate payment links and QR codes when other members need to pay you across any linked workspace.
                                    </span>
                                </div>

                                <div style="display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); flex-wrap: wrap; padding-top: var(--space-3); border-top: 1px solid var(--border-color);">
                                    <button type="submit" id="btn-settings-save-profile" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
                                        ${renderIcon('check', { size: 14 })}
                                        <span>Save Profile Changes</span>
                                    </button>

                                    <button type="button" class="btn btn-secondary btn-sm" id="btn-settings-switch-workspaces" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                                        ${renderIcon('folder', { size: 13 })}
                                        <span>My Workspaces</span>
                                    </button>
                                </div>
                            </form>
                        ` : `
                            <div style="display: flex; align-items: flex-start; gap: var(--space-4); flex-wrap: wrap;">
                                <div style="width: 44px; height: 44px; border-radius: 9999px; background: var(--brand-primary-soft, #e8f0ec); color: var(--brand-primary, #18352b); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    ${renderIcon('user', { size: 20 })}
                                </div>
                                <div style="flex: 1; min-width: 220px;">
                                    <div style="font-weight: 800; font-size: var(--font-size-sm); color: var(--text-primary); margin-bottom: 4px;">
                                        Exploring as Guest
                                    </div>
                                    <p style="margin: 0 0 var(--space-3); font-size: var(--font-size-xs); color: var(--text-muted); line-height: 1.45;">
                                        You are currently using Smart Split without an account. Your workspaces are stored locally in this browser. Create a free account or sign in to customize your avatar badge, sync ledgers across devices, and link member identities.
                                    </p>
                                    <button type="button" class="btn btn-primary btn-sm" id="btn-settings-guest-signin" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
                                        ${renderIcon('logIn', { size: 14 })}
                                        <span>Sign In / Create Account</span>
                                    </button>
                                </div>
                            </div>
                        `}
                    </div>
                </div>

                <!-- 2. Appearance & Display Density Section -->
                <div class="panel" style="margin-bottom: var(--space-5);">
                    <div class="panel-header">
                        <div class="panel-title-text">
                            ${renderIcon('sparkles', { size: 15 })}
                            <span>Appearance & Display Density</span>
                        </div>
                        <span class="badge badge-settled badge-mono" id="settings-active-theme-label">Active: ${currentActiveTheme.toUpperCase()}</span>
                    </div>
                    <div class="panel-body">
                        <!-- Theme Sub-Section -->
                        <div style="margin-bottom: var(--space-5);">
                            <label class="form-label" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; margin-bottom: 6px; display: block;">Visual Color Theme</label>
                            <p style="margin: 0 0 var(--space-3); font-size: var(--font-size-xs); color: var(--text-secondary); line-height: 1.4;">
                                Choose your preferred visual palette. Settings apply immediately to this device and browser.
                            </p>
                            
                            <div class="settings-theme-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--space-3);">
                                <!-- System Theme Card -->
                                <button type="button" class="settings-theme-card ${themeMode === 'system' ? 'is-selected' : ''}" data-theme-target="system" style="text-align: left; padding: var(--space-3) var(--space-4); border-radius: var(--radius-sm); border: 2px solid ${themeMode === 'system' ? 'var(--brand-primary)' : 'var(--border-color)'}; background: ${themeMode === 'system' ? 'var(--brand-primary-soft, rgba(24,53,43,0.06))' : 'var(--surface-secondary)'}; cursor: pointer; transition: all 0.15s ease;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                        <span style="font-weight: 700; font-size: var(--font-size-sm); color: var(--text-primary); display: inline-flex; align-items: center; gap: 6px;">
                                            ${renderIcon('globe', { size: 14 })}
                                            <span>System Default</span>
                                        </span>
                                        ${themeMode === 'system' ? `<span style="color: var(--brand-primary);">${renderIcon('check', { size: 14 })}</span>` : ''}
                                    </div>
                                    <div style="font-size: 0.72rem; color: var(--text-muted); line-height: 1.3;">
                                        Automatically matches your operating system preference.
                                    </div>
                                </button>

                                <!-- Light Theme Card -->
                                <button type="button" class="settings-theme-card ${themeMode === 'light' ? 'is-selected' : ''}" data-theme-target="light" style="text-align: left; padding: var(--space-3) var(--space-4); border-radius: var(--radius-sm); border: 2px solid ${themeMode === 'light' ? 'var(--brand-primary)' : 'var(--border-color)'}; background: ${themeMode === 'light' ? 'var(--brand-primary-soft, rgba(24,53,43,0.06))' : 'var(--surface-secondary)'}; cursor: pointer; transition: all 0.15s ease;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                        <span style="font-weight: 700; font-size: var(--font-size-sm); color: var(--text-primary); display: inline-flex; align-items: center; gap: 6px;">
                                            ${renderIcon('sparkles', { size: 14 })}
                                            <span>Light Mode</span>
                                        </span>
                                        ${themeMode === 'light' ? `<span style="color: var(--brand-primary);">${renderIcon('check', { size: 14 })}</span>` : ''}
                                    </div>
                                    <div style="font-size: 0.72rem; color: var(--text-muted); line-height: 1.3;">
                                        Crisp editorial light theme with high typographic contrast.
                                    </div>
                                </button>

                                <!-- Dark Theme Card -->
                                <button type="button" class="settings-theme-card ${themeMode === 'dark' ? 'is-selected' : ''}" data-theme-target="dark" style="text-align: left; padding: var(--space-3) var(--space-4); border-radius: var(--radius-sm); border: 2px solid ${themeMode === 'dark' ? 'var(--brand-primary)' : 'var(--border-color)'}; background: ${themeMode === 'dark' ? 'var(--brand-primary-soft, rgba(24,53,43,0.06))' : 'var(--surface-secondary)'}; cursor: pointer; transition: all 0.15s ease;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                        <span style="font-weight: 700; font-size: var(--font-size-sm); color: var(--text-primary); display: inline-flex; align-items: center; gap: 6px;">
                                            ${renderIcon('shield', { size: 14 })}
                                            <span>Dark Mode</span>
                                        </span>
                                        ${themeMode === 'dark' ? `<span style="color: var(--brand-primary);">${renderIcon('check', { size: 14 })}</span>` : ''}
                                    </div>
                                    <div style="font-size: 0.72rem; color: var(--text-muted); line-height: 1.3;">
                                        Deep OLED dark palette optimized for evening reading.
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- Display Density Mode Sub-Section -->
                        <div style="padding-top: var(--space-4); border-top: 1px solid var(--border-color);">
                            <label class="form-label" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; margin-bottom: 6px; display: block;">Display Density (Compact Mode)</label>
                            <p style="margin: 0 0 var(--space-3); font-size: var(--font-size-xs); color: var(--text-secondary); line-height: 1.4;">
                                Select interface information density. Compact mode tightens spacing and table rows for maximum data density.
                            </p>

                            <div class="settings-density-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-3);">
                                ${SUPPORTED_DENSITIES.map(d => `
                                    <button type="button" class="settings-density-card ${currentDensity === d.id ? 'is-selected' : ''}" data-density-target="${d.id}" style="text-align: left; padding: var(--space-3) var(--space-4); border-radius: var(--radius-sm); border: 2px solid ${currentDensity === d.id ? 'var(--brand-primary)' : 'var(--border-color)'}; background: ${currentDensity === d.id ? 'var(--brand-primary-soft, rgba(24,53,43,0.06))' : 'var(--surface-secondary)'}; cursor: pointer; transition: all 0.15s ease;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                            <span style="font-weight: 700; font-size: var(--font-size-sm); color: var(--text-primary); display: inline-flex; align-items: center; gap: 6px;">
                                                ${renderIcon('zap', { size: 14 })}
                                                <span>${escapeHtml(d.label)}</span>
                                            </span>
                                            ${currentDensity === d.id ? `<span style="color: var(--brand-primary);">${renderIcon('check', { size: 14 })}</span>` : ''}
                                        </div>
                                        <div style="font-size: 0.72rem; color: var(--text-muted); line-height: 1.3;">
                                            ${escapeHtml(d.desc)}
                                        </div>
                                    </button>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. Regional, Currency & Formatting Preferences Section -->
                <div class="panel" style="margin-bottom: var(--space-5);">
                    <div class="panel-header">
                        <div class="panel-title-text">
                            ${renderIcon('globe', { size: 15 })}
                            <span>Regional & Formatting Preferences</span>
                        </div>
                        <span class="badge badge-settled badge-mono">Live Formatting</span>
                    </div>
                    <div class="panel-body">
                        <!-- Default Currency Preference -->
                        <div style="margin-bottom: var(--space-4);">
                            <label class="form-label" for="settings-pref-currency" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; margin-bottom: 4px; display: block;">Default Workspace Currency</label>
                            <p style="margin: 0 0 var(--space-2); font-size: var(--font-size-xs); color: var(--text-secondary); line-height: 1.4;">
                                Pre-selected default base currency when creating new expense workspaces.
                            </p>
                            <select id="settings-pref-currency" class="form-select" style="max-width: 320px;">
                                ${SUPPORTED_PREF_CURRENCIES.map(c => `
                                    <option value="${c.code}" ${c.code === currentCurrency ? 'selected' : ''}>
                                        ${c.code} — ${escapeHtml(c.name)} (${c.symbol})
                                    </option>
                                `).join('')}
                            </select>
                        </div>

                        <!-- Date & Number Formats Grid -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--space-4); padding-top: var(--space-3); border-top: 1px solid var(--border-color); margin-bottom: var(--space-4);">
                            <!-- Date Format -->
                            <div>
                                <label class="form-label" for="settings-pref-date-format" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; margin-bottom: 4px; display: block;">Date Format</label>
                                <select id="settings-pref-date-format" class="form-select" style="width: 100%;">
                                    ${SUPPORTED_DATE_FORMATS.map(f => `
                                        <option value="${f.id}" ${f.id === currentDateFormat ? 'selected' : ''}>
                                            ${f.label} (e.g. ${f.example})
                                        </option>
                                    `).join('')}
                                </select>
                            </div>

                            <!-- Number Format -->
                            <div>
                                <label class="form-label" for="settings-pref-number-format" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; margin-bottom: 4px; display: block;">Number & Decimal Style</label>
                                <select id="settings-pref-number-format" class="form-select" style="width: 100%;">
                                    ${SUPPORTED_NUMBER_FORMATS.map(n => `
                                        <option value="${n.id}" ${n.id === currentNumberFormat ? 'selected' : ''}>
                                            ${n.label} — ${escapeHtml(n.desc)}
                                        </option>
                                    `).join('')}
                                </select>
                            </div>
                        </div>

                        <!-- Live Formatting Preview Box -->
                        <div style="background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-xs); padding: var(--space-3); display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); flex-wrap: wrap;">
                            <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">
                                Preview Sample:
                            </div>
                            <div id="settings-formatting-preview" style="font-family: var(--font-mono); font-weight: 700; font-size: var(--font-size-sm); color: var(--text-primary);">
                                ${formatCurrency(123456, currentCurrency)} &bull; ${formatDate('2026-09-21')}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Security & Authentication Section -->
                <div class="panel" style="margin-bottom: var(--space-5);">
                    <div class="panel-header">
                        <div class="panel-title-text">
                            ${renderIcon('shieldCheck', { size: 15 })}
                            <span>Security & Authentication</span>
                        </div>
                        <span class="badge badge-mono badge-settled">bcrypt cost 12</span>
                    </div>
                    <div class="panel-body">
                        <div style="display: flex; flex-direction: column; gap: var(--space-3);">
                            <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: var(--space-3); border-bottom: 1px solid var(--border-color); gap: var(--space-3); flex-wrap: wrap;">
                                <div>
                                    <div style="font-weight: 700; font-size: var(--font-size-xs); color: var(--text-primary); margin-bottom: 2px;">
                                        Password & Emergency Recovery Key
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); line-height: 1.4;">
                                        Password recovery uses cryptographic 64-bit keys (<code style="font-family: var(--font-mono); font-weight: 700;">SMART-XXXX-XXXX</code>). Passwords are never stored in plaintext.
                                    </div>
                                </div>
                                ${isAuthenticated ? `
                                    <button type="button" class="btn btn-secondary btn-sm" id="btn-settings-recover-pwd" style="display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; font-size: var(--font-size-xs);">
                                        ${renderIcon('key', { size: 13 })}
                                        <span>Reset Password</span>
                                    </button>
                                ` : `
                                    <span style="font-size: var(--font-size-xs); color: var(--text-muted); font-style: italic;">Requires Account</span>
                                `}
                            </div>

                            <div style="display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); flex-wrap: wrap;">
                                <div>
                                    <div style="font-weight: 700; font-size: var(--font-size-xs); color: var(--text-primary); margin-bottom: 2px;">
                                        Brute-Force & Lockout Guard
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); line-height: 1.4;">
                                        Active anti-tampering protection locks out failed authentication attempts automatically after 5 incorrect tries.
                                    </div>
                                </div>
                                <span class="badge badge-credit badge-mono" style="font-size: 0.7rem;">Active</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 5. Local Data & Offline Storage Section -->
                <div class="panel" style="margin-bottom: var(--space-5);">
                    <div class="panel-header">
                        <div class="panel-title-text">
                            ${renderIcon('folder', { size: 15 })}
                            <span>Local Device Data & Cache</span>
                        </div>
                        <span class="badge badge-mono badge-settled" id="settings-cache-badge">${cacheStats.approxKb} KB Cached</span>
                    </div>
                    <div class="panel-body">
                        <p style="margin: 0 0 var(--space-3); font-size: var(--font-size-xs); color: var(--text-secondary); line-height: 1.45;">
                            Smart Split saves local snapshots of visited workspaces in this browser so you can inspect balances and settlements instantly even when offline.
                        </p>

                        <div style="background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-xs); padding: var(--space-3); margin-bottom: var(--space-3); display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); flex-wrap: wrap;">
                            <div style="font-size: 0.78rem; color: var(--text-primary);">
                                <strong>${cacheStats.workspaceCount}</strong> offline ledger ${cacheStats.workspaceCount === 1 ? 'cache' : 'caches'} &bull; <strong>${cacheStats.recentCount}</strong> recent ${cacheStats.recentCount === 1 ? 'workspace' : 'workspaces'} in history
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-settings-clear-cache" style="font-size: var(--font-size-xs); color: var(--financial-debt); display: inline-flex; align-items: center; gap: 5px;">
                                ${renderIcon('trash2', { size: 12 })}
                                <span>Clear Local Offline Cache</span>
                            </button>
                        </div>
                        <div style="font-size: 0.72rem; color: var(--text-muted); line-height: 1.35;">
                            <strong>Note:</strong> Clearing local cache purges offline copies on this browser only. Server-side ledger transactions, cloud accounts, and formatting preferences are never deleted.
                        </div>
                    </div>
                </div>

                <!-- 6. Global Keyboard Shortcuts Reference Section -->
                <div class="panel" style="margin-bottom: var(--space-5);">
                    <div class="panel-header">
                        <div class="panel-title-text">
                            ${renderIcon('zap', { size: 15 })}
                            <span>Global Keyboard Shortcuts</span>
                        </div>
                        <span class="badge badge-settled badge-mono">Reference</span>
                    </div>
                    <div class="panel-body">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-3);">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <kbd style="background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-xs); padding: 3px 8px; font-family: var(--font-mono); font-size: 0.8rem; font-weight: 700; color: var(--text-primary); box-shadow: 0 1px 2px rgba(0,0,0,0.06);">E</kbd>
                                <span style="font-size: var(--font-size-xs); color: var(--text-secondary);">Log / Add Expense</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <kbd style="background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-xs); padding: 3px 8px; font-family: var(--font-mono); font-size: 0.8rem; font-weight: 700; color: var(--text-primary); box-shadow: 0 1px 2px rgba(0,0,0,0.06);">M</kbd>
                                <span style="font-size: var(--font-size-xs); color: var(--text-secondary);">Add Group Member</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <kbd style="background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-xs); padding: 3px 8px; font-family: var(--font-mono); font-size: 0.8rem; font-weight: 700; color: var(--text-primary); box-shadow: 0 1px 2px rgba(0,0,0,0.06);">Esc</kbd>
                                <span style="font-size: var(--font-size-xs); color: var(--text-secondary);">Close Active Modal</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <kbd style="background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-xs); padding: 3px 8px; font-family: var(--font-mono); font-size: 0.8rem; font-weight: 700; color: var(--text-primary); box-shadow: 0 1px 2px rgba(0,0,0,0.06);">Ctrl+Enter</kbd>
                                <span style="font-size: var(--font-size-xs); color: var(--text-secondary);">Submit Modal Form</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 7. Account Lifecycle & Destructive Actions -->
                ${isAuthenticated ? `
                    <div class="panel" style="border-color: rgba(239, 68, 68, 0.25);">
                        <div class="panel-header" style="background: var(--surface-secondary);">
                            <div class="panel-title-text" style="color: var(--text-primary);">
                                ${renderIcon('userCheck', { size: 15 })}
                                <span>Account Lifecycle</span>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); flex-wrap: wrap; margin-bottom: var(--space-4); padding-bottom: var(--space-3); border-bottom: 1px solid var(--border-color);">
                                <div>
                                    <div style="font-weight: 700; font-size: var(--font-size-xs); color: var(--text-primary); margin-bottom: 2px;">
                                        Sign Out of Account
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                                        Terminates your active server-side session and clears authentication cookies.
                                    </div>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" id="btn-settings-signout" style="display: inline-flex; align-items: center; gap: 5px;">
                                    ${renderIcon('logOut', { size: 13 })}
                                    <span>Sign Out</span>
                                </button>
                            </div>

                            <div style="display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); flex-wrap: wrap;">
                                <div>
                                    <div style="font-weight: 700; font-size: var(--font-size-xs); color: var(--financial-debt); margin-bottom: 2px;">
                                        Permanently Delete Account
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); line-height: 1.35;">
                                        Deletes your login credentials and disassociates linked member slots. Group financial ledgers remain completely intact.
                                    </div>
                                </div>
                                <button type="button" class="btn btn-danger btn-sm" id="btn-settings-delete-account" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700;">
                                    ${renderIcon('trash2', { size: 13 })}
                                    <span>Delete Account</span>
                                </button>
                            </div>
                        </div>
                    </div>
                ` : ''}
            </div>
        `;

        // Attach Interactive Event Listeners
        SettingsView.attachListeners(container, {
            selectedEmoji: activeEmoji,
            selectedColor: activeColor,
        });
    }

    /**
     * Attach interactive event listeners to the rendered Settings view.
     * @param {HTMLElement} container
     * @param {Object} [initialState]
     */
    static attachListeners(container, initialState = {}) {
        let currentSelectedEmoji = initialState.selectedEmoji || '👤';
        let currentSelectedColor = initialState.selectedColor || '#18352b';

        // 1. Back Button
        const backBtn = container.querySelector('#btn-settings-back');
        if (backBtn) {
            backBtn.addEventListener('click', (e) => {
                e.preventDefault();
                router.navigate('/');
            });
        }

        // 2. Guest Sign In Button
        const guestSignInBtn = container.querySelector('#btn-settings-guest-signin');
        if (guestSignInBtn) {
            guestSignInBtn.addEventListener('click', () => {
                AuthModal.open({
                    initialMode: 'login',
                    onSuccess: () => {
                        SettingsView.render(container);
                    },
                });
            });
        }

        // 3. In-Place Profile & Avatar Customizer Interactions
        const avatarPreview = container.querySelector('#settings-avatar-preview');

        // Emoji buttons
        container.querySelectorAll('.settings-emoji-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const emoji = btn.getAttribute('data-emoji');
                if (emoji) {
                    currentSelectedEmoji = emoji;
                    if (avatarPreview) avatarPreview.textContent = emoji;
                    container.querySelectorAll('.settings-emoji-btn').forEach((b) => {
                        const isMatch = b.getAttribute('data-emoji') === emoji;
                        b.classList.toggle('active', isMatch);
                        b.style.borderColor = isMatch ? 'var(--brand-primary)' : 'var(--border-color)';
                        b.style.background = isMatch ? 'var(--brand-primary-soft)' : 'var(--surface-primary)';
                    });
                }
            });
        });

        // Color buttons
        container.querySelectorAll('.settings-color-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const color = btn.getAttribute('data-color');
                if (color) {
                    currentSelectedColor = color;
                    if (avatarPreview) avatarPreview.style.background = color;
                    container.querySelectorAll('.settings-color-btn').forEach((b) => {
                        const isMatch = (b.getAttribute('data-color') || '').toLowerCase() === color.toLowerCase();
                        b.classList.toggle('active', isMatch);
                        b.style.borderColor = isMatch ? 'var(--text-primary)' : 'transparent';
                        b.style.transform = isMatch ? 'scale(1.15)' : 'scale(1)';
                    });
                }
            });
        });

        // Profile Form Submission (In-place save)
        const profileForm = container.querySelector('#settings-profile-form');
        if (profileForm) {
            profileForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const nameInput = container.querySelector('#settings-profile-name');
                const upiInput = container.querySelector('#settings-profile-upi');
                const saveBtn = container.querySelector('#btn-settings-save-profile');
                const displayName = nameInput ? nameInput.value.trim() : '';
                const rawUpi = upiInput ? upiInput.value.trim() : '';
                const upiId = rawUpi !== '' ? rawUpi : null;

                if (!displayName) {
                    Toast.show('Display name cannot be empty.', 'error');
                    return;
                }

                if (upiId !== null && !isValidUpiVpa(upiId)) {
                    Toast.show('Invalid UPI ID format. Please enter a valid VPA (maximum 80 characters, e.g. name@okaxis).', 'error');
                    return;
                }

                try {
                    if (saveBtn) {
                        saveBtn.disabled = true;
                        saveBtn.innerHTML = `${renderIcon('loader', { size: 14, className: 'spin' })} <span>Saving...</span>`;
                    }

                    const res = await api.updateProfile({
                        display_name: displayName,
                        avatar_emoji: currentSelectedEmoji,
                        avatar_color: currentSelectedColor,
                        upi_id: upiId,
                    });

                    const updatedUser = res?.data?.user || res?.user || {
                        ...(store.getState().currentUser || {}),
                        display_name: displayName,
                        avatar_emoji: currentSelectedEmoji,
                        avatar_color: currentSelectedColor,
                        upi_id: upiId,
                    };
                    store.setState({ currentUser: updatedUser });

                    Toast.show('Profile updated successfully.', 'success');
                    SettingsView.render(container);
                } catch (err) {
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = `${renderIcon('check', { size: 14 })} <span>Save Profile Changes</span>`;
                    }
                    Toast.show(err.message || 'Failed to update profile.', 'error');
                }
            });
        }

        // Copy Personal UPI ID Button
        const copyUpiBtn = container.querySelector('#btn-settings-copy-upi');
        if (copyUpiBtn) {
            copyUpiBtn.addEventListener('click', async () => {
                const currentUser = store.getState().currentUser;
                if (currentUser && currentUser.upi_id) {
                    try {
                        if (navigator.clipboard) {
                            await navigator.clipboard.writeText(currentUser.upi_id);
                            Toast.show('UPI ID copied to clipboard.', 'success');
                        }
                    } catch {
                        Toast.show('UPI ID ready to copy.', 'info');
                    }
                }
            });
        }

        // Clear Personal UPI ID Button
        const clearUpiBtn = container.querySelector('#btn-settings-clear-upi');
        if (clearUpiBtn) {
            clearUpiBtn.addEventListener('click', async () => {
                const upiInput = container.querySelector('#settings-profile-upi');
                if (upiInput) {
                    upiInput.value = '';
                }
                const nameInput = container.querySelector('#settings-profile-name');
                const displayName = nameInput ? nameInput.value.trim() : (store.getState().currentUser?.display_name || 'Member');

                try {
                    const res = await api.updateProfile({
                        display_name: displayName,
                        avatar_emoji: currentSelectedEmoji,
                        avatar_color: currentSelectedColor,
                        upi_id: null,
                    });

                    const updatedUser = res?.data?.user || res?.user || {
                        ...(store.getState().currentUser || {}),
                        upi_id: null,
                    };
                    store.setState({ currentUser: updatedUser });
                    Toast.show('Personal UPI ID removed.', 'info');
                    SettingsView.render(container);
                } catch (err) {
                    Toast.show(err.message || 'Failed to remove UPI ID.', 'error');
                }
            });
        }

        // 4. Theme Selection Buttons
        container.querySelectorAll('.settings-theme-card').forEach((card) => {
            card.addEventListener('click', () => {
                const target = card.getAttribute('data-theme-target');
                if (target === 'system') {
                    try {
                        localStorage.removeItem('smartsplit_theme');
                    } catch {}
                    ThemeManager.applyTheme(ThemeManager.getSystemTheme(), false);
                } else if (target === 'light' || target === 'dark') {
                    ThemeManager.applyTheme(target, true);
                }
                SettingsView.render(container);
                Toast.show(`Theme set to ${target === 'system' ? 'System Default' : target.charAt(0).toUpperCase() + target.slice(1)}`, 'info');
            });
        });

        // 5. Display Density Selection Cards
        container.querySelectorAll('.settings-density-card').forEach((card) => {
            card.addEventListener('click', () => {
                const target = card.getAttribute('data-density-target');
                if (target === 'standard' || target === 'compact') {
                    PreferencesManager.setDensity(target);
                    SettingsView.render(container);
                    Toast.show(`Display density set to ${target === 'compact' ? 'Compact Mode' : 'Standard Density'}`, 'info');
                }
            });
        });

        // 6. Default Currency Preference Dropdown
        const currencySelect = container.querySelector('#settings-pref-currency');
        if (currencySelect) {
            currencySelect.addEventListener('change', () => {
                const val = currencySelect.value;
                PreferencesManager.setDefaultCurrency(val);
                updateFormattingPreview();
                Toast.show(`Default workspace currency set to ${val}`, 'info');
            });
        }

        // 7. Date Format Preference Dropdown
        const dateFormatSelect = container.querySelector('#settings-pref-date-format');
        if (dateFormatSelect) {
            dateFormatSelect.addEventListener('change', () => {
                const val = dateFormatSelect.value;
                PreferencesManager.setDateFormat(val);
                updateFormattingPreview();
                Toast.show(`Date format set to ${val}`, 'info');
            });
        }

        // 8. Number Format Preference Dropdown
        const numberFormatSelect = container.querySelector('#settings-pref-number-format');
        if (numberFormatSelect) {
            numberFormatSelect.addEventListener('change', () => {
                const val = numberFormatSelect.value;
                PreferencesManager.setNumberFormat(val);
                updateFormattingPreview();
                Toast.show(`Number format set to ${val === 'dot_decimal' ? '1.234,56' : '1,234.56'}`, 'info');
            });
        }

        function updateFormattingPreview() {
            const previewEl = container.querySelector('#settings-formatting-preview');
            if (previewEl) {
                const curr = PreferencesManager.getDefaultCurrency();
                previewEl.textContent = `${formatCurrency(123456, curr)} • ${formatDate('2026-09-21')}`;
            }
        }

        // 9. Password Recovery Link
        const recoverBtn = container.querySelector('#btn-settings-recover-pwd');
        if (recoverBtn) {
            recoverBtn.addEventListener('click', () => {
                AuthModal.open({ initialMode: 'recover' });
            });
        }

        // 10. Switch Workspaces Action
        const workspacesBtn = container.querySelector('#btn-settings-switch-workspaces');
        if (workspacesBtn) {
            workspacesBtn.addEventListener('click', () => {
                LandingView.openWorkspacesModal();
            });
        }

        // 11. Clear Local Cache Action
        const clearCacheBtn = container.querySelector('#btn-settings-clear-cache');
        if (clearCacheBtn) {
            clearCacheBtn.addEventListener('click', () => {
                Modal.open({
                    title: 'Clear Offline Cache',
                    size: 'sm',
                    showFooter: false,
                    content: `
                        <div style="margin-bottom: var(--space-3);">
                            <p style="font-size: var(--font-size-sm); color: var(--text-primary); margin-bottom: var(--space-2); line-height: 1.45;">
                                This will remove cached offline copies of workspaces and recent workspace history from this browser.
                            </p>
                            <p style="font-size: var(--font-size-xs); color: var(--text-muted); line-height: 1.4; margin-bottom: var(--space-4);">
                                Server-side group ledgers, preferences, and your authentication session will remain completely unaffected.
                            </p>
                        </div>
                        <div style="display: flex; gap: var(--space-2); justify-content: flex-end;">
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-cancel-clear-cache">Cancel</button>
                            <button type="button" class="btn btn-danger btn-sm" id="btn-confirm-clear-cache" style="font-weight: 700;">Clear Cache</button>
                        </div>
                    `,
                    onMount: (overlay) => {
                        overlay.querySelector('#btn-cancel-clear-cache')?.addEventListener('click', () => Modal.close());
                        overlay.querySelector('#btn-confirm-clear-cache')?.addEventListener('click', () => {
                            const count = SettingsView.clearLocalCache();
                            Modal.close();
                            SettingsView.render(container);
                            Toast.show(`Cleared ${count} cached items from local storage.`, 'success');
                        });
                    },
                });
            });
        }

        // 12. Sign Out Action
        const signoutBtn = container.querySelector('#btn-settings-signout');
        if (signoutBtn) {
            signoutBtn.addEventListener('click', async () => {
                try {
                    await api.logout();
                    store.setState({ currentUser: null, isAuthenticated: false });
                    Toast.show('Signed out successfully.', 'success');
                    router.navigate('/');
                } catch (err) {
                    store.setState({ currentUser: null, isAuthenticated: false });
                    Toast.show('Signed out.', 'success');
                    router.navigate('/');
                }
            });
        }

        // 13. Delete Account Action
        const deleteAccountBtn = container.querySelector('#btn-settings-delete-account');
        if (deleteAccountBtn) {
            deleteAccountBtn.addEventListener('click', () => {
                Modal.open({
                    title: 'Delete Account',
                    size: 'sm',
                    showFooter: false,
                    content: `
                        <div style="margin-bottom: var(--space-3);">
                            <p style="font-size: var(--font-size-sm); color: var(--financial-debt); font-weight: 600; margin-bottom: var(--space-2); display: flex; align-items: center; gap: 6px;">
                                ${renderIcon('alertTriangle', { size: 16 })}
                                <span>Warning: This action is permanent.</span>
                            </p>
                            <p style="font-size: var(--font-size-xs); color: var(--text-muted); line-height: 1.4; margin-bottom: var(--space-4);">
                                Deleting your account removes your login identity. Workspace ledger history will remain intact for your groups.
                            </p>
                        </div>
                        <form id="settings-delete-account-form">
                            <div id="settings-delete-account-error" style="display: none; background: rgba(239,68,68,0.1); border: 1px solid var(--financial-debt); color: var(--financial-debt); padding: 8px 12px; border-radius: var(--radius-xs); font-size: var(--font-size-xs); margin-bottom: var(--space-3);"></div>
                            <div class="form-group" style="margin-bottom: var(--space-4);">
                                <label class="form-label" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase;">Confirm Password</label>
                                <input type="password" id="settings-delete-pwd" class="form-input" placeholder="Enter your password" required style="width: 100%; box-sizing: border-box;">
                            </div>
                            <div style="display: flex; gap: var(--space-2);">
                                <button type="button" class="btn btn-secondary btn-sm" id="btn-cancel-delete-acc" style="flex: 1;">Cancel</button>
                                <button type="submit" id="btn-submit-delete-acc" class="btn btn-danger btn-sm" style="flex: 1; font-weight: 700;">Delete Account</button>
                            </div>
                        </form>
                    `,
                    onMount: (overlay) => {
                        const form = overlay.querySelector('#settings-delete-account-form');
                        const cancelBtn = overlay.querySelector('#btn-cancel-delete-acc');
                        if (cancelBtn) cancelBtn.addEventListener('click', () => Modal.close());

                        if (form) {
                            form.addEventListener('submit', async (e) => {
                                e.preventDefault();
                                const passInput = overlay.querySelector('#settings-delete-pwd');
                                const errDiv = overlay.querySelector('#settings-delete-account-error');
                                const submitBtn = overlay.querySelector('#btn-submit-delete-acc');

                                const password = passInput?.value || '';
                                if (!password) return;

                                try {
                                    submitBtn.disabled = true;
                                    submitBtn.textContent = 'Deleting...';
                                    if (errDiv) errDiv.style.display = 'none';

                                    await api.deleteAccount({ password });
                                    store.setState({ currentUser: null, isAuthenticated: false });
                                    Modal.close();
                                    Toast.show('Account deleted successfully.', 'success');
                                    router.navigate('/');
                                } catch (err) {
                                    submitBtn.disabled = false;
                                    submitBtn.textContent = 'Delete Account';
                                    if (errDiv) {
                                        errDiv.textContent = err.message || 'Incorrect password.';
                                        errDiv.style.display = 'block';
                                    }
                                }
                            });
                        }
                    },
                });
            });
        }
    }
}
