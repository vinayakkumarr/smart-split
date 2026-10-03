/**
 * Smart Split V2 – Executive Group Overview & Metrics Header
 * Principles: Crisp Financial Metrics, Editorial Hierarchy, High-Contrast Actions
 */

import { Modal } from './Modal.js';
import { Toast } from './Toast.js';
import { AuthModal } from './AuthModal.js';
import { FailedMutationsModal } from './FailedMutationsModal.js';
import { QRCode } from '../utils/qrcode.js';
import { api } from '../api.js';
import { store } from '../state.js';
import { offlineManager } from '../utils/offline.js';
import { escapeHtml, formatDate, formatCurrency, getCurrencySymbol } from '../utils/formatters.js';
import { renderIcon } from '../utils/icons.js';

export class GroupHeader {
    /**
     * Retrieve stored spending budget for a workspace token.
     * @param {string} token
     * @returns {number|null}
     */
    static getBudget(token) {
        if (!token || typeof localStorage === 'undefined') return null;
        try {
            const raw = localStorage.getItem(`smartsplit_budget_${token}`);
            if (raw === null || raw === '') return null;
            const parsed = parseFloat(raw);
            return isNaN(parsed) || parsed <= 0 ? null : parsed;
        } catch {
            return null;
        }
    }

    /**
     * Set or remove stored spending budget for a workspace token.
     * @param {string} token
     * @param {number|null} amount
     */
    static setBudget(token, amount) {
        if (!token || typeof localStorage === 'undefined') return;
        try {
            if (amount === null || amount === undefined || amount === '' || isNaN(amount) || Number(amount) <= 0) {
                localStorage.removeItem(`smartsplit_budget_${token}`);
            } else {
                localStorage.setItem(`smartsplit_budget_${token}`, String(Number(amount)));
            }
        } catch {
            // Silently handle storage errors
        }
    }

    /**
     * Compute budget consumption metrics, progress width, and dynamic threshold styling.
     * @param {number} totalSpendCents
     * @param {number} budgetAmount Major currency units (e.g. 50000 for ₹50,000)
     * @returns {Object|null}
     */
    static calculateBudgetProgress(totalSpendCents, budgetAmount) {
        if (!budgetAmount || budgetAmount <= 0) return null;

        const spentMajor = (typeof totalSpendCents === 'number' && !isNaN(totalSpendCents) ? totalSpendCents : 0) / 100;
        const percentage = (spentMajor / budgetAmount) * 100;
        const visualWidth = Math.min(100, Math.max(0, percentage));

        let statusColor = 'var(--financial-credit, #059669)';
        let statusClass = 'budget-safe';
        if (percentage > 90) {
            statusColor = 'var(--financial-debt, #dc2626)';
            statusClass = 'budget-danger';
        } else if (percentage >= 75) {
            statusColor = 'var(--financial-warning, #d97706)';
            statusClass = 'budget-warning';
        }

        return {
            budgetAmount,
            spentMajor,
            percentage,
            percentageFormatted: `${percentage.toFixed(1)}%`,
            visualWidth,
            statusColor,
            statusClass,
            isExceeded: spentMajor > budgetAmount,
        };
    }

    /**
     * Open modal dialog to define, update, or clear workspace spending budget target.
     * @param {Object} options
     * @param {string} options.token
     * @param {number|null} [options.currentBudget]
     * @param {string} [options.currency]
     * @param {Function} [options.onSave]
     */
    static openBudgetModal({ token, currentBudget = null, currency = 'INR', onSave = null }) {
        if (!token) return;

        const modalContent = `
            <form id="form-budget-target" style="display: flex; flex-direction: column; gap: var(--space-4);" onsubmit="return false;">
                <p style="margin: 0; font-size: var(--font-size-sm); color: var(--text-secondary); line-height: 1.5;">
                    Set a total spending target for this workspace to track spending progress and monitor financial ceiling in real-time.
                </p>
                <div class="form-group" style="display: flex; flex-direction: column; gap: 6px;">
                    <label for="input-budget-limit" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary);">
                        Target Budget Ceiling (${escapeHtml(currency)})
                    </label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <span style="position: absolute; left: 12px; font-weight: 700; color: var(--text-muted); font-family: var(--font-mono); font-size: 1rem; pointer-events: none;">
                            ${escapeHtml(getCurrencySymbol(currency))}
                        </span>
                        <input
                            type="number"
                            id="input-budget-limit"
                            name="budget_limit"
                            class="form-input"
                            placeholder="e.g. 50000"
                            step="any"
                            min="1"
                            value="${currentBudget !== null ? currentBudget : ''}"
                            style="width: 100%; padding-left: 2.2rem; font-family: var(--font-mono); font-size: 1.05rem; font-weight: 600;"
                            required
                            autofocus
                        />
                    </div>
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        Stored locally in browser. Color coding: Safe (&lt;75%), Warning (75–90%), Alert (&gt;90%).
                    </span>
                </div>
                ${currentBudget !== null ? `
                    <div style="padding-top: var(--space-3); border-top: 1px solid var(--border-subtle); display: flex; justify-content: flex-start;">
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-clear-budget-target" style="color: var(--financial-debt); border-color: var(--border-color);">
                            Clear Budget Limit
                        </button>
                    </div>
                ` : ''}
            </form>
        `;

        Modal.open({
            title: currentBudget !== null ? 'Edit Spending Budget Target' : 'Set Workspace Spending Budget',
            content: modalContent,
            confirmText: currentBudget !== null ? 'Save Target' : 'Set Budget Target',
            size: 'sm',
            onMount: (overlay) => {
                const clearBtn = overlay.querySelector('#btn-clear-budget-target');
                if (clearBtn) {
                    clearBtn.addEventListener('click', () => {
                        GroupHeader.setBudget(token, null);
                        Toast.info('Spending budget target cleared.');
                        Modal.close();
                        if (typeof onSave === 'function') onSave();
                    });
                }

                const form = overlay.querySelector('#form-budget-target');
                if (form) {
                    form.addEventListener('submit', (e) => {
                        e.preventDefault();
                        const confirmBtn = overlay.querySelector('.modal-btn-confirm');
                        if (confirmBtn) confirmBtn.click();
                    });
                }
            },
            onConfirm: async () => {
                const overlay = document.getElementById('modal-overlay');
                const input = overlay?.querySelector('#input-budget-limit');
                const rawVal = input?.value?.trim();
                const num = parseFloat(rawVal);

                if (isNaN(num) || num <= 0) {
                    Toast.error('Please enter a valid positive budget amount.');
                    throw new Error('Invalid budget value');
                }

                GroupHeader.setBudget(token, num);
                Toast.success(`Budget target set to ${formatCurrency(num * 100, currency)}.`);
                if (typeof onSave === 'function') onSave();
            }
        });
    }

    /**
     * Share workspace invite via Web Share API or WhatsApp.
     * @param {Object} group
     * @param {string} [inviteUrl]
     */
    static async shareInvite(group, inviteUrl = (typeof window !== 'undefined' ? window.location.href : '')) {
        const groupName = group?.name || 'Smart Split Workspace';
        const title = `Join ${groupName} on Smart Split`;
        const text = `Join "${groupName}" on Smart Split to track shared expenses and settlements:`;
        const fullMessage = `${text}\n${inviteUrl}`;

        if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
            try {
                await navigator.share({
                    title,
                    text: fullMessage,
                    url: inviteUrl,
                });
                return;
            } catch (err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
            }
        }

        const whatsappUrl = `https://api.whatsapp.com/send?text=${encodeURIComponent(fullMessage)}`;
        if (typeof window !== 'undefined') {
            window.open(whatsappUrl, '_blank', 'noopener,noreferrer');
        }
    }

    /**
     * Open device pairing modal generating short-lived (10 min) QR & cryptographic pairing code.
     * @param {Object} options
     * @param {string} options.token
     * @param {string} [options.groupName]
     */
    static async openCreatorPairingModal({ token, groupName = '' }) {
        if (!token) return;

        try {
            const res = await api.createCreatorPairingCode(token);
            const pairingData = res?.data;
            const code = pairingData?.pairing_code || pairingData?.code;
            if (!pairingData || !code) {
                throw new Error('Failed to generate pairing code');
            }

            const expiresInSeconds = pairingData.expires_in_seconds || 600;
            const baseUrl = typeof window !== 'undefined' ? window.location.origin + window.location.pathname : '';
            const pairingLink = `${baseUrl}#/g/${token}?pair=${code}`;
            const qrSvg = QRCode.generateSvg(pairingLink, { size: 160, margin: 2 });

            let remainingSeconds = expiresInSeconds;
            let timerInterval = null;

            Modal.open({
                title: 'Pair Another Device (Creator Authority)',
                size: 'md',
                content: `
                    <div style="display: flex; flex-direction: column; gap: var(--space-3); text-align: center;">
                        <p style="font-size: var(--font-size-sm); color: var(--text-secondary); margin: 0; text-align: left;">
                            Scan this QR code or enter the single-use pairing code on your second device to securely link creator authority:
                        </p>

                        <!-- QR Code Container -->
                        <div style="display: flex; justify-content: center; margin: var(--space-2) 0;">
                            <div class="qr-code-svg-box" style="padding: 12px; background: #ffffff; border-radius: var(--radius-sm); border: 1px solid var(--border-color); display: inline-block;">
                                ${qrSvg}
                            </div>
                        </div>

                        <!-- Pairing Code Display -->
                        <div>
                            <div style="font-size: var(--font-size-2xs); text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.05em; margin-bottom: 4px;">
                                One-Time Pairing Code
                            </div>
                            <div class="pairing-code-display" id="pairing-code-val">
                                ${escapeHtml(code)}
                            </div>
                        </div>

                        <!-- Timer & Expiry Alert -->
                        <div style="display: flex; align-items: center; justify-content: center; gap: 6px; font-size: var(--font-size-xs); font-family: var(--font-mono); color: var(--text-muted);">
                            <span style="display: inline-flex; color: var(--brand-primary);">${renderIcon('clock', { size: 13 })}</span>
                            <span>Expires in: <strong id="pairing-timer-countdown" style="color: var(--financial-debt);">10:00</strong></span>
                        </div>

                        <!-- Action Buttons -->
                        <div style="display: flex; gap: var(--space-2); justify-content: center; margin-top: var(--space-2); flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-copy-pairing-code" style="display: inline-flex; align-items: center; gap: 4px;">
                                ${renderIcon('copy', { size: 13 })}
                                <span>Copy Code</span>
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-copy-pairing-link" style="display: inline-flex; align-items: center; gap: 4px;">
                                ${renderIcon('link', { size: 13 })}
                                <span>Copy Pairing Link</span>
                            </button>
                        </div>
                    </div>
                `,
                confirmText: 'Done',
                confirmClass: 'btn-primary',
                onMount: (overlay) => {
                    const countdownEl = overlay.querySelector('#pairing-timer-countdown');
                    const copyCodeBtn = overlay.querySelector('#btn-copy-pairing-code');
                    const copyLinkBtn = overlay.querySelector('#btn-copy-pairing-link');

                    const updateTimer = () => {
                        if (remainingSeconds <= 0) {
                            if (countdownEl) {
                                countdownEl.textContent = 'Expired';
                                countdownEl.style.color = 'var(--financial-debt)';
                            }
                            if (timerInterval) clearInterval(timerInterval);
                            return;
                        }
                        const mins = Math.floor(remainingSeconds / 60);
                        const secs = remainingSeconds % 60;
                        if (countdownEl) {
                            countdownEl.textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
                        }
                        remainingSeconds--;
                    };

                    updateTimer();
                    timerInterval = setInterval(updateTimer, 1000);

                    if (copyCodeBtn) {
                        copyCodeBtn.addEventListener('click', async () => {
                            if (navigator.clipboard) await navigator.clipboard.writeText(code);
                            Toast.success('Pairing code copied to clipboard.');
                        });
                    }

                    if (copyLinkBtn) {
                        copyLinkBtn.addEventListener('click', async () => {
                            if (navigator.clipboard) await navigator.clipboard.writeText(pairingLink);
                            Toast.success('Direct pairing link copied to clipboard.');
                        });
                    }
                }
            });
        } catch (err) {
            Toast.error(err.message || 'Failed to generate pairing code. Only workspace creators can pair devices.');
        }
    }

    /**
     * Open modal to enter and claim a device pairing code.
     * @param {Object} options
     * @param {string} [options.token]
     * @param {string} [options.initialCode]
     * @param {Function} [options.onClaimed]
     */
    static openClaimPairingModal({ token = '', initialCode = '', onClaimed = null }) {
        Modal.open({
            title: 'Enter Pairing Code',
            size: 'sm',
            content: `
                <form id="form-claim-pairing" style="display: flex; flex-direction: column; gap: var(--space-3);" onsubmit="return false;">
                    <p style="font-size: var(--font-size-sm); color: var(--text-secondary); margin: 0;">
                        Enter the 8-character pairing code from the workspace creator device:
                    </p>

                    ${!token ? `
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 4px;">
                            <label for="input-claim-token" class="form-label" style="margin-bottom: 0;">Workspace Token / URL</label>
                            <input type="text" id="input-claim-token" class="form-input" placeholder="e.g. 16-character token" required />
                        </div>
                    ` : ''}

                    <div class="form-group" style="display: flex; flex-direction: column; gap: 4px;">
                        <label for="input-claim-code" class="form-label" style="margin-bottom: 0;">Pairing Code</label>
                        <input
                            type="text"
                            id="input-claim-code"
                            class="form-input"
                            placeholder="e.g. PAIR-ABCD-1234"
                            value="${escapeHtml(initialCode)}"
                            style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; text-transform: uppercase; text-align: center; letter-spacing: 0.1em;"
                            required
                            autofocus
                        />
                    </div>
                </form>
            `,
            confirmText: 'Claim Creator Access',
            confirmClass: 'btn-primary',
            onConfirm: async () => {
                const overlay = Modal.getMountPoint();
                const codeInput = overlay.querySelector('#input-claim-code');
                const tokenInput = overlay.querySelector('#input-claim-token');

                const rawCode = (codeInput?.value || '').trim().toUpperCase();
                let targetToken = token || (tokenInput?.value || '').trim();

                if (targetToken.includes('/g/')) {
                    targetToken = targetToken.split('/g/')[1].split('?')[0].split('#')[0].trim();
                } else if (targetToken.includes('/')) {
                    const parts = targetToken.split('/');
                    targetToken = parts[parts.length - 1].split('?')[0].split('#')[0].trim();
                }

                if (!rawCode) {
                    Toast.error('Please enter a valid pairing code.');
                    throw new Error('Missing pairing code');
                }
                if (!targetToken) {
                    Toast.error('Please provide the workspace token.');
                    throw new Error('Missing workspace token');
                }

                try {
                    const res = await api.claimCreatorPairingCode(targetToken, rawCode);
                    const data = res?.data;
                    if (data?.creator_token) {
                        localStorage.setItem(`smartsplit_creator_${targetToken}`, data.creator_token);
                        if (data.creator_member_id) {
                            localStorage.setItem(`smartsplit_creator_id_${targetToken}`, String(data.creator_member_id));
                        }
                    }

                    Toast.success('Device paired successfully! Creator rights active on this device.');
                    if (typeof onClaimed === 'function') {
                        await onClaimed(targetToken);
                    } else if (typeof window !== 'undefined' && window.SmartSplit?.refreshGroupData) {
                        await window.SmartSplit.refreshGroupData(targetToken);
                    }
                } catch (err) {
                    Toast.error(err.message || 'Invalid or expired pairing code.');
                    throw err;
                }
            }
        });
    }


    /**
     * Render the executive group overview bar with key metrics, claiming banner, and share actions.
     * @param {HTMLElement} container
     * @param {Object} group
     * @param {Object} [stats]
     * @param {number} [stats.totalSpendCents=0]
     * @param {number} [stats.memberCount=0]
     * @param {number} [stats.pendingTransfers=0]
     * @param {Function} [onAddExpense]
     * @param {Function} [onUpdate] Callback after claiming/unlinking/budget updates
     */
    static render(container, group, stats = {}, onAddExpense = null, onUpdate = null) {
        if (!group) return;

        const token = group.invite_token || group.token || '';
        const totalSpendCents = stats.totalSpendCents || 0;
        const memberCount = stats.memberCount || 0;
        const pendingTransfers = stats.pendingTransfers ?? 0;
        const currency = group.currency_code || group.currency || 'INR';

        const currentBudget = GroupHeader.getBudget(token);
        const budgetProgress = currentBudget ? GroupHeader.calculateBudgetProgress(totalSpendCents, currentBudget) : null;

        // Progressive Identity Context
        const state = (typeof store !== 'undefined' && store.getState) ? store.getState() : {};
        const currentUser = state.currentUser;
        const isAuthenticated = Boolean(state.isAuthenticated);
        const members = state.members || [];
        const myClaimedMember = (isAuthenticated && currentUser)
            ? members.find(m => m.user_id !== null && m.user_id !== undefined && Number(m.user_id) === Number(currentUser.id))
            : null;
        const unclaimedMembers = members.filter(m => m.user_id === null || m.user_id === undefined);

        // Check if user dismissed claiming banner this session
        const isDismissed = typeof sessionStorage !== 'undefined' && sessionStorage.getItem(`smartsplit_dismiss_claim_${token}`) === 'true';
        const showClaimingBanner = isAuthenticated && !myClaimedMember && !isDismissed && members.length > 0;

        // Check for permanently failed offline actions requiring review
        const failedMutations = offlineManager.getFailedMutations(token);

        container.innerHTML = `
            ${failedMutations.length > 0 ? `
                <!-- Unsynced Failed Offline Actions Warning Banner -->
                <div class="failed-mutations-banner" id="workspace-failed-mutations-banner" style="background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid var(--financial-debt, #dc2626); border-radius: var(--radius-sm, 6px); padding: var(--space-3, 12px) var(--space-4, 16px); margin-bottom: var(--space-4, 16px); display: flex; justify-content: space-between; align-items: center; gap: var(--space-3, 12px); flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: var(--financial-debt, #dc2626); display: inline-flex;">${renderIcon('alertTriangle', { size: 16 })}</span>
                        <span style="font-size: var(--font-size-sm); color: #991b1b;">
                            <strong>${failedMutations.length} offline ${failedMutations.length === 1 ? 'action' : 'actions'}</strong> failed server sync and requires your review.
                        </span>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-review-failed-mutations" style="font-weight: 700; border-color: #fca5a5; color: #991b1b; display: inline-flex; align-items: center; gap: 4px; background: #ffffff;">
                        ${renderIcon('alertCircle', { size: 13 })}
                        <span>Review & Recover</span>
                    </button>
                </div>
            ` : ''}

            ${showClaimingBanner ? `
                <!-- Progressive Member Claiming Banner -->
                <div class="claiming-banner" id="workspace-claiming-banner" style="background: var(--brand-primary-soft, #E8F0EC); border: 1px solid var(--border-strong, #C2C9C3); border-radius: var(--radius-sm, 6px); padding: var(--space-3, 12px) var(--space-4, 16px); margin-bottom: var(--space-4, 16px); display: flex; justify-content: space-between; align-items: center; gap: var(--space-3, 12px); flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: var(--space-3, 12px); min-width: 260px; flex: 1;">
                        <div style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: var(--radius-xs, 4px); background: var(--surface-primary, #ffffff); border: 1px solid var(--border-color, #DDE2DE); color: var(--brand-primary, #18352B); flex-shrink: 0;">
                            ${renderIcon('userPlus', { size: 16 })}
                        </div>
                        <div>
                            <div style="font-weight: 700; font-size: var(--font-size-sm, 0.875rem); color: var(--text-primary); margin-bottom: 2px;">
                                Which member are you in this workspace?
                            </div>
                            <div style="font-size: var(--font-size-xs, 0.75rem); color: var(--text-muted); line-height: 1.3;">
                                Link your account to track your personal balance and access this group across devices.
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: var(--space-2, 8px); flex-wrap: wrap;">
                        ${unclaimedMembers.length > 0 ? `
                            <select id="select-claim-member" class="form-control form-control-sm" style="font-size: var(--font-size-xs, 0.75rem); padding: 5px 10px; border-radius: var(--radius-xs, 4px); font-weight: 600; min-width: 140px; height: 32px; background: var(--surface-primary, #ffffff); color: var(--text-primary); border: 1px solid var(--border-color, #cbd5e1);">
                                <option value="" disabled selected>Select your name...</option>
                                ${unclaimedMembers.map(m => `
                                    <option value="${m.id}">${escapeHtml(m.name)}</option>
                                `).join('')}
                            </select>
                            <button type="button" class="btn btn-primary btn-sm" id="btn-claim-member" style="font-size: var(--font-size-xs, 0.75rem); padding: 5px 12px; font-weight: 700; height: 32px; display: inline-flex; align-items: center; gap: 5px;">
                                ${renderIcon('sparkles', { size: 13 })} <span>Claim My Profile</span>
                            </button>
                        ` : `
                            <span style="font-size: var(--font-size-xs, 0.75rem); color: var(--text-muted); font-style: italic;">All member slots are claimed.</span>
                        `}
                        <button type="button" class="btn btn-ghost btn-sm" id="btn-dismiss-claim-banner" title="Dismiss banner" style="font-size: var(--font-size-xs, 0.75rem); color: var(--text-muted); height: 32px; padding: 4px 8px; display: inline-flex; align-items: center; gap: 4px;">
                            <span>I'm just browsing</span> ${renderIcon('x', { size: 11 })}
                        </button>
                    </div>
                </div>
            ` : ''}

            <div class="executive-summary-bar">
                <div class="exec-header-left">
                    <div class="exec-title-row" style="display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap;">
                        <h1 class="exec-title" style="margin-right: 4px;">${escapeHtml(group.name)}</h1>
                        <span class="badge badge-settled badge-mono">${escapeHtml(currency)}</span>
                        ${myClaimedMember ? `
                            <div id="claimed-identity-pill" style="display: inline-flex; align-items: center; gap: 6px; background: var(--brand-primary-soft, #E8F0EC); border: 1px solid var(--brand-accent-border, #C9D0CB); padding: 3px 10px; border-radius: var(--radius-xs, 4px); font-size: var(--font-size-xs, 0.75rem); font-weight: 600; color: var(--brand-primary, #18352B);">
                                <span style="display: inline-flex; align-items: center; color: var(--brand-primary);">${renderIcon('shieldCheck', { size: 13 })}</span>
                                <span>You are <strong>${escapeHtml(myClaimedMember.name)}</strong></span>
                                <button type="button" id="btn-unlink-my-profile" title="Unlink member slot from your account" style="background: none; border: none; padding: 0 0 0 4px; margin: 0; color: var(--text-muted); font-size: 0.7rem; cursor: pointer; text-decoration: underline;">
                                    Unlink
                                </button>
                            </div>
                        ` : ''}
                    </div>
                    <div class="exec-header-meta" style="display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap;">
                        <span>Created ${formatDate(group.created_at)}</span>
                        <span>•</span>
                        <span>${memberCount} ${memberCount === 1 ? 'Member' : 'Members'}</span>
                        ${!isAuthenticated ? `
                            <span>•</span>
                            <div id="guest-claim-hint" style="display: inline-flex; align-items: center; gap: 4px; font-size: var(--font-size-xs, 0.75rem); color: var(--text-muted);">
                                <span>Are you in this group?</span>
                                <button type="button" id="btn-header-signin-claim" style="background: none; border: none; padding: 0; color: var(--brand-primary, #18352B); font-weight: 600; cursor: pointer; text-decoration: underline; font-size: inherit;">
                                    Sign in to claim your profile
                                </button>
                            </div>
                        ` : ''}
                    </div>
                </div>

                <div class="exec-header-stats">
                    <div class="exec-stat-box">
                        <span class="exec-stat-label">Total Expenditure</span>
                        <span class="exec-stat-value">${formatCurrency(totalSpendCents, currency)}</span>
                    </div>

                    <div class="exec-stat-box exec-stat-budget">
                        <div style="display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 6px;">
                            <span class="exec-stat-label" style="margin-bottom: 0;">Budget Target</span>
                            ${currentBudget !== null ? `
                                <button type="button" class="btn-link-subtle" id="btn-edit-budget" title="Edit spending budget" style="background: none; border: none; padding: 0; color: var(--text-muted); font-size: 0.7rem; font-weight: 600; cursor: pointer; text-decoration: underline;">
                                    Edit
                                </button>
                            ` : ''}
                        </div>
                        ${budgetProgress ? `
                            <div class="exec-stat-value" style="font-size: 0.8125rem; font-weight: 600; color: var(--text-secondary); margin-top: 3px; display: flex; align-items: center; gap: 4px; line-height: 1.2;">
                                <span>${formatCurrency(totalSpendCents, currency)} / ${formatCurrency(currentBudget * 100, currency)}</span>
                                <span style="color: ${budgetProgress.statusColor}; font-weight: 700;">• ${budgetProgress.percentageFormatted}</span>
                            </div>
                            <div class="budget-progress-track" style="width: 100%; height: 5px; background: var(--surface-tertiary, #e2e8f0); border-radius: 9999px; margin-top: 5px; overflow: hidden;" title="${budgetProgress.percentageFormatted} of budget used">
                                <div class="budget-progress-bar" style="width: ${budgetProgress.visualWidth}%; height: 100%; background: ${budgetProgress.statusColor}; border-radius: 9999px; transition: width 0.3s ease;"></div>
                            </div>
                        ` : `
                            <div style="height: 1.4rem; display: flex; align-items: center;">
                                <button type="button" class="btn-link-subtle" id="btn-set-budget" style="background: none; border: none; padding: 0; color: var(--brand-primary, #18352B); font-size: var(--font-size-xs); font-weight: 600; cursor: pointer; text-decoration: underline; display: inline-flex; align-items: center; gap: 3px;">
                                    <span>+ Set Budget</span>
                                </button>
                            </div>
                        `}
                    </div>

                    <div class="exec-stat-box">
                        <span class="exec-stat-label">Settlement Status</span>
                        <span class="exec-stat-value" style="font-size: var(--font-size-base); display: flex; align-items: center; gap: 4px; height: 1.4rem;">
                            ${pendingTransfers === 0 
                                ? `<span class="badge badge-credit">All Settled</span>` 
                                : `<span class="badge badge-debt">${pendingTransfers} ${pendingTransfers === 1 ? 'Transfer' : 'Transfers'} Due</span>`}
                        </span>
                    </div>
                </div>

                <div class="exec-header-actions">
                    <div class="dropdown-container" id="share-dropdown-wrapper">
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-share-menu-toggle" aria-haspopup="true" aria-expanded="false" title="Share workspace">
                            ${renderIcon('share2', { size: 13 })}
                            <span>Share</span>
                            ${renderIcon('chevronDown', { size: 10 })}
                        </button>
                        <div class="dropdown-menu" id="share-dropdown-menu">
                            <button type="button" class="dropdown-item" id="btn-copy-invite" title="Copy shareable invite link">
                                ${renderIcon('link', { size: 13 })}
                                <span>Copy Invite Link</span>
                            </button>
                            <button type="button" class="dropdown-item" id="btn-whatsapp-invite" title="Share invite via WhatsApp">
                                ${renderIcon('whatsapp', { size: 13 })}
                                <span>Share to WhatsApp</span>
                            </button>
                            <div class="dropdown-divider" style="height: 1px; background: var(--border-subtle, #e2e8f0); margin: 4px 0;"></div>
                            <button type="button" class="dropdown-item" id="btn-pair-device-creator" title="Pair another device with creator access">
                                ${renderIcon('qrCode', { size: 13 })}
                                <span>Pair Another Device</span>
                            </button>
                            <button type="button" class="dropdown-item" id="btn-claim-device-pairing" title="Enter pairing code from another device">
                                ${renderIcon('key', { size: 13 })}
                                <span>Enter Pairing Code</span>
                            </button>
                        </div>
                    </div>
                    ${onAddExpense ? `
                        <button type="button" class="btn btn-primary btn-sm" id="btn-header-add-expense" style="font-weight: 700;">
                            ${renderIcon('plus', { size: 13 })}
                            <span>Log Expense</span>
                        </button>
                    ` : ''}
                </div>
            </div>
        `;

        // Review Failed Mutations Action Handler
        const reviewFailedBtn = container.querySelector('#btn-review-failed-mutations');
        if (reviewFailedBtn) {
            reviewFailedBtn.addEventListener('click', () => {
                FailedMutationsModal.open({
                    token,
                    onUpdated: async () => {
                        if (typeof onUpdate === 'function') {
                            await onUpdate();
                        } else if (typeof window !== 'undefined' && window.SmartSplit?.refreshGroupData) {
                            await window.SmartSplit.refreshGroupData(token);
                        }
                    },
                });
            });
        }

        // Claim Member Action Handler
        const claimBtn = container.querySelector('#btn-claim-member');
        const selectEl = container.querySelector('#select-claim-member');
        if (claimBtn && selectEl) {
            claimBtn.addEventListener('click', async () => {
                const memberId = selectEl.value;
                if (!memberId) {
                    Toast.error('Please select a member name to claim.');
                    return;
                }
                const member = members.find(m => String(m.id) === String(memberId));
                const memberName = member?.name || 'profile';
                try {
                    claimBtn.disabled = true;
                    claimBtn.textContent = 'Claiming...';
                    await api.claimMember(token, Number(memberId));
                    Toast.success(`Linked to ${memberName} successfully!`);
                    if (typeof onUpdate === 'function') {
                        await onUpdate();
                    } else if (typeof window !== 'undefined' && window.SmartSplit?.refreshGroupData) {
                        await window.SmartSplit.refreshGroupData(token);
                    }
                } catch (err) {
                    claimBtn.disabled = false;
                    claimBtn.innerHTML = `${renderIcon('sparkles', { size: 13 })} <span>Claim My Profile</span>`;
                    Toast.error(err.message || 'Failed to claim member profile.');
                }
            });
        }

        // Dismiss Claim Banner Handler
        const dismissBannerBtn = container.querySelector('#btn-dismiss-claim-banner');
        if (dismissBannerBtn) {
            dismissBannerBtn.addEventListener('click', () => {
                if (typeof sessionStorage !== 'undefined') {
                    sessionStorage.setItem(`smartsplit_dismiss_claim_${token}`, 'true');
                }
                container.querySelector('#workspace-claiming-banner')?.remove();
            });
        }

        // Unlink Profile Action Handler (From Header Pill)
        const unlinkHeaderBtn = container.querySelector('#btn-unlink-my-profile');
        if (unlinkHeaderBtn && myClaimedMember) {
            unlinkHeaderBtn.addEventListener('click', () => {
                Modal.open({
                    title: `Unlink from ${escapeHtml(myClaimedMember.name)}?`,
                    size: 'sm',
                    content: `
                        <p style="font-size: var(--font-size-sm); color: var(--text-secondary); margin-bottom: var(--space-2);">
                            Are you sure you want to unlink your account from <strong>${escapeHtml(myClaimedMember.name)}</strong>?
                        </p>
                        <p style="font-size: var(--font-size-xs); color: var(--text-muted);">
                            Your account will no longer be attached to this member slot, but past expenses and balances remain untouched.
                        </p>
                    `,
                    confirmText: 'Unlink Profile',
                    confirmClass: 'btn-danger',
                    onConfirm: async () => {
                        try {
                            await api.unlinkMember(token, myClaimedMember.id);
                            Toast.info(`Unlinked from ${myClaimedMember.name}.`);
                            if (typeof onUpdate === 'function') {
                                await onUpdate();
                            } else if (typeof window !== 'undefined' && window.SmartSplit?.refreshGroupData) {
                                await window.SmartSplit.refreshGroupData(token);
                            }
                        } catch (err) {
                            Toast.error(err.message || 'Failed to unlink member.');
                        }
                    }
                });
            });
        }

        // Guest Claim Prompt Handler
        const signInClaimBtn = container.querySelector('#btn-header-signin-claim');
        if (signInClaimBtn) {
            signInClaimBtn.addEventListener('click', () => {
                AuthModal.open({ initialMode: 'login' });
            });
        }

        // Budget Handlers
        const setBudgetBtn = container.querySelector('#btn-set-budget');
        if (setBudgetBtn) {
            setBudgetBtn.addEventListener('click', () => {
                GroupHeader.openBudgetModal({
                    token,
                    currentBudget,
                    currency,
                    onSave: () => {
                        if (typeof onUpdate === 'function') {
                            onUpdate();
                        } else {
                            GroupHeader.render(container, group, stats, onAddExpense, onUpdate);
                        }
                    },
                });
            });
        }

        const editBudgetBtn = container.querySelector('#btn-edit-budget');
        if (editBudgetBtn) {
            editBudgetBtn.addEventListener('click', () => {
                GroupHeader.openBudgetModal({
                    token,
                    currentBudget,
                    currency,
                    onSave: () => {
                        if (typeof onUpdate === 'function') {
                            onUpdate();
                        } else {
                            GroupHeader.render(container, group, stats, onAddExpense, onUpdate);
                        }
                    },
                });
            });
        }

        // Share Menu Dropdown Toggle Handler
        const shareToggleBtn = container.querySelector('#btn-share-menu-toggle');
        const shareMenu = container.querySelector('#share-dropdown-menu');
        if (shareToggleBtn && shareMenu) {
            shareToggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = shareMenu.classList.toggle('is-active');
                shareToggleBtn.setAttribute('aria-expanded', String(isOpen));
            });
            document.addEventListener('click', () => {
                shareMenu.classList.remove('is-active');
                shareToggleBtn.setAttribute('aria-expanded', 'false');
            });
        }

        // Invite Handlers
        const copyBtn = container.querySelector('#btn-copy-invite');
        if (copyBtn) {
            copyBtn.addEventListener('click', async () => {
                if (shareMenu) shareMenu.classList.remove('is-active');
                const inviteUrl = window.location.href;
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(inviteUrl);
                    } else {
                        const textArea = document.createElement('textarea');
                        textArea.value = inviteUrl;
                        textArea.style.position = 'fixed';
                        textArea.style.left = '-999999px';
                        document.body.appendChild(textArea);
                        textArea.focus();
                        textArea.select();
                        document.execCommand('copy');
                        textArea.remove();
                    }
                    Toast.success('Workspace invite link copied to clipboard.');
                } catch (err) {
                    Toast.info(`Share URL: ${inviteUrl}`, 6000);
                }
            });
        }

        const whatsappInviteBtn = container.querySelector('#btn-whatsapp-invite');
        if (whatsappInviteBtn) {
            whatsappInviteBtn.addEventListener('click', async () => {
                if (shareMenu) shareMenu.classList.remove('is-active');
                await GroupHeader.shareInvite(group, window.location.href);
            });
        }

        // Pairing Handlers
        const pairDeviceBtn = container.querySelector('#btn-pair-device-creator');
        if (pairDeviceBtn) {
            pairDeviceBtn.addEventListener('click', () => {
                if (shareMenu) shareMenu.classList.remove('is-active');
                GroupHeader.openCreatorPairingModal({ token, groupName: group.name });
            });
        }

        const claimPairingBtn = container.querySelector('#btn-claim-device-pairing');
        if (claimPairingBtn) {
            claimPairingBtn.addEventListener('click', () => {
                if (shareMenu) shareMenu.classList.remove('is-active');
                GroupHeader.openClaimPairingModal({ token, onClaimed: onUpdate });
            });
        }

        const addExpenseBtn = container.querySelector('#btn-header-add-expense');
        if (addExpenseBtn && typeof onAddExpense === 'function') {
            addExpenseBtn.addEventListener('click', onAddExpense);
        }
    }
}
