/**
 * Smart Split – Authentication & Account Management Modal Component
 * Institutional Linear/Stripe-Grade Interface for Sign In, Registration & Emergency Recovery
 */

import { api } from '../api.js';
import { store } from '../state.js';
import { Toast } from './Toast.js';
import { Modal } from './Modal.js';
import { escapeHtml } from '../utils/formatters.js';
import { renderIcon } from '../utils/icons.js';

const PRESET_AVATARS = [
    { emoji: '👤', label: 'Default User', color: '#18352B' },
    { emoji: '💼', label: 'Executive', color: '#2e563e' },
    { emoji: '🛡️', label: 'Security', color: '#3730a3' },
    { emoji: '🧭', label: 'Navigator', color: '#28534e' },
    { emoji: '⚡', label: 'Lightning', color: '#8c6017' },
    { emoji: '💎', label: 'Prestige', color: '#5c436d' },
    { emoji: '🌟', label: 'Stellar', color: '#9c4127' },
    { emoji: '🚀', label: 'Pioneer', color: '#6e385e' },
];

export class AuthModal {
    static currentMode = 'login'; // 'login' | 'register' | 'recover' | 'recovery_key'
    static selectedEmoji = '👤';
    static selectedColor = '#18352B';
    static activeUser = null;
    static activeRecoveryCode = '';
    static onSuccessCallback = null;

    /**
     * Open the authentication modal.
     * @param {Object} [options]
     * @param {string} [options.initialMode='login'] 'login' | 'register' | 'recover'
     * @param {Function} [options.onSuccess] Callback when successfully authenticated
     */
    static open(options = {}) {
        AuthModal.currentMode = options.initialMode || 'login';
        AuthModal.selectedEmoji = '👤';
        AuthModal.selectedColor = '#18352B';
        AuthModal.activeUser = null;
        AuthModal.activeRecoveryCode = '';
        AuthModal.onSuccessCallback = options.onSuccess || null;

        AuthModal.renderModal();
    }

    /**
     * Close the authentication modal.
     */
    static close() {
        Modal.close();
    }

    /**
     * Render or refresh modal body based on currentMode.
     */
    static renderModal() {
        let title = 'Authentication';
        if (AuthModal.currentMode === 'login') title = 'Sign In';
        else if (AuthModal.currentMode === 'register') title = 'Create Account';
        else if (AuthModal.currentMode === 'recover') title = 'Reset Password';
        else if (AuthModal.currentMode === 'recovery_key') title = 'Emergency Recovery Key';

        Modal.open({
            title,
            size: 'sm',
            showFooter: false,
            content: AuthModal.getHtmlContent(),
            onMount: (overlay) => {
                AuthModal.attachListeners(overlay);
            },
        });
    }

    /**
     * Switch current view mode and re-render.
     * @param {string} mode 'login' | 'register' | 'recover' | 'recovery_key'
     */
    static switchMode(mode) {
        AuthModal.currentMode = mode;
        const bodyContainer = document.getElementById('modal-body-container');
        const heading = document.getElementById('modal-title-heading');
        const overlay = document.getElementById('modal-overlay');

        if (heading) {
            if (mode === 'login') heading.textContent = 'Sign In';
            else if (mode === 'register') heading.textContent = 'Create Account';
            else if (mode === 'recover') heading.textContent = 'Reset Password';
            else if (mode === 'recovery_key') heading.textContent = 'Emergency Recovery Key';
        }

        if (bodyContainer && overlay) {
            bodyContainer.innerHTML = AuthModal.getHtmlContent();
            AuthModal.attachListeners(overlay);
        } else {
            AuthModal.renderModal();
        }
    }

    /**
     * Generate HTML markup for the active mode.
     */
    static getHtmlContent() {
        const mode = AuthModal.currentMode;

        const tabsMarkup = (mode === 'login' || mode === 'register') ? `
            <div class="auth-tabs" style="display: flex; background: var(--surface-secondary, #f1f3f1); border-radius: var(--radius-sm, 6px); padding: 3px; margin-bottom: var(--space-4, 16px); border: 1px solid var(--border-color, #dde2de);">
                <button type="button" class="auth-tab-btn ${mode === 'login' ? 'active' : ''}" data-tab="login" style="flex: 1; padding: 7px 12px; font-size: var(--font-size-xs, 0.8rem); font-weight: 700; border: none; background: ${mode === 'login' ? 'var(--surface-primary, #ffffff)' : 'transparent'}; color: ${mode === 'login' ? 'var(--brand-primary, #18352b)' : 'var(--text-muted, #68706c)'}; border-radius: var(--radius-xs, 4px); cursor: pointer; box-shadow: ${mode === 'login' ? '0 1px 3px rgba(0,0,0,0.06)' : 'none'}; transition: all 0.15s ease; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                    ${renderIcon('logIn', { size: 14 })}
                    <span>Sign In</span>
                </button>
                <button type="button" class="auth-tab-btn ${mode === 'register' ? 'active' : ''}" data-tab="register" style="flex: 1; padding: 7px 12px; font-size: var(--font-size-xs, 0.8rem); font-weight: 700; border: none; background: ${mode === 'register' ? 'var(--surface-primary, #ffffff)' : 'transparent'}; color: ${mode === 'register' ? 'var(--brand-primary, #18352b)' : 'var(--text-muted, #68706c)'}; border-radius: var(--radius-xs, 4px); cursor: pointer; box-shadow: ${mode === 'register' ? '0 1px 3px rgba(0,0,0,0.06)' : 'none'}; transition: all 0.15s ease; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                    ${renderIcon('userPlus', { size: 14 })}
                    <span>Create Account</span>
                </button>
            </div>
        ` : '';

        if (mode === 'login') {
            return `
                ${tabsMarkup}
                <form id="auth-login-form" class="auth-form" novalidate>
                    <div id="auth-error-alert" class="auth-alert-error" style="display: none; background: var(--financial-debt-bg, #fdf1f1); border: 1px solid var(--financial-debt-border, #f7c8c8); color: var(--financial-debt, #c43d3d); padding: 8px 12px; border-radius: var(--radius-xs, 4px); font-size: var(--font-size-xs, 0.8rem); margin-bottom: var(--space-3, 12px); line-height: 1.4;"></div>
                    
                    <div class="form-group" style="margin-bottom: var(--space-3, 12px);">
                        <label class="form-label" for="auth-login-email" style="display: block; font-size: var(--font-size-xs, 0.75rem); font-weight: 700; text-transform: uppercase; color: var(--text-secondary, #575e5a); margin-bottom: 5px;">Email Address</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 10px; color: var(--text-muted, #68706c); pointer-events: none; display: flex; align-items: center;">
                                ${renderIcon('mail', { size: 15 })}
                            </span>
                            <input type="email" id="auth-login-email" class="form-input" placeholder="name@example.com" required autocomplete="email" style="width: 100%; padding-left: 32px; box-sizing: border-box;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: var(--space-2, 8px);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                            <label class="form-label" for="auth-login-password" style="font-size: var(--font-size-xs, 0.75rem); font-weight: 700; text-transform: uppercase; color: var(--text-secondary, #575e5a); margin: 0;">Password</label>
                            <button type="button" id="auth-forgot-link" style="font-size: var(--font-size-xs, 0.75rem); background: none; border: none; color: var(--brand-primary, #18352b); cursor: pointer; text-decoration: underline; padding: 0; font-weight: 600;">Forgot password?</button>
                        </div>
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 10px; color: var(--text-muted, #68706c); pointer-events: none; display: flex; align-items: center;">
                                ${renderIcon('lock', { size: 15 })}
                            </span>
                            <input type="password" id="auth-login-password" class="form-input" placeholder="••••••••" required autocomplete="current-password" style="width: 100%; padding-left: 32px; padding-right: 36px; box-sizing: border-box;">
                            <button type="button" class="btn-toggle-pwd" data-target="auth-login-password" title="Toggle password visibility" style="position: absolute; right: 8px; background: none; border: none; padding: 4px; color: var(--text-muted, #68706c); cursor: pointer; display: flex; align-items: center;">
                                ${renderIcon('eye', { size: 15 })}
                            </button>
                        </div>
                    </div>

                    <button type="submit" id="auth-login-submit" class="btn btn-primary btn-block" style="width: 100%; margin-top: var(--space-4, 16px); padding: 9px 16px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                        ${renderIcon('logIn', { size: 15 })}
                        <span>Sign In</span>
                    </button>
                </form>
            `;
        }

        if (mode === 'register') {
            return `
                ${tabsMarkup}
                <form id="auth-register-form" class="auth-form" novalidate>
                    <div id="auth-error-alert" class="auth-alert-error" style="display: none; background: var(--financial-debt-bg, #fdf1f1); border: 1px solid var(--financial-debt-border, #f7c8c8); color: var(--financial-debt, #c43d3d); padding: 8px 12px; border-radius: var(--radius-xs, 4px); font-size: var(--font-size-xs, 0.8rem); margin-bottom: var(--space-3, 12px); line-height: 1.4;"></div>

                    <div class="form-group" style="margin-bottom: var(--space-3, 12px);">
                        <label class="form-label" for="auth-reg-name" style="display: block; font-size: var(--font-size-xs, 0.75rem); font-weight: 700; text-transform: uppercase; color: var(--text-secondary, #575e5a); margin-bottom: 5px;">Display Name</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 10px; color: var(--text-muted, #68706c); pointer-events: none; display: flex; align-items: center;">
                                ${renderIcon('user', { size: 15 })}
                            </span>
                            <input type="text" id="auth-reg-name" class="form-input" placeholder="Jay" required autocomplete="name" style="width: 100%; padding-left: 32px; box-sizing: border-box;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: var(--space-3, 12px);">
                        <label class="form-label" for="auth-reg-email" style="display: block; font-size: var(--font-size-xs, 0.75rem); font-weight: 700; text-transform: uppercase; color: var(--text-secondary, #575e5a); margin-bottom: 5px;">Email Address</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 10px; color: var(--text-muted, #68706c); pointer-events: none; display: flex; align-items: center;">
                                ${renderIcon('mail', { size: 15 })}
                            </span>
                            <input type="email" id="auth-reg-email" class="form-input" placeholder="name@example.com" required autocomplete="email" style="width: 100%; padding-left: 32px; box-sizing: border-box;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: var(--space-3, 12px);">
                        <label class="form-label" for="auth-reg-password" style="display: block; font-size: var(--font-size-xs, 0.75rem); font-weight: 700; text-transform: uppercase; color: var(--text-secondary, #575e5a); margin-bottom: 5px;">Password (min. 8 characters)</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 10px; color: var(--text-muted, #68706c); pointer-events: none; display: flex; align-items: center;">
                                ${renderIcon('lock', { size: 15 })}
                            </span>
                            <input type="password" id="auth-reg-password" class="form-input" placeholder="••••••••" minlength="8" required autocomplete="new-password" style="width: 100%; padding-left: 32px; padding-right: 36px; box-sizing: border-box;">
                            <button type="button" class="btn-toggle-pwd" data-target="auth-reg-password" title="Toggle password visibility" style="position: absolute; right: 8px; background: none; border: none; padding: 4px; color: var(--text-muted, #68706c); cursor: pointer; display: flex; align-items: center;">
                                ${renderIcon('eye', { size: 15 })}
                            </button>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: var(--space-4, 16px);">
                        <label class="form-label" style="display: block; font-size: var(--font-size-xs, 0.75rem); font-weight: 700; text-transform: uppercase; color: var(--text-secondary, #575e5a); margin-bottom: 6px;">Profile Avatar Badge</label>
                        <div class="auth-emoji-grid" id="auth-emoji-picker" style="display: flex; gap: 6px; flex-wrap: wrap;">
                            ${PRESET_AVATARS.map((a) => `
                                <button type="button" class="auth-emoji-btn ${a.emoji === AuthModal.selectedEmoji ? 'active' : ''}" data-emoji="${a.emoji}" data-color="${a.color}" title="${a.label}" style="font-size: 1.1rem; padding: 6px 10px; border-radius: var(--radius-xs, 4px); border: 1px solid ${a.emoji === AuthModal.selectedEmoji ? 'var(--brand-primary, #18352b)' : 'var(--border-color, #dde2de)'}; background: ${a.emoji === AuthModal.selectedEmoji ? 'var(--brand-primary-soft, #e8f0ec)' : 'var(--surface-primary, #ffffff)'}; cursor: pointer; transition: all 0.15s ease;">
                                    ${a.emoji}
                                </button>
                            `).join('')}
                        </div>
                    </div>

                    <button type="submit" id="auth-reg-submit" class="btn btn-primary btn-block" style="width: 100%; padding: 9px 16px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                        ${renderIcon('userPlus', { size: 15 })}
                        <span>Create Account</span>
                    </button>
                </form>
            `;
        }

        if (mode === 'recover') {
            return `
                <div style="margin-bottom: var(--space-3, 12px);">
                    <button type="button" id="auth-back-to-login" style="display: inline-flex; align-items: center; gap: 4px; font-size: var(--font-size-xs, 0.75rem); background: none; border: none; color: var(--text-secondary, #575e5a); cursor: pointer; padding: 0; margin-bottom: var(--space-2, 8px); font-weight: 600;">
                        ← Back to Sign In
                    </button>
                    <p style="font-size: var(--font-size-sm, 0.85rem); color: var(--text-muted, #68706c); margin: 0; line-height: 1.4;">
                        Enter your account email, your emergency recovery key (<code style="font-family: var(--font-mono); font-weight: 700;">SMART-XXXX-XXXX</code>), and choose a new password.
                    </p>
                </div>

                <form id="auth-recover-form" class="auth-form" novalidate>
                    <div id="auth-error-alert" class="auth-alert-error" style="display: none; background: var(--financial-debt-bg, #fdf1f1); border: 1px solid var(--financial-debt-border, #f7c8c8); color: var(--financial-debt, #c43d3d); padding: 8px 12px; border-radius: var(--radius-xs, 4px); font-size: var(--font-size-xs, 0.8rem); margin-bottom: var(--space-3, 12px); line-height: 1.4;"></div>

                    <div class="form-group" style="margin-bottom: var(--space-3, 12px);">
                        <label class="form-label" for="auth-rec-email" style="display: block; font-size: var(--font-size-xs, 0.75rem); font-weight: 700; text-transform: uppercase; color: var(--text-secondary, #575e5a); margin-bottom: 5px;">Account Email</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 10px; color: var(--text-muted, #68706c); pointer-events: none; display: flex; align-items: center;">
                                ${renderIcon('mail', { size: 15 })}
                            </span>
                            <input type="email" id="auth-rec-email" class="form-input" placeholder="name@example.com" required autocomplete="email" style="width: 100%; padding-left: 32px; box-sizing: border-box;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: var(--space-3, 12px);">
                        <label class="form-label" for="auth-rec-code" style="display: block; font-size: var(--font-size-xs, 0.75rem); font-weight: 700; text-transform: uppercase; color: var(--text-secondary, #575e5a); margin-bottom: 5px;">Emergency Recovery Key</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 10px; color: var(--text-muted, #68706c); pointer-events: none; display: flex; align-items: center;">
                                ${renderIcon('key', { size: 15 })}
                            </span>
                            <input type="text" id="auth-rec-code" class="form-input" placeholder="SMART-XXXX-XXXX" style="width: 100%; padding-left: 32px; box-sizing: border-box; font-family: var(--font-mono, monospace); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;" required>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: var(--space-4, 16px);">
                        <label class="form-label" for="auth-rec-password" style="display: block; font-size: var(--font-size-xs, 0.75rem); font-weight: 700; text-transform: uppercase; color: var(--text-secondary, #575e5a); margin-bottom: 5px;">New Password (min. 8 characters)</label>
                        <div style="position: relative; display: flex; align-items: center;">
                            <span style="position: absolute; left: 10px; color: var(--text-muted, #68706c); pointer-events: none; display: flex; align-items: center;">
                                ${renderIcon('lock', { size: 15 })}
                            </span>
                            <input type="password" id="auth-rec-password" class="form-input" placeholder="••••••••" minlength="8" required autocomplete="new-password" style="width: 100%; padding-left: 32px; padding-right: 36px; box-sizing: border-box;">
                            <button type="button" class="btn-toggle-pwd" data-target="auth-rec-password" title="Toggle password visibility" style="position: absolute; right: 8px; background: none; border: none; padding: 4px; color: var(--text-muted, #68706c); cursor: pointer; display: flex; align-items: center;">
                                ${renderIcon('eye', { size: 15 })}
                            </button>
                        </div>
                    </div>

                    <button type="submit" id="auth-rec-submit" class="btn btn-primary btn-block" style="width: 100%; padding: 9px 16px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                        ${renderIcon('shieldCheck', { size: 15 })}
                        <span>Reset Password & Log In</span>
                    </button>
                </form>
            `;
        }

        if (mode === 'recovery_key') {
            const recoveryCode = AuthModal.activeRecoveryCode || 'SMART-XXXX-XXXX';
            return `
                <div style="text-align: center; margin-bottom: var(--space-3, 12px);">
                    <div style="display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 9999px; background: var(--brand-primary-soft, #e8f0ec); color: var(--brand-primary, #18352b); margin-bottom: var(--space-2, 8px);">
                        ${renderIcon('key', { size: 22 })}
                    </div>
                    <h4 style="margin: 0 0 6px 0; font-size: 1.05rem; font-weight: 800; color: var(--text-primary);">Save Your Emergency Recovery Key</h4>
                    <p style="font-size: var(--font-size-xs, 0.78rem); color: var(--text-muted, #68706c); margin: 0; line-height: 1.45;">
                        Smart Split does not store unhashed passwords. This cryptographic key is the <strong>ONLY</strong> way to recover your account if you forget your password.
                    </p>
                </div>

                <div style="background: var(--surface-secondary, #f1f3f1); border: 1px solid var(--border-color, #dde2de); border-radius: var(--radius-sm, 6px); padding: var(--space-3, 12px); text-align: center; margin: var(--space-3, 12px) 0;">
                    <div style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted, #68706c); font-weight: 800; margin-bottom: 4px;">
                        One-Time Emergency Recovery Key
                    </div>
                    <div id="auth-recovery-code-val" style="font-family: var(--font-mono, monospace); font-size: 1.35rem; font-weight: 800; letter-spacing: 0.08em; color: var(--brand-primary, #18352b); user-select: all;">
                        ${escapeHtml(recoveryCode)}
                    </div>
                </div>

                <div style="display: flex; gap: var(--space-2, 8px); margin-bottom: var(--space-3, 12px);">
                    <button type="button" id="btn-auth-copy-key" class="btn btn-secondary btn-sm" style="flex: 1; padding: 7px 10px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                        ${renderIcon('copy', { size: 14 })}
                        <span>Copy Key</span>
                    </button>
                    <button type="button" id="btn-auth-download-key" class="btn btn-secondary btn-sm" style="flex: 1; padding: 7px 10px; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                        ${renderIcon('download', { size: 14 })}
                        <span>Download as .txt</span>
                    </button>
                </div>

                <label style="display: flex; align-items: flex-start; gap: 8px; margin: var(--space-3, 12px) 0; font-size: var(--font-size-xs, 0.78rem); color: var(--text-secondary, #575e5a); cursor: pointer; line-height: 1.4;">
                    <input type="checkbox" id="chk-auth-recovery-ack" style="margin-top: 2px; cursor: pointer;">
                    <span>I have saved this emergency recovery key in a safe location. I understand that my account cannot be recovered without it.</span>
                </label>

                <button type="button" id="btn-auth-recovery-done" class="btn btn-primary btn-block" style="width: 100%; padding: 9px 16px; font-weight: 700; margin-top: 4px;" disabled>
                    Done / Continue to Workspace
                </button>
            `;
        }

        return '';
    }

    /**
     * Attach DOM event listeners to the modal container.
     * @param {HTMLElement} overlay
     */
    static attachListeners(overlay) {
        // 1. Tab buttons
        overlay.querySelectorAll('.auth-tab-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const targetTab = btn.getAttribute('data-tab');
                if (targetTab) {
                    AuthModal.switchMode(targetTab);
                }
            });
        });

        // 2. Forgot Password link
        const forgotLink = overlay.querySelector('#auth-forgot-link');
        if (forgotLink) {
            forgotLink.addEventListener('click', () => {
                AuthModal.switchMode('recover');
            });
        }

        // 3. Back to Sign In link
        const backLink = overlay.querySelector('#auth-back-to-login');
        if (backLink) {
            backLink.addEventListener('click', () => {
                AuthModal.switchMode('login');
            });
        }

        // 4. Password show/hide toggle
        overlay.querySelectorAll('.btn-toggle-pwd').forEach((toggleBtn) => {
            toggleBtn.addEventListener('click', () => {
                const targetId = toggleBtn.getAttribute('data-target');
                const input = overlay.querySelector(`#${targetId}`);
                if (!input) return;
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                toggleBtn.innerHTML = renderIcon(isPassword ? 'eyeOff' : 'eye', { size: 15 });
            });
        });

        // 5. Avatar picker buttons
        overlay.querySelectorAll('.auth-emoji-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const emoji = btn.getAttribute('data-emoji');
                const color = btn.getAttribute('data-color') || '#18352B';
                if (emoji) {
                    AuthModal.selectedEmoji = emoji;
                    AuthModal.selectedColor = color;
                    overlay.querySelectorAll('.auth-emoji-btn').forEach((b) => {
                        const isMatch = b.getAttribute('data-emoji') === emoji;
                        b.classList.toggle('active', isMatch);
                        b.style.borderColor = isMatch ? 'var(--brand-primary, #18352b)' : 'var(--border-color, #dde2de)';
                        b.style.background = isMatch ? 'var(--brand-primary-soft, #e8f0ec)' : 'var(--surface-primary, #ffffff)';
                    });
                }
            });
        });

        // 6. Sign In submission
        const loginForm = overlay.querySelector('#auth-login-form');
        if (loginForm) {
            loginForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const emailInput = overlay.querySelector('#auth-login-email');
                const passInput = overlay.querySelector('#auth-login-password');
                const submitBtn = overlay.querySelector('#auth-login-submit');
                const errorAlert = overlay.querySelector('#auth-error-alert');

                const email = emailInput ? emailInput.value.trim() : '';
                const password = passInput ? passInput.value : '';

                if (errorAlert) errorAlert.style.display = 'none';

                if (!email || !password) {
                    if (errorAlert) {
                        errorAlert.textContent = 'Please enter both your email address and password.';
                        errorAlert.style.display = 'block';
                    }
                    return;
                }

                try {
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Signing in...';
                    }

                    const res = await api.login({ email, password });
                    const user = res.data.user;

                    store.setState({
                        currentUser: user,
                        isAuthenticated: true,
                        authLoading: false,
                    });

                    Toast.success(`Welcome back, ${user.display_name}!`);
                    AuthModal.close();

                    if (typeof AuthModal.onSuccessCallback === 'function') {
                        AuthModal.onSuccessCallback(user);
                    }
                } catch (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = `${renderIcon('logIn', { size: 15 })} <span>Sign In</span>`;
                    }
                    if (errorAlert) {
                        errorAlert.textContent = err.message || 'Invalid email or password.';
                        errorAlert.style.display = 'block';
                    }
                }
            });
        }

        // 7. Register submission
        const regForm = overlay.querySelector('#auth-register-form');
        if (regForm) {
            regForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const nameInput = overlay.querySelector('#auth-reg-name');
                const emailInput = overlay.querySelector('#auth-reg-email');
                const passInput = overlay.querySelector('#auth-reg-password');
                const submitBtn = overlay.querySelector('#auth-reg-submit');
                const errorAlert = overlay.querySelector('#auth-error-alert');

                const displayName = nameInput ? nameInput.value.trim() : '';
                const email = emailInput ? emailInput.value.trim() : '';
                const password = passInput ? passInput.value : '';

                if (errorAlert) errorAlert.style.display = 'none';

                if (!email || !password) {
                    if (errorAlert) {
                        errorAlert.textContent = 'Please fill in all required fields.';
                        errorAlert.style.display = 'block';
                    }
                    return;
                }

                if (password.length < 8) {
                    if (errorAlert) {
                        errorAlert.textContent = 'Password must be at least 8 characters long.';
                        errorAlert.style.display = 'block';
                    }
                    return;
                }

                try {
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Creating account...';
                    }

                    const res = await api.register({
                        email,
                        password,
                        display_name: displayName,
                        avatar_emoji: AuthModal.selectedEmoji,
                        avatar_color: AuthModal.selectedColor,
                    });

                    const user = res.data.user;
                    const recoveryCode = res.data.recovery_code;

                    store.setState({
                        currentUser: user,
                        isAuthenticated: true,
                        authLoading: false,
                    });

                    AuthModal.activeUser = user;
                    AuthModal.activeRecoveryCode = recoveryCode;
                    AuthModal.switchMode('recovery_key');
                } catch (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = `${renderIcon('userPlus', { size: 15 })} <span>Create Account</span>`;
                    }
                    if (errorAlert) {
                        errorAlert.textContent = err.message || 'Registration failed. Please try again.';
                        errorAlert.style.display = 'block';
                    }
                }
            });
        }

        // 8. Password Recovery submission
        const recoverForm = overlay.querySelector('#auth-recover-form');
        if (recoverForm) {
            recoverForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const emailInput = overlay.querySelector('#auth-rec-email');
                const codeInput = overlay.querySelector('#auth-rec-code');
                const passInput = overlay.querySelector('#auth-rec-password');
                const submitBtn = overlay.querySelector('#auth-rec-submit');
                const errorAlert = overlay.querySelector('#auth-error-alert');

                const email = emailInput ? emailInput.value.trim() : '';
                const recoveryCode = codeInput ? codeInput.value.trim().toUpperCase() : '';
                const newPassword = passInput ? passInput.value : '';

                if (errorAlert) errorAlert.style.display = 'none';

                if (!email || !recoveryCode || !newPassword) {
                    if (errorAlert) {
                        errorAlert.textContent = 'Please fill in all recovery fields.';
                        errorAlert.style.display = 'block';
                    }
                    return;
                }

                if (newPassword.length < 8) {
                    if (errorAlert) {
                        errorAlert.textContent = 'New password must be at least 8 characters long.';
                        errorAlert.style.display = 'block';
                    }
                    return;
                }

                try {
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Verifying key...';
                    }

                    const res = await api.recoverPassword({
                        email,
                        recovery_code: recoveryCode,
                        new_password: newPassword,
                    });

                    const user = res.data.user;
                    const newRecoveryCode = res.data.recovery_code || res.data.new_recovery_code;

                    store.setState({
                        currentUser: user,
                        isAuthenticated: true,
                        authLoading: false,
                    });

                    AuthModal.activeUser = user;
                    AuthModal.activeRecoveryCode = newRecoveryCode;
                    AuthModal.switchMode('recovery_key');
                } catch (err) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = `${renderIcon('shieldCheck', { size: 15 })} <span>Reset Password & Log In</span>`;
                    }
                    if (errorAlert) {
                        errorAlert.textContent = err.message || 'Password reset failed. Please check your recovery key.';
                        errorAlert.style.display = 'block';
                    }
                }
            });
        }

        // 9. Recovery Key Action Handlers
        const copyKeyBtn = overlay.querySelector('#btn-auth-copy-key');
        if (copyKeyBtn) {
            copyKeyBtn.addEventListener('click', async () => {
                const code = AuthModal.activeRecoveryCode || '';
                if (!code) return;
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(code);
                    } else {
                        const textArea = document.createElement('textarea');
                        textArea.value = code;
                        textArea.style.position = 'fixed';
                        textArea.style.left = '-999999px';
                        document.body.appendChild(textArea);
                        textArea.focus();
                        textArea.select();
                        document.execCommand('copy');
                        textArea.remove();
                    }
                    copyKeyBtn.innerHTML = `${renderIcon('check', { size: 14 })} <span>Copied!</span>`;
                    Toast.success('Recovery key copied to clipboard.');
                    setTimeout(() => {
                        if (copyKeyBtn) copyKeyBtn.innerHTML = `${renderIcon('copy', { size: 14 })} <span>Copy Key</span>`;
                    }, 3000);
                } catch (err) {
                    Toast.info(`Key: ${code}`, 8000);
                }
            });
        }

        const downloadKeyBtn = overlay.querySelector('#btn-auth-download-key');
        if (downloadKeyBtn) {
            downloadKeyBtn.addEventListener('click', () => {
                const code = AuthModal.activeRecoveryCode || '';
                const email = AuthModal.activeUser?.email || 'account';
                if (!code) return;

                const textContent = `SMART SPLIT EMERGENCY RECOVERY KEY\n` +
                    `=========================================\n` +
                    `Account Email : ${email}\n` +
                    `Recovery Key  : ${code}\n` +
                    `Created At    : ${new Date().toISOString()}\n\n` +
                    `IMPORTANT: Keep this file secure and private.\n` +
                    `Smart Split cannot recover your account without this key.\n`;

                const blob = new Blob([textContent], { type: 'text/plain;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `smartsplit-recovery-key-${email.split('@')[0]}.txt`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                Toast.success('Recovery key downloaded.');
            });
        }

        const ackCheckbox = overlay.querySelector('#chk-auth-recovery-ack');
        const doneBtn = overlay.querySelector('#btn-auth-recovery-done');
        if (ackCheckbox && doneBtn) {
            ackCheckbox.addEventListener('change', () => {
                doneBtn.disabled = !ackCheckbox.checked;
            });

            doneBtn.addEventListener('click', () => {
                Toast.success('Account successfully secured.');
                AuthModal.close();
                if (typeof AuthModal.onSuccessCallback === 'function') {
                    AuthModal.onSuccessCallback(AuthModal.activeUser);
                }
            });
        }
    }
}
