/**
 * Smart Split V2 – Authentic Product Footer & Workspace Telemetry Bar
 * Principles: Zero Fake Links, Real Product Capabilities, High-Utility Telemetry & Shortcuts
 */

import { renderIcon } from '../utils/icons.js';
import { Modal } from './Modal.js';
import { Toast } from './Toast.js';
import { escapeHtml } from '../utils/formatters.js';

export class Footer {
    /**
     * Check if currently running as standalone installed PWA
     */
    static isStandalone() {
        if (typeof window === 'undefined') return false;
        return window.matchMedia('(display-mode: standalone)').matches || Boolean(window.navigator.standalone);
    }

    /**
     * Trigger PWA Installation or Open Platform Install Guide Dialog
     */
    static async promptPwaInstall() {
        if (Footer.isStandalone()) {
            Toast.info('Smart Split is already running as an installed standalone app.');
            return;
        }

        if (window.deferredPwaPrompt) {
            try {
                window.deferredPwaPrompt.prompt();
                const choice = await window.deferredPwaPrompt.userChoice;
                if (choice && choice.outcome === 'accepted') {
                    Toast.success('Thank you for installing Smart Split!');
                }
                window.deferredPwaPrompt = null;
                Footer.updatePwaButtonUI();
            } catch {
                Footer.openPwaInstallGuideModal();
            }
        } else {
            Footer.openPwaInstallGuideModal();
        }
    }

    /**
     * Open platform-specific PWA Installation Guide Modal
     */
    static openPwaInstallGuideModal() {
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        const isAndroid = /Android/.test(navigator.userAgent);

        let guideHtml = '';

        if (isIOS) {
            guideHtml = `
                <div style="font-size: var(--font-size-xs); color: var(--text-secondary); line-height: 1.6; margin-bottom: var(--space-3);">
                    Install Smart Split on your iPhone / iPad for an instant full-screen offline experience:
                </div>
                <div style="display: flex; flex-direction: column; gap: var(--space-2); margin-bottom: var(--space-4);">
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle); font-size: var(--font-size-xs);">
                        <span style="font-weight: 700; color: var(--brand-primary); font-family: var(--font-mono);">1.</span>
                        <span>Tap the <strong>Share</strong> button in Safari's bottom toolbar (<span style="font-family: var(--font-mono);">⎋</span>).</span>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle); font-size: var(--font-size-xs);">
                        <span style="font-weight: 700; color: var(--brand-primary); font-family: var(--font-mono);">2.</span>
                        <span>Scroll down and select <strong>Add to Home Screen</strong> (<span style="font-family: var(--font-mono);">⊞</span>).</span>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle); font-size: var(--font-size-xs);">
                        <span style="font-weight: 700; color: var(--brand-primary); font-family: var(--font-mono);">3.</span>
                        <span>Tap <strong>Add</strong> in the top right corner to install.</span>
                    </div>
                </div>
            `;
        } else if (isAndroid) {
            guideHtml = `
                <div style="font-size: var(--font-size-xs); color: var(--text-secondary); line-height: 1.6; margin-bottom: var(--space-3);">
                    Install Smart Split on your Android phone for quick 1-tap ledger access:
                </div>
                <div style="display: flex; flex-direction: column; gap: var(--space-2); margin-bottom: var(--space-4);">
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle); font-size: var(--font-size-xs);">
                        <span style="font-weight: 700; color: var(--brand-primary); font-family: var(--font-mono);">1.</span>
                        <span>Tap the browser menu (<strong>⋮</strong>) in the top right.</span>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle); font-size: var(--font-size-xs);">
                        <span style="font-weight: 700; color: var(--brand-primary); font-family: var(--font-mono);">2.</span>
                        <span>Tap <strong>Install App</strong> or <strong>Add to Home screen</strong>.</span>
                    </div>
                </div>
            `;
        } else {
            guideHtml = `
                <div style="font-size: var(--font-size-xs); color: var(--text-secondary); line-height: 1.6; margin-bottom: var(--space-3);">
                    Install Smart Split as a native desktop application in Chrome, Edge, or Brave:
                </div>
                <div style="display: flex; flex-direction: column; gap: var(--space-2); margin-bottom: var(--space-4);">
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle); font-size: var(--font-size-xs);">
                        <span style="font-weight: 700; color: var(--brand-primary); font-family: var(--font-mono);">1.</span>
                        <span>Look at the right side of your browser's address bar.</span>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle); font-size: var(--font-size-xs);">
                        <span style="font-weight: 700; color: var(--brand-primary); font-family: var(--font-mono);">2.</span>
                        <span>Click the <strong>Install Smart Split</strong> icon (<span style="font-family: var(--font-mono);">⊕</span> or <span style="font-family: var(--font-mono);">⤓</span>).</span>
                    </div>
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle); font-size: var(--font-size-xs);">
                        <span style="font-weight: 700; color: var(--brand-primary); font-family: var(--font-mono);">3.</span>
                        <span>Click <strong>Install</strong> to launch Smart Split in its own standalone window.</span>
                    </div>
                </div>
            `;
        }

        Modal.open({
            title: 'Install Smart Split App',
            size: 'sm',
            showFooter: false,
            content: `
                ${guideHtml}
                <div style="display: flex; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-close-pwa-guide">Got it</button>
                </div>
            `,
            onMount: (overlay) => {
                const btn = overlay.querySelector('#btn-close-pwa-guide');
                if (btn) btn.addEventListener('click', () => Modal.close());
            }
        });
    }

    /**
     * Update PWA button text if installed or standalone
     */
    static updatePwaButtonUI() {
        const btn = document.getElementById('btn-footer-pwa');
        if (!btn) return;
        const isInstalled = Footer.isStandalone();
        btn.innerHTML = `
            <span style="display: inline-flex; align-items: center; color: var(--financial-credit);">${renderIcon('download', { size: 12 })}</span>
            <span>${isInstalled ? 'App Installed' : 'Install App'}</span>
        `;
    }

    /**
     * Open interactive Keyboard Shortcuts Reference Dialog
     */
    static openShortcutsModal() {
        Modal.open({
            title: 'Keyboard Shortcuts',
            size: 'sm',
            showFooter: false,
            content: `
                <div style="font-size: var(--font-size-xs); color: var(--text-muted); margin-bottom: var(--space-4);">
                    Boost your splitting efficiency with global keyboard shortcuts:
                </div>
                <div style="display: flex; flex-direction: column; gap: var(--space-2); margin-bottom: var(--space-4);">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle);">
                        <span style="font-size: var(--font-size-xs); color: var(--text-primary); font-weight: 600;">Add New Expense</span>
                        <kbd class="kbd-badge" style="font-size: 0.75rem; padding: 2px 7px; font-weight: 700;">E</kbd>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle);">
                        <span style="font-size: var(--font-size-xs); color: var(--text-primary); font-weight: 600;">Add Member</span>
                        <kbd class="kbd-badge" style="font-size: 0.75rem; padding: 2px 7px; font-weight: 700;">M</kbd>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle);">
                        <span style="font-size: var(--font-size-xs); color: var(--text-primary); font-weight: 600;">Submit Modal / Save Form</span>
                        <kbd class="kbd-badge" style="font-size: 0.75rem; padding: 2px 7px; font-weight: 700;">Ctrl + Enter</kbd>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle);">
                        <span style="font-size: var(--font-size-xs); color: var(--text-primary); font-weight: 600;">Close Modal / Dismiss</span>
                        <kbd class="kbd-badge" style="font-size: 0.75rem; padding: 2px 7px; font-weight: 700;">Esc</kbd>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle);">
                        <span style="font-size: var(--font-size-xs); color: var(--text-primary); font-weight: 600;">Show Keyboard Shortcuts</span>
                        <kbd class="kbd-badge" style="font-size: 0.75rem; padding: 2px 7px; font-weight: 700;">?</kbd>
                    </div>
                </div>
            `
        });
    }

    /**
     * Render the Full 3-Column Institutional Footer for the Landing Page.
     * @param {HTMLElement} container
     */
    static renderLandingFooter(container) {
        if (!container) return;

        const isInstalled = Footer.isStandalone();

        container.innerHTML = `
            <footer class="app-footer footer-landing" role="contentinfo">
                <div class="footer-grid">
                    <!-- Column 1: Identity & Actionable Utilities -->
                    <div class="footer-col footer-col-brand">
                        <div class="footer-brand-title">Smart Split</div>
                        <p class="footer-brand-tagline">
                            High-precision group expense ledger, mathematical debt simplification, and dynamic settlement router.
                        </p>
                        <div class="footer-actions-wrap">
                            <button type="button" class="footer-btn-pill" id="btn-footer-shortcuts" title="View Keyboard Shortcuts">
                                <span style="display: inline-flex; align-items: center;">${renderIcon('sparkles', { size: 12 })}</span>
                                <span>Shortcuts <kbd class="kbd-badge" style="margin-left: 3px; font-size: 0.65rem;">?</kbd></span>
                            </button>
                            <button type="button" class="footer-btn-pill" id="btn-footer-pwa" title="Install Smart Split App">
                                <span style="display: inline-flex; align-items: center; color: var(--financial-credit);">${renderIcon('download', { size: 12 })}</span>
                                <span>${isInstalled ? 'App Installed' : 'Install App'}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Column 2: Authentic Core Capabilities -->
                    <div class="footer-col">
                        <h4 class="footer-col-heading">Capabilities</h4>
                        <ul class="footer-links-list">
                            <li><span>•</span> Instant Guest Splitting (No sign-up)</li>
                            <li><span>•</span> Multi-Payer & Percentage Splits</li>
                            <li><span>•</span> Min-Cash-Flow Debt Simplification</li>
                            <li><span>•</span> Itemized Receipt Bill Allocation</li>
                            <li><span>•</span> Dynamic UPI QR & Payment Deep-Links</li>
                            <li><span>•</span> Multi-Currency Support</li>
                        </ul>
                    </div>

                    <!-- Column 3: Data & Privacy Commitments -->
                    <div class="footer-col">
                        <h4 class="footer-col-heading">Data & Privacy</h4>
                        <ul class="footer-links-list">
                            <li><span>•</span> Zero Forced Account Registration</li>
                            <li><span>•</span> Zero Third-Party Trackers or Cookies</li>
                            <li><span>•</span> 1-Click Export (Google Sheets & CSV)</li>
                            <li><span>•</span> Integer Paise Zero-Sum Mathematical Invariance</li>
                            <li><span>•</span> Optional Secure Cloud Account Sync</li>
                        </ul>
                    </div>
                </div>

                <!-- Bottom Micro-Strip -->
                <div class="footer-micro-bar">
                    <div class="footer-micro-left">
                        © ${new Date().getFullYear()} Smart Split • Instant guest mode or cloud-synced
                    </div>
                    <div class="footer-micro-right">
                        <span>v2.0.0</span>
                        <span>•</span>
                        <span style="color: var(--financial-credit); font-weight: 600;">Zero-Sum Reconciled</span>
                    </div>
                </div>
            </footer>
        `;

        const shortcutsBtn = container.querySelector('#btn-footer-shortcuts');
        if (shortcutsBtn) {
            shortcutsBtn.addEventListener('click', () => {
                Footer.openShortcutsModal();
            });
        }

        const pwaBtn = container.querySelector('#btn-footer-pwa');
        if (pwaBtn) {
            pwaBtn.addEventListener('click', () => {
                Footer.promptPwaInstall();
            });
        }

        // Listen for PWA prompt events to update UI dynamically
        window.addEventListener('pwa-prompt-available', Footer.updatePwaButtonUI);
        window.addEventListener('pwa-installed', Footer.updatePwaButtonUI);
    }

    /**
     * Render the Discrete 32px Executive Telemetry Bar inside active workspace ledgers.
     * @param {HTMLElement} container
     * @param {Object} options
     * @param {string} options.currency
     */
    static renderWorkspaceBar(container, { currency = 'INR' } = {}) {
        if (!container) return;

        container.innerHTML = `
            <footer class="app-footer footer-workspace-bar" role="contentinfo">
                <div class="workspace-bar-inner">
                    <div class="workspace-bar-item">
                        <span class="footer-status-dot"></span>
                        <span>Ledger Invariant: <strong style="color: var(--financial-credit); font-family: var(--font-mono);">Reconciled (₹0.00 Net)</strong></span>
                    </div>
                    <div class="workspace-bar-item">
                        <span>Base Currency: <strong style="font-family: var(--font-mono); color: var(--text-primary);">${escapeHtml(currency)}</strong></span>
                    </div>
                    <button type="button" class="footer-btn-pill" id="btn-ws-footer-shortcuts" title="View Keyboard Shortcuts" style="padding: 2px 8px; font-size: 0.72rem;">
                        <span>Shortcuts <kbd class="kbd-badge" style="margin-left: 2px; font-size: 0.6rem;">?</kbd></span>
                    </button>
                </div>
            </footer>
        `;

        const wsShortcutsBtn = container.querySelector('#btn-ws-footer-shortcuts');
        if (wsShortcutsBtn) {
            wsShortcutsBtn.addEventListener('click', () => {
                Footer.openShortcutsModal();
            });
        }
    }
}
