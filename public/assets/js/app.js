/**
 * Smart Split – Main Client SPA Bootstrap & 2-Column Workspace Coordinator
 */

import { store } from './state.js';
import { router } from './router.js';
import { api } from './api.js';
import * as MathUtils from './utils/math.js';
import * as Formatters from './utils/formatters.js';
import { Toast } from './components/Toast.js';
import { Modal } from './components/Modal.js';
import { LandingView } from './components/LandingView.js';
import { GroupHeader } from './components/GroupHeader.js';
import { MemberList } from './components/MemberList.js';
import { ExpenseModal } from './components/ExpenseModal.js';
import { BalanceSummary } from './components/BalanceSummary.js';
import { SettlementPlan } from './components/SettlementPlan.js';
import { ExpenseList } from './components/ExpenseList.js';
import { ActivityTimeline } from './components/ActivityTimeline.js';
import { ThemeManager } from './utils/theme.js';
import { PreferencesManager } from './utils/preferences.js';
import { AuthModal } from './components/AuthModal.js';
import { SettingsView } from './components/SettingsView.js';
import { Footer } from './components/Footer.js';
import { renderIcon } from './utils/icons.js';
import { sseManager } from './utils/sse.js';
import { ReportModal } from './components/ReportModal.js';
import { FailedMutationsModal } from './components/FailedMutationsModal.js';
import { offlineManager } from './utils/offline.js';

// Expose utilities on window for dev debugging & automated browser testing
window.SmartSplit = {
    store,
    router,
    api,
    MathUtils,
    Formatters,
    Toast,
    Modal,
    LandingView,
    SettingsView,
    ExpenseModal,
    BalanceSummary,
    SettlementPlan,
    ExpenseList,
    ActivityTimeline,
    ThemeManager,
    PreferencesManager,
    AuthModal,
    ReportModal,
    FailedMutationsModal,
    sseManager,
    offlineManager,
    refreshGroupData,
    initAuth,
    renderNavbarAuth,
};

console.log('Smart Split Financial Workspace initialized.');
if (typeof window !== 'undefined' && window.__smartSplitStartupTimer) {
    clearTimeout(window.__smartSplitStartupTimer);
}

const mainContent = document.getElementById('main-content');

/**
 * Perform silent startup authentication handshake to distinguish guest vs authenticated user.
 */
export async function initAuth() {
    try {
        const res = await api.getMe();
        if (res && res.data && res.data.authenticated === true && res.data.user) {
            store.setState({
                currentUser: res.data.user,
                isAuthenticated: true,
                authLoading: false,
            });
            return res.data.user;
        } else {
            store.setState({
                currentUser: null,
                isAuthenticated: false,
                authLoading: false,
            });
            return null;
        }
    } catch (err) {
        store.setState({
            currentUser: null,
            isAuthenticated: false,
            authLoading: false,
        });
        return null;
    }
}

/**
 * Fetch and refresh all group data asynchronously with offline cache fallback.
 * @param {string} token Group invite token
 */
export async function refreshGroupData(token) {
    try {
        // 1. Optimistic Stale-While-Revalidate: If we have a local snapshot cache, render immediately (0ms)
        let hasCache = false;
        if (typeof localStorage !== 'undefined' && token) {
            const cachedRaw = localStorage.getItem(`smartsplit_cache_${token}`);
            if (cachedRaw) {
                try {
                    const cached = JSON.parse(cachedRaw);
                    if (cached?.group) {
                        hasCache = true;
                        store.setState({
                            currentGroup: cached.group,
                            members: cached.members || [],
                            balances: cached.balances || [],
                            settlementPlan: cached.settlementPlan || { transactions: [] },
                            expenses: cached.expenses || [],
                            settlements: cached.settlements || [],
                            isLoading: false,
                            isSyncing: true,
                        });
                    }
                } catch (cacheErr) {}
            }
        }

        if (!hasCache && !store.getState().currentGroup) {
            store.setState({ isLoading: true, isSyncing: true });
        } else {
            store.setState({ isSyncing: true });
        }

        // 2. Fetch consolidated workspace payload in 1 single HTTP request (with multi-endpoint fallback)
        let currentGroup, members, balances, settlementPlan, expenses, settlements;

        try {
            const wsRes = await api.getWorkspace(token);
            if (wsRes?.data?.group) {
                currentGroup = wsRes.data.group;
                members = wsRes.data.members || [];
                balances = wsRes.data.balances?.members || [];
                settlementPlan = wsRes.data.settlement_plan || { transactions: [] };
                expenses = wsRes.data.expenses || [];
                settlements = wsRes.data.settlements || [];
            }
        } catch (wsErr) {
            // Graceful fallback to concurrent multi-endpoint fetch if needed
            const [groupRes, balancesRes, planRes, expensesRes, settlementsRes] = await Promise.all([
                api.getGroup(token),
                api.getBalances(token).catch(() => ({ data: { members: [] } })),
                api.getSettlementPlan(token).catch(() => ({ data: { transactions: [] } })),
                api.getExpenses(token).catch(() => ({ data: { expenses: [] } })),
                api.getSettlements(token).catch(() => ({ data: { settlements: [] } })),
            ]);

            currentGroup = groupRes?.data?.group;
            members = groupRes?.data?.members || [];
            balances = balancesRes?.data?.members || [];
            settlementPlan = planRes?.data || { transactions: [] };
            expenses = expensesRes?.data?.expenses || [];
            settlements = settlementsRes?.data?.settlements || [];
        }

        if (currentGroup) {
            LandingView.saveWorkspace(currentGroup);
        }

        // 3. Silent non-blocking background evaluation for recurring rules
        api.evaluateRecurring(token).catch(() => {});

        // Save local snapshot cache for offline viewing and instant subsequent loads
        if (typeof localStorage !== 'undefined' && token && currentGroup) {
            try {
                localStorage.setItem(`smartsplit_cache_${token}`, JSON.stringify({
                    group: currentGroup,
                    members,
                    balances,
                    settlementPlan,
                    expenses,
                    settlements,
                    cachedAt: Date.now(),
                }));
            } catch (cacheErr) {}
        }

        store.setState({
            currentGroup,
            members,
            balances,
            settlementPlan,
            expenses,
            settlements,
            isLoading: false,
            isSyncing: false,
            isOffline: false,
        });
    } catch (err) {
        // Offline Cache Snapshot Fallback
        if (typeof localStorage !== 'undefined' && token) {
            const cachedRaw = localStorage.getItem(`smartsplit_cache_${token}`);
            if (cachedRaw) {
                try {
                    const cached = JSON.parse(cachedRaw);
                    store.setState({
                        currentGroup: cached.group,
                        members: cached.members || [],
                        balances: cached.balances || [],
                        settlementPlan: cached.settlementPlan || { transactions: [] },
                        expenses: cached.expenses || [],
                        settlements: cached.settlements || [],
                        isLoading: false,
                        isOffline: true,
                    });
                    Toast.info('Viewing offline workspace cache.');
                    return;
                } catch (parseErr) {}
            }
        }

        store.setState({
            error: err.message || 'Failed to load workspace data.',
            isLoading: false,
            isOffline: typeof navigator !== 'undefined' && !navigator.onLine,
        });
    }
}

/**
 * Main DOM View Renderer subscribing to store state mutations.
 */
function renderApp(state) {
    if (!mainContent) return;

    if (state.activeView === 'landing') {
        LandingView.render(mainContent);
        // Remove mobile action bar if present
        document.getElementById('mobile-bottom-bar')?.remove();
        return;
    }

    if (state.activeView === 'settings') {
        SettingsView.render(mainContent);
        // Remove mobile action bar if present
        document.getElementById('mobile-bottom-bar')?.remove();
        return;
    }

    if (state.activeView === 'dashboard') {
        if (state.isLoading) {
            mainContent.innerHTML = `
                <div class="flex-center" style="min-height: 280px; flex-direction: column; gap: var(--space-3);">
                    <div class="navbar-chip-indicator" style="width: 12px; height: 12px; animation: pulse 1s infinite alternate;"></div>
                    <div style="color: var(--text-muted); font-weight: 600; font-size: 0.85rem; font-family: var(--font-mono);">
                        Synchronizing financial ledger...
                    </div>
                </div>
            `;
            return;
        }

        if (state.error) {
            const match = window.location.hash.match(/#\/g\/([a-zA-Z0-9_-]+)/);
            const routeToken = match ? match[1] : null;
            const recentWorkspaces = LandingView.getRecentWorkspaces();
            const matchingWorkspace = routeToken ? recentWorkspaces.find(w => w.token === routeToken) : null;

            mainContent.innerHTML = `
                <div class="panel" style="text-align: center; padding: var(--space-8) var(--space-4); max-width: 480px; margin: var(--space-8) auto;">
                    <div style="display: flex; justify-content: center; margin-bottom: var(--space-3); color: var(--financial-debt);">
                        ${renderIcon('alertTriangle', { size: 38 })}
                    </div>
                    <h2 style="font-size: 1.15rem; font-weight: 800; margin-bottom: var(--space-2);">Workspace Not Found</h2>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: var(--space-5); line-height: 1.5;">${Formatters.escapeHtml(state.error)}</p>
                    <div style="display: flex; flex-direction: column; gap: var(--space-2); max-width: 300px; margin: 0 auto;">
                        ${matchingWorkspace ? `
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-404-remove-stale" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                ${renderIcon('trash2', { size: 13 })}
                                <span>Remove from My Workspaces</span>
                            </button>
                        ` : ''}
                        <a href="#/" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                            <span>Return to Workspaces Hub</span>
                        </a>
                    </div>
                </div>
            `;

            const removeStaleBtn = mainContent.querySelector('#btn-404-remove-stale');
            if (removeStaleBtn && routeToken) {
                removeStaleBtn.addEventListener('click', () => {
                    LandingView.removeRecentWorkspace(routeToken);
                    Toast.success('Workspace removed from your list.');
                    router.navigate('/');
                });
            }
            return;
        }

        const group = state.currentGroup;
        if (!group) return;

        const token = group.invite_token;
        const currency = group.currency_code || group.currency || 'INR';

        // Calculate summary metrics
        const totalSpendCents = (state.expenses || []).reduce((sum, exp) => sum + (exp.amount_cents || 0), 0);
        const pendingTransfers = (state.settlementPlan?.transactions || []).length;
        const memberCount = (state.members || []).length;

        let headerContainer = mainContent.querySelector('#group-header-container');
        let expenseContainer = mainContent.querySelector('#expense-list-container');
        let memberContainer = mainContent.querySelector('#member-list-container');
        let balanceContainer = mainContent.querySelector('#balance-summary-container');
        let settlementContainer = mainContent.querySelector('#settlement-plan-container');
        let wsFooterContainer = mainContent.querySelector('#workspace-footer-container');

        const isMounted = headerContainer && expenseContainer && memberContainer && balanceContainer && settlementContainer;

        if (!isMounted) {
            // Structured 2-column Financial Workspace Layout
            mainContent.innerHTML = `
                <div id="group-header-container"></div>
                
                <div class="workspace-grid">
                    <!-- Left Column (Primary Ledger Table) -->
                    <div class="workspace-col-primary">
                        <div id="expense-list-container"></div>
                    </div>

                    <!-- Right Column (Position Matrix & Settlement Router) -->
                    <div class="workspace-col-secondary">
                        <div id="member-list-container"></div>
                        <div id="balance-summary-container"></div>
                        <div id="settlement-plan-container"></div>
                    </div>
                </div>

                <div id="workspace-footer-container"></div>
            `;

            headerContainer = mainContent.querySelector('#group-header-container');
            expenseContainer = mainContent.querySelector('#expense-list-container');
            memberContainer = mainContent.querySelector('#member-list-container');
            balanceContainer = mainContent.querySelector('#balance-summary-container');
            settlementContainer = mainContent.querySelector('#settlement-plan-container');
            wsFooterContainer = mainContent.querySelector('#workspace-footer-container');
        }

        const triggerAddExpense = () => ExpenseModal.open({
            token,
            members: state.members || [],
            currency,
            onSuccess: () => refreshGroupData(token),
        });

        const triggerEditExpense = (expense) => ExpenseModal.open({
            token,
            members: state.members || [],
            currency,
            expenseToEdit: expense,
            onSuccess: () => refreshGroupData(token),
        });

        const triggerDuplicateExpense = (expense) => ExpenseModal.open({
            token,
            members: state.members || [],
            currency,
            duplicateFrom: expense,
            onSuccess: () => refreshGroupData(token),
        });

        const triggerAddMember = () => MemberList.openAddMemberModal(token, () => refreshGroupData(token));

        // Render Tactile 4-Item Mobile Bottom Action Bar (Thumb-Friendly Ergonomics)
        let mobileBar = document.getElementById('mobile-bottom-bar');
        if (!mobileBar) {
            mobileBar = document.createElement('div');
            mobileBar.id = 'mobile-bottom-bar';
            mobileBar.className = 'mobile-bottom-bar';
            document.body.appendChild(mobileBar);
        }
        if (!mobileBar.dataset.mounted) {
            mobileBar.dataset.mounted = 'true';
            mobileBar.innerHTML = `
                <button type="button" class="mobile-nav-item mobile-nav-item-primary" id="btn-mobile-add-expense" title="Add New Expense">
                    <span class="mobile-nav-item-icon">${renderIcon('plusCircle', { size: 18 })}</span>
                    <span>Expense</span>
                </button>
                <button type="button" class="mobile-nav-item" id="btn-mobile-analytics" title="Visual Spend Analytics">
                    <span class="mobile-nav-item-icon">${renderIcon('barChart2', { size: 18 })}</span>
                    <span>Analytics</span>
                </button>
                <button type="button" class="mobile-nav-item" id="btn-mobile-activity" title="Activity Audit Feed">
                    <span class="mobile-nav-item-icon">${renderIcon('clock', { size: 18 })}</span>
                    <span>Activity</span>
                </button>
                <button type="button" class="mobile-nav-item" id="btn-mobile-settle" title="Settlement Plan">
                    <span class="mobile-nav-item-icon">${renderIcon('zap', { size: 18 })}</span>
                    <span>Settle</span>
                </button>
            `;
        }

        const mobileAddBtn = mobileBar.querySelector('#btn-mobile-add-expense');
        if (mobileAddBtn) mobileAddBtn.onclick = () => triggerAddExpense();

        const mobileAnalyticsBtn = mobileBar.querySelector('#btn-mobile-analytics');
        if (mobileAnalyticsBtn) mobileAnalyticsBtn.onclick = () => BalanceSummary.openAnalyticsModal(token, currency);

        const mobileActivityBtn = mobileBar.querySelector('#btn-mobile-activity');
        if (mobileActivityBtn) mobileActivityBtn.onclick = () => ActivityTimeline.open(token, currency);

        const mobileSettleBtn = mobileBar.querySelector('#btn-mobile-settle');
        if (mobileSettleBtn) {
            mobileSettleBtn.onclick = () => {
                const settleEl = document.getElementById('settlement-plan-container');
                if (settleEl) {
                    settleEl.scrollIntoView({ behavior: 'smooth' });
                }
            };
        }

        if (headerContainer) {
            GroupHeader.render(headerContainer, group, {
                totalSpendCents,
                memberCount,
                pendingTransfers,
            }, triggerAddExpense, () => refreshGroupData(token));
        }

        if (expenseContainer) {
            ExpenseList.render(expenseContainer, {
                token,
                expenses: state.expenses || [],
                members: state.members || [],
                currency,
                onAddExpense: triggerAddExpense,
                onEditExpense: triggerEditExpense,
                onDuplicateExpense: triggerDuplicateExpense,
                onDelete: () => refreshGroupData(token),
            });
        }

        if (memberContainer) {
            MemberList.render(memberContainer, state.members, token, () => refreshGroupData(token));
        }

        if (balanceContainer) {
            BalanceSummary.render(balanceContainer, {
                token,
                balances: state.balances || [],
                expenses: state.expenses || [],
                currency,
            });
        }

        if (settlementContainer) {
            SettlementPlan.render(settlementContainer, {
                token,
                plan: state.settlementPlan || {},
                settlements: state.settlements || [],
                members: state.members || [],
                currency,
                groupName: group.name || '',
                onUpdate: () => refreshGroupData(token),
            });
        }

        if (wsFooterContainer) {
            Footer.renderWorkspaceBar(wsFooterContainer, { currency });
        }

        // Setup Global Keyboard Shortcuts (E for Expense, M for Member)
        setupKeyboardShortcuts(triggerAddExpense, triggerAddMember);
    }
}

// Initialize Theme & Preferences Managers
ThemeManager.init();
PreferencesManager.init();

// Wire up Top Navbar Workspaces Hub Button
const workspacesNavBtn = document.getElementById('btn-navbar-workspaces');
if (workspacesNavBtn && !workspacesNavBtn.dataset.bound) {
    workspacesNavBtn.dataset.bound = 'true';
    workspacesNavBtn.addEventListener('click', () => {
        LandingView.openWorkspacesModal();
    });
}

let activeExpenseTrigger = null;
let activeMemberTrigger = null;
let keyboardShortcutAttached = false;

function setupKeyboardShortcuts(onAddExpense, onAddMember) {
    activeExpenseTrigger = onAddExpense;
    activeMemberTrigger = onAddMember;

    if (keyboardShortcutAttached) return;
    keyboardShortcutAttached = true;

    window.addEventListener('keydown', (e) => {
        // 1. Modal Dismissal (Esc)
        if (e.key === 'Escape') {
            const overlay = document.getElementById('modal-overlay');
            if (overlay && overlay.classList.contains('active')) {
                e.preventDefault();
                Modal.close();
            }
            return;
        }

        // 2. Modal Form Submission (Ctrl + Enter / Cmd + Enter)
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            const overlay = document.getElementById('modal-overlay');
            if (overlay && overlay.classList.contains('active')) {
                e.preventDefault();
                const confirmBtn = overlay.querySelector('.modal-btn-confirm, #btn-expense-submit, .modal-footer button.btn-primary, .modal-footer button.btn-success');
                if (confirmBtn && !confirmBtn.disabled) {
                    confirmBtn.click();
                }
            }
            return;
        }

        // 3. Do not trigger single-key shortcuts when typing in inputs, textareas, selects, or when modal is open
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isEditing = activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select' || document.activeElement?.isContentEditable;
        const isModalOpen = document.getElementById('modal-overlay')?.classList.contains('active');

        if (isEditing || isModalOpen) return;

        // 4. Power-User Single Key Shortcuts
        if (e.key === '/') {
            const searchInput = document.getElementById('ledger-search-input');
            if (searchInput) {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        } else if (e.key === 's' || e.key === 'S') {
            const settleEl = document.getElementById('settlement-plan-container');
            if (settleEl) {
                e.preventDefault();
                settleEl.scrollIntoView({ behavior: 'smooth' });
            }
        } else if (e.key === 'a' || e.key === 'A') {
            const group = store.getState()?.currentGroup;
            if (group) {
                e.preventDefault();
                const token = group.invite_token;
                const currency = group.currency_code || group.currency || 'INR';
                BalanceSummary.openAnalyticsModal(token, currency);
            }
        } else if (e.key === 'e' || e.key === 'E') {
            if (typeof activeExpenseTrigger === 'function') {
                e.preventDefault();
                activeExpenseTrigger();
            }
        } else if (e.key === 'm' || e.key === 'M') {
            if (typeof activeMemberTrigger === 'function') {
                e.preventDefault();
                activeMemberTrigger();
            }
        } else if (e.key === '?' || (e.shiftKey && e.key === '/')) {
            e.preventDefault();
            Footer.openShortcutsModal();
        }
    });
}

/**
 * Render reactive authentication button / user profile dropdown in top navbar.
 * @param {Object} state
 */
export function renderNavbarAuth(state) {
    const container = document.getElementById('navbar-auth-container');
    if (!container) return;

    if (!state.isAuthenticated || !state.currentUser) {
        // Unauthenticated Guest State: Render Sign In button
        container.innerHTML = `
            <button type="button" class="btn btn-secondary btn-sm" id="btn-navbar-signin" title="Sign In or Access Cloud Workspaces">
                ${renderIcon('logIn', { size: 14 })}
                <span>Sign In</span>
            </button>
        `;

        const signInBtn = container.querySelector('#btn-navbar-signin');
        if (signInBtn) {
            signInBtn.addEventListener('click', () => {
                AuthModal.open({ initialMode: 'login' });
            });
        }
        return;
    }

    // Authenticated User State: Render interactive profile chip with dropdown
    const user = state.currentUser;
    const emoji = user.avatar_emoji || '👤';
    const displayName = user.display_name || (user.email ? user.email.split('@')[0] : 'User');

    container.innerHTML = `
        <button type="button" class="navbar-workspaces-btn" id="btn-navbar-profile" title="Account & Profile">
            <span style="display: inline-flex; align-items: center; color: var(--brand-primary);">${renderIcon('user', { size: 14 })}</span>
            <span style="max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${Formatters.escapeHtml(displayName)}</span>
            <span style="display: inline-flex; align-items: center; opacity: 0.7;">${renderIcon('chevronDown', { size: 10 })}</span>
            <!-- avatar_emoji: ${Formatters.escapeHtml(emoji)} -->
        </button>

        <div id="navbar-profile-dropdown" class="navbar-profile-dropdown" style="display: none; position: absolute; right: 0; top: calc(100% + 6px); width: 220px; background: var(--surface-primary, #ffffff); border: 1px solid var(--border-color, #e5e7eb); border-radius: var(--radius-sm, 8px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); z-index: 1000; padding: 6px 0;">
            <div style="padding: 8px 14px; border-bottom: 1px solid var(--border-subtle, rgba(0,0,0,0.08));">
                <div style="font-weight: 700; font-size: var(--font-size-sm, 0.85rem); color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    ${Formatters.escapeHtml(displayName)}
                </div>
                <div style="font-size: var(--font-size-2xs, 0.7rem); color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: var(--font-mono);">
                    ${Formatters.escapeHtml(user.email || '')}
                </div>
            </div>
            
            <div style="padding: 4px 0;">
                <button type="button" id="btn-profile-settings" class="dropdown-item" style="width: 100%; text-align: left; background: none; border: none; padding: 8px 14px; font-size: var(--font-size-xs, 0.8rem); color: var(--text-primary); cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    ${renderIcon('user', { size: 14 })} <span>Settings & Profile</span>
                </button>
                <button type="button" id="btn-profile-workspaces" class="dropdown-item" style="width: 100%; text-align: left; background: none; border: none; padding: 8px 14px; font-size: var(--font-size-xs, 0.8rem); color: var(--text-primary); cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    ${renderIcon('folder', { size: 14 })} <span>My Workspaces</span>
                </button>
                <button type="button" id="btn-profile-signout" class="dropdown-item" style="width: 100%; text-align: left; background: none; border: none; padding: 8px 14px; font-size: var(--font-size-xs, 0.8rem); color: var(--text-primary); cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    ${renderIcon('logOut', { size: 14 })} <span>Sign Out</span>
                </button>
            </div>

            <div style="border-top: 1px solid var(--border-subtle, rgba(0,0,0,0.08)); padding: 4px 0;">
                <button type="button" id="btn-profile-delete-account" class="dropdown-item" style="width: 100%; text-align: left; background: none; border: none; padding: 8px 14px; font-size: var(--font-size-xs, 0.8rem); color: var(--financial-debt, #ef4444); cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    ${renderIcon('trash2', { size: 14 })} <span>Delete Account</span>
                </button>
            </div>
        </div>
    `;

    const profileBtn = container.querySelector('#btn-navbar-profile');
    const dropdown = container.querySelector('#navbar-profile-dropdown');

    if (profileBtn && dropdown) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isShown = dropdown.style.display === 'block';
            dropdown.style.display = isShown ? 'none' : 'block';
        });

        // Close dropdown when clicking outside
        const closeDropdownHandler = (e) => {
            if (!container.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        };
        document.addEventListener('click', closeDropdownHandler);
    }

    // Settings & Profile action
    const settingsBtn = container.querySelector('#btn-profile-settings');
    if (settingsBtn) {
        settingsBtn.addEventListener('click', () => {
            if (dropdown) dropdown.style.display = 'none';
            router.navigate('/settings');
        });
    }

    // Workspaces action
    const workspacesBtn = container.querySelector('#btn-profile-workspaces');
    if (workspacesBtn) {
        workspacesBtn.addEventListener('click', () => {
            if (dropdown) dropdown.style.display = 'none';
            LandingView.openWorkspacesModal();
        });
    }

    // Sign Out action
    const signoutBtn = container.querySelector('#btn-profile-signout');
    if (signoutBtn) {
        signoutBtn.addEventListener('click', async () => {
            if (dropdown) dropdown.style.display = 'none';
            try {
                await api.logout();
                store.setState({ currentUser: null, isAuthenticated: false });
                Toast.show('Signed out successfully', 'success');
            } catch (err) {
                store.setState({ currentUser: null, isAuthenticated: false });
                Toast.show('Signed out', 'success');
            }
        });
    }

    // Delete Account action
    const deleteBtn = container.querySelector('#btn-profile-delete-account');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', () => {
            if (dropdown) dropdown.style.display = 'none';
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
                    <form id="auth-delete-account-form">
                        <div id="delete-account-error" style="display: none; background: rgba(239,68,68,0.1); border: 1px solid var(--financial-debt); color: var(--financial-debt); padding: 8px 12px; border-radius: var(--radius-xs); font-size: var(--font-size-xs); margin-bottom: var(--space-3);"></div>
                        <div class="form-group" style="margin-bottom: var(--space-4);">
                            <label class="form-label" style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase;">Confirm Password</label>
                            <input type="password" id="delete-account-password" class="form-control" placeholder="Enter your password" required style="width: 100%; box-sizing: border-box;">
                        </div>
                        <div style="display: flex; gap: var(--space-2);">
                            <button type="button" class="btn btn-secondary btn-sm modal-btn-cancel" style="flex: 1;">Cancel</button>
                            <button type="submit" id="btn-confirm-delete-account" class="btn btn-danger btn-sm" style="flex: 1; font-weight: 700;">Delete Account</button>
                        </div>
                    </form>
                `,
                onMount: (overlay) => {
                    const form = overlay.querySelector('#auth-delete-account-form');
                    const cancelBtn = overlay.querySelector('.modal-btn-cancel');
                    if (cancelBtn) cancelBtn.addEventListener('click', () => Modal.close());

                    if (form) {
                        form.addEventListener('submit', async (e) => {
                            e.preventDefault();
                            const passInput = overlay.querySelector('#delete-account-password');
                            const errDiv = overlay.querySelector('#delete-account-error');
                            const submitBtn = overlay.querySelector('#btn-confirm-delete-account');

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
                }
            });
        });
    }
}

// Subscribe renderers to store
store.subscribe((state) => {
    renderApp(state);
    renderNavbarAuth(state);
});

// Define Core SPA Routes
router.on('', ({ pair } = {}) => {
    sseManager.disconnect();
    store.setState({ activeView: 'landing', currentGroup: null });
    if (pair) {
        setTimeout(() => {
            GroupHeader.openClaimPairingModal({
                initialCode: pair,
                onClaimed: (claimedToken) => {
                    router.navigate(`/g/${claimedToken}`);
                }
            });
        }, 300);
    }
});

router.on('/', ({ pair } = {}) => {
    sseManager.disconnect();
    store.setState({ activeView: 'landing', currentGroup: null });
    if (pair) {
        setTimeout(() => {
            GroupHeader.openClaimPairingModal({
                initialCode: pair,
                onClaimed: (claimedToken) => {
                    router.navigate(`/g/${claimedToken}`);
                }
            });
        }, 300);
    }
});

router.on('/settings', () => {
    sseManager.disconnect();
    store.setState({ activeView: 'settings', currentGroup: null });
});

router.on('/g/:token', async ({ token, pair } = {}) => {
    sseManager.connect(token, () => refreshGroupData(token));
    store.setState({ activeView: 'dashboard', isLoading: true, error: null });
    await refreshGroupData(token);
    if (pair) {
        setTimeout(() => {
            GroupHeader.openClaimPairingModal({
                token,
                initialCode: pair,
                onClaimed: async () => {
                    await refreshGroupData(token);
                }
            });
        }, 300);
    }
});

router.on('/group/:token', async (params) => {
    router.navigate(`/g/${params.token}${params.pair ? `?pair=${params.pair}` : ''}`);
});

window.addEventListener('beforeunload', () => {
    sseManager.disconnect();
});

// Network status change listeners & Auto-Sync Hook
window.addEventListener('online', async () => {
    Toast.info('Internet connection restored. Synchronizing data...');
    const match = window.location.hash.match(/#\/g\/([a-zA-Z0-9_-]+)/);
    const activeToken = match ? match[1] : null;

    // Drain queued offline mutations
    await offlineManager.drainQueue(api, async (syncedToken) => {
        if (activeToken === syncedToken) {
            await refreshGroupData(activeToken);
        }
    });

    if (activeToken) {
        await refreshGroupData(activeToken);
    }
});

window.addEventListener('offline', () => {
    Toast.warning('You are offline. Any changes will be saved locally and synced when reconnected.');
    store.setState({ isOffline: true });
});

// Initialize startup authentication handshake, navbar, and router
initAuth().then(() => {
    renderNavbarAuth(store.getState());
});
router.start();
renderNavbarAuth(store.getState());
