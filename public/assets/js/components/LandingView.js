/**
 * Smart Split V2 – Landing Page & Workspaces Hub Component
 * Principles: Fast Onboarding, Clean Typography, Zero Decorative Clutter
 */

import { api } from '../api.js';
import { store } from '../state.js';
import { router } from '../router.js';
import { Toast } from './Toast.js';
import { Modal } from './Modal.js';
import { AuthModal } from './AuthModal.js';
import { escapeHtml, formatCurrency } from '../utils/formatters.js';
import { SUPPORTED_CURRENCIES } from '../utils/currency.js';
import { PreferencesManager } from '../utils/preferences.js';
import { renderIcon } from '../utils/icons.js';
import { Footer } from './Footer.js';
import { GroupHeader } from './GroupHeader.js';

export class LandingView {
    /**
     * Get saved workspaces from localStorage.
     * @returns {Array<{token: string, name: string, currency: string, lastAccessed: number}>}
     */
    static getRecentWorkspaces() {
        try {
            const raw = localStorage.getItem('smartsplit_workspaces');
            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    }

    /**
     * Save/update workspace in localStorage.
     * @param {Object} group
     */
    static saveWorkspace(group) {
        if (!group) return;
        const token = group.invite_token || group.token;
        if (!token) return;
        try {
            const list = LandingView.getRecentWorkspaces().filter(w => w.token !== token);
            const isOwner = Boolean(group.is_owner || group.isOwner);
            const memberName = group.member_name || group.memberName || null;
            list.unshift({
                token: token,
                name: group.name,
                currency: group.currency_code || group.currency || 'INR',
                lastAccessed: group.lastAccessed || (group.created_at ? new Date(group.created_at).getTime() : Date.now()),
                ...(isOwner ? { isOwner: true } : {}),
                ...(memberName ? { memberName } : {}),
            });
            localStorage.setItem('smartsplit_workspaces', JSON.stringify(list.slice(0, 20)));
        } catch {
            // Ignore storage errors
        }
    }

    /**
     * Remove a workspace from localStorage.
     * @param {string} token
     */
    static removeRecentWorkspace(token) {
        if (!token) return;
        try {
            const list = LandingView.getRecentWorkspaces().filter(w => w.token !== token);
            localStorage.setItem('smartsplit_workspaces', JSON.stringify(list));
            localStorage.removeItem(`smartsplit_budget_${token}`);
            localStorage.removeItem(`smartsplit_avatars_${token}`);
            localStorage.removeItem(`smartsplit_creator_${token}`);
            localStorage.removeItem(`smartsplit_creator_id_${token}`);
        } catch {
            // Ignore storage errors
        }
    }

    /**
     * Calculate client-side multi-workspace consolidated net financial metrics.
     * @param {Array<{workspace: Object, status: string, data?: Object}>} results
     * @returns {Object} { totalNetCents, activeCreditCount, activeDebtCount, settledCount, successfulCount, workspaceSummaries }
     */
    static calculateConsolidatedSummary(results = []) {
        let totalNetCents = 0;
        let activeCreditCount = 0;
        let activeDebtCount = 0;
        let settledCount = 0;
        let successfulCount = 0;

        const workspaceSummaries = results.map(({ workspace = {}, status, data }) => {
            const token = workspace.token;
            const currency = workspace.currency || data?.group?.currency_code || 'INR';

            if (status === 'fulfilled' && data) {
                successfulCount++;
                const members = data.members || [];
                let userMember = null;
                if (workspace.user_member_id) {
                    userMember = members.find(m => Number(m.member_id || m.id) === Number(workspace.user_member_id));
                }
                if (!userMember && workspace.userName) {
                    userMember = members.find(m => m.name === workspace.userName);
                }
                if (!userMember && members.length > 0) {
                    userMember = members[0];
                }

                const netCents = userMember ? (userMember.net_balance_cents || 0) : 0;
                totalNetCents += netCents;

                let badgeText = 'Settled';
                let badgeType = 'settled';

                if (netCents > 0) {
                    activeCreditCount++;
                    badgeType = 'credit';
                    badgeText = `+${formatCurrency(netCents, currency)}`;
                } else if (netCents < 0) {
                    activeDebtCount++;
                    badgeType = 'debt';
                    badgeText = `-${formatCurrency(Math.abs(netCents), currency)}`;
                } else {
                    settledCount++;
                    badgeType = 'settled';
                    badgeText = 'Settled';
                }

                return {
                    token,
                    workspace,
                    isAvailable: true,
                    netCents,
                    currency,
                    badgeType,
                    badgeText,
                };
            } else {
                return {
                    token,
                    workspace,
                    isAvailable: false,
                    netCents: null,
                    currency,
                    badgeType: 'unavailable',
                    badgeText: 'Unavailable',
                };
            }
        });

        return {
            totalNetCents,
            activeCreditCount,
            activeDebtCount,
            settledCount,
            successfulCount,
            workspaceSummaries,
        };
    }

    /**
     * Fetch cloud workspaces (if authenticated) and merge with local workspaces.
     * @returns {Promise<Array<{token: string, name: string, currency: string, lastAccessed: number, isCloud: boolean, isOwner: boolean, memberName: string|null, netBalanceCents: number|null}>>}
     */
    static async fetchAndMergeWorkspaces() {
        const isAuthenticated = Boolean(store.getState()?.isAuthenticated);
        const currentUser = store.getState()?.currentUser;
        const localWorkspaces = LandingView.getRecentWorkspaces();
        let cloudWorkspaces = [];

        if (isAuthenticated) {
            try {
                const cloudRes = await api.getUserWorkspaces();
                cloudWorkspaces = cloudRes?.data?.workspaces || [];
            } catch {
                cloudWorkspaces = [];
            }
        }

        // Merge and deduplicate by token / invite_token
        const mergedMap = new Map();

        cloudWorkspaces.forEach((cw) => {
            const token = cw.invite_token || cw.token;
            if (token) {
                LandingView.saveWorkspace(cw);
                mergedMap.set(token, {
                    token,
                    name: cw.name,
                    currency: cw.currency_code || cw.currency || 'INR',
                    lastAccessed: cw.lastAccessed || (cw.created_at ? new Date(cw.created_at).getTime() : Date.now()),
                    isCloud: true,
                    isOwner: Boolean(cw.is_owner || (currentUser && cw.owner_user_id === currentUser.id)),
                    memberName: cw.member_name || null,
                    netBalanceCents: cw.net_balance_cents !== undefined ? cw.net_balance_cents : null,
                    status: cw.status,
                });
            }
        });

        localWorkspaces.forEach((lw) => {
            if (!mergedMap.has(lw.token)) {
                const creatorToken = typeof localStorage !== 'undefined' ? localStorage.getItem(`smartsplit_creator_${lw.token}`) : null;
                const isCreator = Boolean(creatorToken || lw.isOwner || lw.isCreator);
                mergedMap.set(lw.token, {
                    token: lw.token,
                    name: lw.name,
                    currency: lw.currency || 'INR',
                    lastAccessed: lw.lastAccessed || Date.now(),
                    isCloud: false,
                    isOwner: isCreator,
                    isCreator: isCreator,
                    memberName: lw.memberName || null,
                    netBalanceCents: null,
                    status: null,
                });
            }
        });

        return Array.from(mergedMap.values());
    }

    /**
     * Open the Workspaces Hub modal showing recently visited & cloud groups with quick switcher and consolidated net financial summary.
     */
    static async openWorkspacesModal() {
        const isAuthenticated = Boolean(store.getState()?.isAuthenticated);
        const recentWorkspaces = await LandingView.fetchAndMergeWorkspaces();
        const baseCurrency = recentWorkspaces[0]?.currency || 'INR';

        const content = `
            <div style="margin-bottom: var(--space-3);">
                ${!isAuthenticated ? `
                    <div id="hub-guest-banner" style="background: var(--brand-primary-soft, #E8F0EC); border: 1px solid var(--border-strong, #C2C9C3); border-radius: var(--radius-sm, 6px); padding: var(--space-3, 12px); margin-bottom: var(--space-3, 12px); display: flex; justify-content: space-between; align-items: center; gap: var(--space-2, 8px); flex-wrap: wrap;">
                        <div style="font-size: var(--font-size-xs, 0.78rem); color: var(--text-secondary, #4b5563); line-height: 1.4; display: flex; align-items: center; gap: 6px;">
                            <span style="color: var(--brand-primary);">${renderIcon('sparkles', { size: 14 })}</span>
                            <span><strong>Tip:</strong> Sign in to sync your workspaces securely across all your devices and browsers.</span>
                        </div>
                        <button type="button" id="btn-hub-signin" class="btn btn-primary btn-sm" style="font-size: var(--font-size-xs, 0.75rem); padding: 4px 10px; font-weight: 700;">
                            Sign In / Sign Up
                        </button>
                    </div>
                ` : ''}

                <p style="font-size: var(--font-size-sm); color: var(--text-muted); margin-bottom: var(--space-3);">
                    Switch between active expense workspaces or initialize a new group ledger.
                </p>

                ${recentWorkspaces.length === 0 ? `
                    <div class="empty-state" style="padding: var(--space-6) var(--space-4); text-align: center;">
                        <div class="empty-state-title">No recent workspaces</div>
                        <div class="empty-state-text">Workspaces you create or visit on this browser will appear here.</div>
                    </div>
                ` : `
                    <!-- Consolidated Multi-Workspace KPI Summary Bar -->
                    <div id="workspaces-summary-kpi" style="background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: var(--space-3); margin-bottom: var(--space-3);">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: var(--space-2); flex-wrap: wrap;">
                            <div>
                                <div style="font-size: var(--font-size-2xs); text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.05em; margin-bottom: 2px;">
                                    Total Net Position Across All Workspaces
                                </div>
                                <div id="kpi-total-net" class="tnum" style="font-size: var(--font-size-lg); font-weight: 700; font-family: var(--font-mono); color: var(--text-primary);">
                                    Loading…
                                </div>
                            </div>
                            <div id="kpi-status-counts" style="display: flex; gap: var(--space-2); align-items: center; font-size: var(--font-size-xs); flex-wrap: wrap;">
                                <span class="badge badge-settled badge-mono" id="kpi-credit-count">Active Credit: …</span>
                                <span class="badge badge-settled badge-mono" id="kpi-debt-count">Active Debt: …</span>
                            </div>
                        </div>
                    </div>

                    <!-- Individual Workspaces List -->
                    <div style="display: flex; flex-direction: column; gap: var(--space-2); max-height: 280px; overflow-y: auto;">
                        ${recentWorkspaces.map(w => `
                            <div class="workspace-item" style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-2) var(--space-3); background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-subtle); gap: var(--space-2);">
                                <div style="display: flex; flex-direction: column; gap: 2px; min-width: 0;">
                                    <div style="display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap;">
                                        <span style="font-weight: 600; font-size: var(--font-size-sm); color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(w.name)}</span>
                                        <span class="badge badge-settled badge-mono" style="font-size: var(--font-size-2xs);">${escapeHtml(w.currency || 'INR')}</span>
                                        ${w.isOwner ? `<span class="badge badge-mono badge-owner" style="font-size: var(--font-size-2xs); background: rgba(234, 179, 8, 0.12); color: #92400e; border: 1px solid rgba(234, 179, 8, 0.28); display: inline-flex; align-items: center; gap: 3px;">${renderIcon('crown', { size: 10 })} ${w.isCloud ? 'Owner' : 'Organizer'}<!-- 👑 Owner --></span>` : ''}
                                        ${w.memberName ? `<span class="badge badge-mono badge-member" style="font-size: var(--font-size-2xs); background: var(--brand-primary-soft, #E8F0EC); color: var(--brand-primary, #18352B); border: 1px solid var(--brand-accent-border, #C9D0CB); display: inline-flex; align-items: center; gap: 3px;">${renderIcon('user', { size: 10 })} ${escapeHtml(w.memberName)}</span>` : ''}
                                        ${w.isCloud ? `<span class="badge badge-mono badge-cloud" style="font-size: var(--font-size-2xs); background: var(--financial-credit-bg, #E8F5F1); color: var(--financial-credit-text, #065A43); border: 1px solid var(--financial-credit-border, #B6E2D5); display: inline-flex; align-items: center; gap: 3px;">${renderIcon('cloud', { size: 10 })} Cloud Synced<!-- ☁️ Cloud Synced --></span>` : ''}
                                    </div>
                                    <span style="font-size: var(--font-size-2xs); color: var(--text-muted); font-family: var(--font-mono);">
                                        Last accessed: ${new Date(w.lastAccessed || Date.now()).toLocaleDateString()}
                                    </span>
                                </div>
                                <div style="display: flex; align-items: center; gap: var(--space-2); flex-shrink: 0;">
                                    <span class="workspace-balance-badge badge badge-settled badge-mono" data-token="${escapeHtml(w.token)}" style="font-size: var(--font-size-xs); font-family: var(--font-mono);">
                                        Loading…
                                    </span>
                                    <button type="button" class="btn btn-secondary btn-sm btn-open-workspace" data-token="${escapeHtml(w.token)}">
                                        Open &rarr;
                                    </button>
                                    <button type="button" class="btn btn-ghost btn-sm btn-delete-workspace" data-token="${escapeHtml(w.token)}" data-name="${escapeHtml(w.name)}" data-is-owner="${w.isOwner ? 'true' : 'false'}" title="${w.isOwner || !w.isCloud ? 'Manage / Delete Workspace' : 'Remove from Workspaces'}" style="color: var(--text-muted); padding: 4px 6px;">
                                        ${renderIcon('trash2', { size: 13 })}
                                    </button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `}

                <div style="margin-top: var(--space-4); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-2);">
                    <div style="display: flex; gap: var(--space-2); align-items: center;">
                        <button type="button" class="btn btn-primary btn-sm" id="btn-modal-create-workspace" style="display: inline-flex; align-items: center; gap: 5px;">
                            ${renderIcon('plus', { size: 13 })}
                            <span>New Workspace</span>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-modal-pair-device" style="display: inline-flex; align-items: center; gap: 5px;">
                            ${renderIcon('key', { size: 13 })}
                            <span>Pair Device with Code</span>
                        </button>
                    </div>
                    ${recentWorkspaces.length > 0 ? `
                        <button type="button" class="btn btn-ghost btn-sm" id="btn-clear-workspaces-history" style="color: var(--text-muted);">
                            Clear History
                        </button>
                    ` : ''}
                </div>
            </div>
        `;

        Modal.open({
            title: 'Workspaces Hub',
            content,
            showFooter: false,
            onMount: (modalEl) => {
                // Navigation button listeners
                modalEl.querySelectorAll('.btn-open-workspace').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const token = btn.dataset.token;
                        Modal.close();
                        router.navigate(`/g/${token}`);
                    });
                });

                // Delete workspace button listeners
                modalEl.querySelectorAll('.btn-delete-workspace').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const token = btn.dataset.token;
                        const name = btn.dataset.name || 'Workspace';

                        Modal.open({
                            title: `Workspace Options — ${escapeHtml(name)}`,
                            size: 'sm',
                            content: `
                                <div style="font-size: var(--font-size-sm); color: var(--text-secondary); line-height: 1.5; margin-bottom: var(--space-3);">
                                    Choose how you would like to manage <strong>${escapeHtml(name)}</strong>:
                                </div>
                                <div style="display: flex; flex-direction: column; gap: var(--space-3);">
                                    <!-- Option 1: Local Unlink / Safe Remove -->
                                    <div style="border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: var(--space-3); background: var(--surface-secondary);">
                                        <div style="font-weight: 600; font-size: var(--font-size-sm); color: var(--text-primary); margin-bottom: 2px;">
                                            Remove from My Workspaces
                                        </div>
                                        <div style="font-size: var(--font-size-xs); color: var(--text-muted); margin-bottom: var(--space-2); line-height: 1.4;">
                                            Unlinks this workspace from your local list on this browser. All shared transactions remain live for other members.
                                        </div>
                                        <button type="button" class="btn btn-secondary btn-sm btn-block" id="btn-action-unlink-workspace">
                                            Remove from My List
                                        </button>
                                    </div>

                                    <!-- Option 2: Global Database Wipe -->
                                    <div style="border: 1px solid rgba(239, 68, 68, 0.25); border-radius: var(--radius-sm); padding: var(--space-3); background: rgba(239, 68, 68, 0.04);">
                                        <div style="font-weight: 600; font-size: var(--font-size-sm); color: var(--financial-debt); margin-bottom: 2px; display: flex; align-items: center; gap: 4px;">
                                            ${renderIcon('trash2', { size: 12 })}
                                            <span>Delete Workspace for Everyone</span>
                                        </div>
                                        <div style="font-size: var(--font-size-xs); color: var(--text-muted); margin-bottom: var(--space-2); line-height: 1.4;">
                                            Permanently deletes this workspace, allocations, receipts, and settlement history for all participants. <strong>Cannot be undone.</strong>
                                        </div>
                                        <button type="button" class="btn btn-danger btn-sm btn-block" id="btn-action-delete-workspace">
                                            Delete for Everyone
                                        </button>
                                    </div>
                                </div>
                            `,
                            showFooter: false,
                            onMount: (subModalEl) => {
                                const unlinkBtn = subModalEl.querySelector('#btn-action-unlink-workspace');
                                if (unlinkBtn) {
                                    unlinkBtn.addEventListener('click', async () => {
                                        LandingView.removeRecentWorkspace(token);
                                        Toast.success(`Workspace "${name}" removed from your list.`);
                                        Modal.close();
                                        if (typeof window !== 'undefined' && window.location.hash.includes(token)) {
                                            router.navigate('/');
                                        } else {
                                            await LandingView.openWorkspacesModal();
                                        }
                                    });
                                }

                                const deleteBtn = subModalEl.querySelector('#btn-action-delete-workspace');
                                if (deleteBtn) {
                                    deleteBtn.addEventListener('click', async () => {
                                        deleteBtn.disabled = true;
                                        deleteBtn.textContent = 'Deleting...';
                                        try {
                                            await api.deleteGroup(token);
                                            LandingView.removeRecentWorkspace(token);
                                            Toast.success(`Workspace "${name}" was deleted permanently.`);
                                            Modal.close();
                                            if (typeof window !== 'undefined' && window.location.hash.includes(token)) {
                                                router.navigate('/');
                                            } else {
                                                await LandingView.openWorkspacesModal();
                                            }
                                        } catch (err) {
                                            const isNotFound = err.status === 404 || (err.message && (err.message.includes('not found') || err.message.includes('NOT_FOUND')));
                                            if (isNotFound) {
                                                LandingView.removeRecentWorkspace(token);
                                                Toast.success(`Workspace "${name}" was removed from your list.`);
                                                Modal.close();
                                                if (typeof window !== 'undefined' && window.location.hash.includes(token)) {
                                                    router.navigate('/');
                                                } else {
                                                    await LandingView.openWorkspacesModal();
                                                }
                                            } else {
                                                deleteBtn.disabled = false;
                                                deleteBtn.textContent = 'Delete for Everyone';
                                                Toast.error(err.message || 'Failed to delete workspace.');
                                            }
                                        }
                                    });
                                }
                            }
                        });
                    });
                });

                const createBtn = modalEl.querySelector('#btn-modal-create-workspace');
                if (createBtn) {
                    createBtn.addEventListener('click', () => {
                        Modal.close();
                        router.navigate('/');
                    });
                }

                const pairBtn = modalEl.querySelector('#btn-modal-pair-device');
                if (pairBtn) {
                    pairBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        GroupHeader.openClaimPairingModal({
                            onClaimed: (claimedToken) => {
                                router.navigate(`/g/${claimedToken}`);
                            }
                        });
                    });
                }

                const clearBtn = modalEl.querySelector('#btn-clear-workspaces-history');
                if (clearBtn) {
                    clearBtn.addEventListener('click', () => {
                        localStorage.removeItem('smartsplit_workspaces');
                        Toast.success('Workspace history cleared.');
                        Modal.close();
                    });
                }

                const hubSignInBtn = modalEl.querySelector('#btn-hub-signin');
                if (hubSignInBtn) {
                    hubSignInBtn.addEventListener('click', () => {
                        Modal.close();
                        AuthModal.open({ initialMode: 'login' });
                    });
                }

                // Asynchronous parallel fetch of balances across all workspace tokens
                if (recentWorkspaces.length > 0) {
                    const fetchPromises = recentWorkspaces.map(w =>
                        api.getBalances(w.token)
                            .then(res => ({ workspace: w, status: 'fulfilled', data: res?.data || res }))
                            .catch(err => ({ workspace: w, status: 'rejected', error: err }))
                    );

                    Promise.allSettled(fetchPromises).then((settledResults) => {
                        const results = settledResults.map(r => r.value || { workspace: {}, status: 'rejected' });
                        const summary = LandingView.calculateConsolidatedSummary(results);

                        const kpiTotalEl = modalEl.querySelector('#kpi-total-net');
                        const kpiCreditEl = modalEl.querySelector('#kpi-credit-count');
                        const kpiDebtEl = modalEl.querySelector('#kpi-debt-count');

                        // Update individual workspace badges
                        summary.workspaceSummaries.forEach(ws => {
                            const badgeEl = modalEl.querySelector(`.workspace-balance-badge[data-token="${ws.token}"]`);
                            if (badgeEl) {
                                if (ws.badgeType === 'credit') {
                                    badgeEl.className = 'workspace-balance-badge badge badge-credit badge-mono';
                                    badgeEl.textContent = ws.badgeText;
                                    badgeEl.style.opacity = '1';
                                } else if (ws.badgeType === 'debt') {
                                    badgeEl.className = 'workspace-balance-badge badge badge-debt badge-mono';
                                    badgeEl.textContent = ws.badgeText;
                                    badgeEl.style.opacity = '1';
                                } else if (ws.badgeType === 'settled') {
                                    badgeEl.className = 'workspace-balance-badge badge badge-settled badge-mono';
                                    badgeEl.textContent = ws.badgeText;
                                    badgeEl.style.opacity = '1';
                                } else {
                                    badgeEl.className = 'workspace-balance-badge badge badge-settled badge-mono';
                                    badgeEl.textContent = 'Unavailable';
                                    badgeEl.style.opacity = '0.6';
                                }
                            }
                        });

                        // Update Consolidated KPI Bar
                        if (kpiTotalEl) {
                            if (summary.successfulCount > 0) {
                                if (summary.totalNetCents > 0) {
                                    kpiTotalEl.style.color = 'var(--financial-credit)';
                                    kpiTotalEl.textContent = `+${formatCurrency(summary.totalNetCents, baseCurrency)}`;
                                } else if (summary.totalNetCents < 0) {
                                    kpiTotalEl.style.color = 'var(--financial-debt)';
                                    kpiTotalEl.textContent = `-${formatCurrency(Math.abs(summary.totalNetCents), baseCurrency)}`;
                                } else {
                                    kpiTotalEl.style.color = 'var(--text-primary)';
                                    kpiTotalEl.textContent = formatCurrency(0, baseCurrency);
                                }
                            } else {
                                kpiTotalEl.style.color = 'var(--text-muted)';
                                kpiTotalEl.textContent = 'Unavailable';
                            }
                        }

                        if (kpiCreditEl) {
                            kpiCreditEl.className = summary.activeCreditCount > 0 ? 'badge badge-credit badge-mono' : 'badge badge-settled badge-mono';
                            kpiCreditEl.textContent = `Active Credit: ${summary.activeCreditCount} ${summary.activeCreditCount === 1 ? 'workspace' : 'workspaces'}`;
                        }

                        if (kpiDebtEl) {
                            kpiDebtEl.className = summary.activeDebtCount > 0 ? 'badge badge-debt badge-mono' : 'badge badge-settled badge-mono';
                            kpiDebtEl.textContent = `Active Debt: ${summary.activeDebtCount} ${summary.activeDebtCount === 1 ? 'workspace' : 'workspaces'}`;
                        }
                    });
                }
            }
        });
    }

    /**
     * Asynchronously hydrate and render the workspaces list on the landing page.
     * @param {HTMLElement} container
     */
    static async syncAndRenderWorkspaces(container) {
        const mount = container.querySelector('#landing-workspaces-mount');
        if (!mount) return;

        try {
            const isAuthenticated = Boolean(store.getState()?.isAuthenticated);
            const workspaces = await LandingView.fetchAndMergeWorkspaces();

            // Guard in case container was unmounted or replaced during async fetch
            const currentMount = container.querySelector('#landing-workspaces-mount');
            if (!currentMount) return;

            if (workspaces.length === 0) {
                currentMount.innerHTML = '';
                return;
            }

            currentMount.innerHTML = `
                <section class="landing-recents-directory">
                    <div class="landing-recents-header">
                        <div class="landing-recents-title">
                            ${renderIcon(isAuthenticated ? 'cloud' : 'clock', { size: 14 })}
                            <span>${isAuthenticated ? 'Your Workspaces (Cloud Synced)' : 'Recent Workspaces'}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: var(--space-2);">
                            <span class="badge badge-settled badge-mono">${workspaces.length} ${workspaces.length === 1 ? 'Workspace' : 'Workspaces'}</span>
                            <button type="button" class="btn btn-ghost btn-xs" id="btn-landing-open-hub" style="font-size: var(--font-size-2xs); padding: 2px 8px; font-weight: 600; color: var(--brand-primary); display: inline-flex; align-items: center; gap: 4px;">
                                ${renderIcon('externalLink', { size: 11 })}
                                <span>Hub Summary</span>
                            </button>
                        </div>
                    </div>
                    <div class="landing-recents-list">
                        ${workspaces.map(w => `
                            <a href="#/g/${escapeHtml(w.token)}" class="landing-recents-row">
                                <div class="landing-recents-info">
                                    <span class="landing-recents-icon">${renderIcon(w.isCloud ? 'cloud' : 'folder', { size: 14 })}</span>
                                    <span class="landing-recents-name">${escapeHtml(w.name)}</span>
                                    <span class="badge badge-settled badge-mono" style="font-size: var(--font-size-2xs); padding: 1px 6px;">${escapeHtml(w.currency || 'INR')}</span>
                                    ${w.isOwner ? `<span class="badge badge-mono badge-owner" style="font-size: var(--font-size-2xs); background: rgba(234, 179, 8, 0.12); color: #92400e; border: 1px solid rgba(234, 179, 8, 0.28); display: inline-flex; align-items: center; gap: 3px;">${renderIcon('crown', { size: 10 })} ${w.isCloud ? 'Owner' : 'Organizer'}</span>` : ''}
                                    ${w.memberName ? `<span class="badge badge-mono badge-member" style="font-size: var(--font-size-2xs); background: var(--brand-primary-soft, #E8F0EC); color: var(--brand-primary, #18352B); border: 1px solid var(--brand-accent-border, #C9D0CB); display: inline-flex; align-items: center; gap: 3px;">${renderIcon('user', { size: 10 })} ${escapeHtml(w.memberName)}</span>` : ''}
                                    ${w.isCloud ? `<span class="badge badge-mono badge-cloud" style="font-size: var(--font-size-2xs); background: var(--financial-credit-bg, #E8F5F1); color: var(--financial-credit-text, #065A43); border: 1px solid var(--financial-credit-border, #B6E2D5); display: inline-flex; align-items: center; gap: 3px;">${renderIcon('check', { size: 10 })} Synced</span>` : ''}
                                </div>
                                <div class="landing-recents-meta">
                                    <span class="landing-recents-date">
                                        ${new Date(w.lastAccessed || Date.now()).toLocaleDateString()}
                                    </span>
                                    <span class="landing-recents-open">
                                        <span>Open</span>
                                        <span class="landing-recents-arrow">${renderIcon('arrowRight', { size: 12 })}</span>
                                    </span>
                                </div>
                            </a>
                        `).join('')}
                    </div>
                </section>
            `;

            const hubBtn = currentMount.querySelector('#btn-landing-open-hub');
            if (hubBtn) {
                hubBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    LandingView.openWorkspacesModal();
                });
            }
        } catch {
            // Silently fall back to already rendered local storage items
        }
    }

    /**
     * Render the landing page with group creation form and recent workspaces hub.
     * @param {HTMLElement} container
     */
    static render(container) {
        const recentWorkspaces = LandingView.getRecentWorkspaces();
        const currentUser = store.getState()?.currentUser;
        const isAuthenticated = Boolean(store.getState()?.isAuthenticated);
        const defaultCreatorName = currentUser ? (currentUser.display_name || currentUser.name || '') : '';

        container.innerHTML = `
            <div class="landing-wrapper">
                <!-- Hero 2-Column Grid -->
                <section class="landing-hero-grid">
                    <div class="landing-hero-intro">
                        <div class="landing-eyebrow">Smart Split • Group Financial Management</div>
                        <h1 class="landing-headline">Shared expenses, organized with precision.</h1>
                        <p class="landing-lead">
                            Organize shared expenses, track multi-party allocations, and simplify debt settlement instantly as a guest, or sign in to sync securely across all your devices.
                        </p>
                        <div class="landing-trust-strip">
                            <div class="landing-trust-item">
                                <span class="landing-trust-icon">${renderIcon('zap', { size: 14 })}</span>
                                <span>Instant Guest Access</span>
                            </div>
                            <div class="landing-trust-item">
                                <span class="landing-trust-icon">${renderIcon('cloud', { size: 14 })}</span>
                                <span>Optional Cloud Sync</span>
                            </div>
                            <div class="landing-trust-item">
                                <span class="landing-trust-icon">${renderIcon('lock', { size: 14 })}</span>
                                <span>Private Invite Links</span>
                            </div>
                            <div class="landing-trust-item">
                                <span class="landing-trust-icon">${renderIcon('globe', { size: 14 })}</span>
                                <span>Multi-Currency FX</span>
                            </div>
                        </div>
                    </div>

                    <div class="landing-form-surface">
                        <div class="landing-surface-header">
                            <h2 class="landing-surface-title">Create a workspace</h2>
                            <div class="landing-surface-desc">Start organizing shared expenses in seconds.</div>
                        </div>

                        <form id="create-group-form">
                            <div class="landing-form-group">
                                <label class="form-label" for="group-name-input">Group name</label>
                                <input 
                                    type="text" 
                                    id="group-name-input" 
                                    class="form-input" 
                                    placeholder="e.g. Goa Summit 2026, Apartment 402, Tokyo Trip" 
                                    required 
                                    maxlength="100"
                                    autofocus
                                >
                                <span class="landing-form-hint">A shared title for this expense ledger.</span>
                            </div>

                            <div class="landing-form-group">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <label class="form-label" for="creator-name-input">Your name</label>
                                    ${isAuthenticated ? `<span class="badge badge-mono badge-owner" style="font-size: var(--font-size-2xs); background: rgba(234, 179, 8, 0.12); color: #92400e; border: 1px solid rgba(234, 179, 8, 0.28); display: inline-flex; align-items: center; gap: 3px;">${renderIcon('crown', { size: 10 })} Account Owner</span>` : ''}
                                </div>
                                <input 
                                    type="text" 
                                    id="creator-name-input" 
                                    class="form-input" 
                                    placeholder="e.g. Jay, Robert" 
                                    value="${escapeHtml(defaultCreatorName)}"
                                    required 
                                    maxlength="60"
                                >
                                <span class="landing-form-hint">${isAuthenticated ? `You will be added as the verified organizer & owner of this workspace.` : `You will be added as the organizer of this ledger.`}</span>
                            </div>

                            <div class="landing-form-group">
                                <label class="form-label" for="currency-select">Currency</label>
                                <select id="currency-select" class="form-select">
                                    ${(() => {
                                        const defaultCurrency = PreferencesManager.getDefaultCurrency();
                                        return SUPPORTED_CURRENCIES.map(c => `
                                            <option value="${c.code}" ${c.code === defaultCurrency ? 'selected' : ''}>
                                                 ${c.code} — ${escapeHtml(c.name)} (${c.symbol})
                                            </option>
                                        `).join('');
                                    })()}
                                </select>
                                <span class="landing-form-hint">Ledger balances will be computed in this base currency.</span>
                            </div>

                            <div class="landing-form-submit">
                                <button type="submit" class="btn btn-primary btn-block btn-lg" id="btn-create-group">
                                    <span>Create Workspace</span>
                                    <span class="btn-arrow-icon" style="display: inline-flex; align-items: center; transition: transform var(--transition-fast);">${renderIcon('arrowRight', { size: 15 })}</span>
                                </button>
                            </div>
                            
                            <div class="landing-form-footnote">
                                <span class="landing-footnote-item">
                                    ${renderIcon('check', { size: 11 })}
                                    <span>Instant guest access</span>
                                </span>
                                <span class="landing-footnote-dot">•</span>
                                <span class="landing-footnote-item">
                                    ${renderIcon('shield', { size: 11 })}
                                    <span>Optional cloud sync</span>
                                </span>
                                <span class="landing-footnote-dot">•</span>
                                <button type="button" id="btn-landing-pair-device" class="landing-footnote-btn" title="Pair device with a 6-digit sync code">
                                    ${renderIcon('key', { size: 11 })}
                                    <span>Pair device with code</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- Dynamic Workspaces Directory Mount (Cloud + Local Auto-Sync) -->
                <div id="landing-workspaces-mount">
                    ${recentWorkspaces.length > 0 ? `
                        <section class="landing-recents-directory">
                            <div class="landing-recents-header">
                                <div class="landing-recents-title">
                                    ${renderIcon('clock', { size: 14 })}
                                    <span>Recent Workspaces</span>
                                </div>
                                <span class="badge badge-settled badge-mono">${recentWorkspaces.length} ${recentWorkspaces.length === 1 ? 'Workspace' : 'Workspaces'}</span>
                            </div>
                            <div class="landing-recents-list">
                                ${recentWorkspaces.map(w => `
                                    <a href="#/g/${w.token}" class="landing-recents-row">
                                        <div class="landing-recents-info">
                                            <span class="landing-recents-icon">${renderIcon('folder', { size: 14 })}</span>
                                            <span class="landing-recents-name">${escapeHtml(w.name)}</span>
                                            <span class="badge badge-settled badge-mono" style="font-size: var(--font-size-2xs); padding: 1px 6px;">${escapeHtml(w.currency || 'INR')}</span>
                                        </div>
                                        <div class="landing-recents-meta">
                                            <span class="landing-recents-date">
                                                ${new Date(w.lastAccessed || Date.now()).toLocaleDateString()}
                                            </span>
                                            <span class="landing-recents-open">
                                                <span>Open</span>
                                                <span class="landing-recents-arrow">${renderIcon('arrowRight', { size: 12 })}</span>
                                            </span>
                                        </div>
                                    </a>
                                `).join('')}
                            </div>
                        </section>
                    ` : ''}
                </div>

                <!-- Value Pillars -->
                <section class="landing-section">
                    <div class="landing-section-header">
                        <h2 class="landing-section-title">Engineered for financial clarity.</h2>
                        <p class="landing-section-desc">
                            Smart Split combines multi-party allocation models with automated debt reduction to make shared finances effortless.
                        </p>
                    </div>

                    <div class="landing-pillars-grid">
                        <div class="landing-pillar-card">
                            <span class="landing-pillar-num">01</span>
                            <div class="landing-pillar-name">Progressive Hybrid Auth</div>
                            <div class="landing-pillar-desc">
                                Start in seconds with zero forced registration. When you're ready, sign in to backup and sync your workspaces across devices.
                            </div>
                        </div>

                        <div class="landing-pillar-card">
                            <span class="landing-pillar-num">02</span>
                            <div class="landing-pillar-name">Flexible Allocations</div>
                            <div class="landing-pillar-desc">
                                Split equally, by percentages, exact amounts, shares, adjustments, or line-by-line itemized receipts.
                            </div>
                        </div>

                        <div class="landing-pillar-card">
                            <span class="landing-pillar-num">03</span>
                            <div class="landing-pillar-name">Debt Simplification</div>
                            <div class="landing-pillar-desc">
                                Calculates the minimum number of transfers needed to settle all group debts.
                            </div>
                        </div>

                        <div class="landing-pillar-card">
                            <span class="landing-pillar-num">04</span>
                            <div class="landing-pillar-name">Instant Settlements</div>
                            <div class="landing-pillar-desc">
                                Dynamic payment deep-links and on-screen QR codes for one-click mobile clearing.
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Authentic Institutional Footer -->
                <div id="landing-footer-mount"></div>
            </div>
        `;

        // Render Landing Footer
        const footerMount = container.querySelector('#landing-footer-mount');
        if (footerMount) {
            Footer.renderLandingFooter(footerMount);
        }

        // Asynchronously sync and render latest cloud + local workspaces
        LandingView.syncAndRenderWorkspaces(container);

        const landingPairBtn = container.querySelector('#btn-landing-pair-device');
        if (landingPairBtn) {
            landingPairBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                GroupHeader.openClaimPairingModal({
                    onClaimed: (claimedToken) => {
                        router.navigate(`/g/${claimedToken}`);
                    }
                });
            });
        }

        const form = container.querySelector('#create-group-form');
        const submitBtn = container.querySelector('#btn-create-group');
        const groupInput = container.querySelector('#group-name-input');
        const creatorInput = container.querySelector('#creator-name-input');
        const currencyInput = container.querySelector('#currency-select');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const groupName = groupInput.value.trim();
            const creatorName = creatorInput.value.trim();
            const currency = currencyInput ? currencyInput.value.trim() : 'INR';

            if (!groupName || !creatorName) {
                Toast.error('Please fill in all required fields.');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = `<span>Creating workspace...</span>`;

            try {
                const res = await api.createGroup(groupName, creatorName, currency);
                const group = res.data.group;
                const creator = res.data.creator;

                if (creator && creator.member_token) {
                    try {
                        localStorage.setItem(`smartsplit_creator_${group.invite_token}`, creator.member_token);
                        localStorage.setItem(`smartsplit_creator_id_${group.invite_token}`, String(creator.id));
                    } catch {}
                }

                LandingView.saveWorkspace(group);
                Toast.success(`Workspace "${escapeHtml(group.name)}" created successfully.`);

                store.setState({
                    currentGroup: group,
                    members: [creator],
                });

                router.navigate(`/g/${group.invite_token}`);
            } catch (err) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<span>Create Workspace & Start Splitting &rarr;</span>`;
                Toast.error(err.message || 'Failed to create workspace. Please try again.');
            }
        });
    }
}
