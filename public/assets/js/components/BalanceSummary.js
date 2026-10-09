/**
 * Smart Split V2 – Net Financial Position Statement Matrix with Visual SVG Spend Analytics & Member Ledgers
 * Principles: Tabular Precision, Right-Aligned Numbers, Zero Decorative Clutter
 */

import { api } from '../api.js';
import * as Formatters from '../utils/formatters.js';
import { Modal } from './Modal.js';
import { Toast } from './Toast.js';
import { renderIcon } from '../utils/icons.js';

export class BalanceSummary {
    /**
     * Render the Financial Position Matrix and Category Breakdown into target element.
     * @param {HTMLElement} container
     * @param {Object} options
     * @param {string} [options.token] Group token for member ledger drilldown & analytics
     * @param {Array} options.balances Member balances from /balances API
     * @param {Array} options.expenses All group expenses
     * @param {string} [options.currency] Default: 'INR'
     */
    static render(container, { token = '', balances = [], expenses = [], currency = 'INR' }) {
        if (!container) return;

        let totalCreditors = 0;
        let totalDebtors = 0;
        balances.forEach((b) => {
            if (b.net_balance_cents > 0) totalCreditors++;
            else if (b.net_balance_cents < 0) totalDebtors++;
        });

        // Compute Category Analytics
        const categoryMap = {};
        let totalGroupSpendCents = 0;
        expenses.forEach((exp) => {
            const amount = exp.total_amount_cents || exp.amount_cents || 0;
            totalGroupSpendCents += amount;
            const catName = exp.category?.name || 'General';
            const catIcon = exp.category?.icon || '';
            const catColor = exp.category?.color || '#475569';
            if (!categoryMap[catName]) {
                categoryMap[catName] = { name: catName, icon: catIcon, color: catColor, totalCents: 0, count: 0 };
            }
            categoryMap[catName].totalCents += amount;
            categoryMap[catName].count++;
        });
        const categories = Object.values(categoryMap).sort((a, b) => b.totalCents - a.totalCents);

        // Generate compact inline SVG Donut Ring
        const inlineDonutSvg = BalanceSummary.generateDonutSvg(
            categories.map(c => ({
                name: c.name,
                icon: c.icon,
                color: c.color,
                spent_cents: c.totalCents,
                percentage: totalGroupSpendCents > 0 ? Math.round((c.totalCents / totalGroupSpendCents) * 100) : 0,
            })),
            totalGroupSpendCents,
            currency,
            110
        );

        const html = `
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title-text">
                        <span>Net Position Matrix</span>
                    </div>
                    <div class="panel-header-actions" style="display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-view-analytics" title="View Visual Spend Analytics & Daily Burn Velocity">
                            ${renderIcon('barChart2', { size: 13 })}
                            <span>Analytics</span>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-view-bilateral" title="View direct 1-on-1 pairwise balances">
                            ${renderIcon('users', { size: 13 })}
                            <span>1-on-1 Balances</span>
                        </button>
                        <span class="badge badge-settled badge-mono">
                            ${totalCreditors} to receive • ${totalDebtors} to pay
                        </span>
                    </div>
                </div>

                ${balances.length === 0 ? `
                    <div class="empty-state" style="padding: var(--space-6) var(--space-4);">
                        <div class="empty-state-text">No balances computed yet.</div>
                    </div>
                ` : `
                    <div class="position-matrix">
                        ${balances.map((m) => {
                            const net = m.net_balance_cents;
                            let avatarClass = 'settled';
                            let badgeClass = 'badge-settled';
                            let badgeText = 'Settled (₹0.00)';

                            if (net > 0) {
                                avatarClass = 'credit';
                                badgeClass = 'badge-credit';
                                badgeText = `+ ${Formatters.formatCurrency(net, currency)}`;
                            } else if (net < 0) {
                                avatarClass = 'debt';
                                badgeClass = 'badge-debt';
                                badgeText = `- ${Formatters.formatCurrency(Math.abs(net), currency)}`;
                            }

                            return `
                                <div class="position-row member-statement-trigger" data-member-id="${m.member_id || m.id}" style="cursor: pointer;" title="Click to view personal financial statement">
                                    <div class="position-info">
                                        ${Formatters.renderMemberAvatar(m, token, { size: 28, extraClass: `position-avatar ${avatarClass}` })}
                                        <div class="position-details">
                                            <span class="position-name">${Formatters.escapeHtml(m.name)}</span>
                                            <span class="position-sub">
                                                Paid ${Formatters.formatCurrency(m.total_paid_cents || 0, currency)} • Share ${Formatters.formatCurrency(m.total_owed_cents || 0, currency)}
                                            </span>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: var(--space-2);">
                                        <span class="badge ${badgeClass} badge-mono">${badgeText}</span>
                                        <span style="color: var(--text-subtle); font-size: var(--font-size-xs);">›</span>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `}

                ${categories.length > 0 ? `
                    <div style="border-top: 1px solid var(--border-color); padding: var(--space-4);">
                        <div style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: var(--space-3); display: flex; justify-content: space-between; align-items: center;">
                            <span>Category Distribution</span>
                            <span class="tnum" style="color: var(--text-primary); font-size: var(--font-size-sm); font-weight: 700;">${Formatters.formatCurrency(totalGroupSpendCents, currency)}</span>
                        </div>

                        <div style="display: flex; gap: var(--space-4); align-items: center; flex-wrap: wrap;">
                            <div style="flex-shrink: 0; display: flex; justify-content: center; width: 110px;">
                                ${inlineDonutSvg}
                            </div>
                            <div style="flex: 1; min-width: 180px; display: flex; flex-direction: column; gap: var(--space-2);">
                                ${categories.slice(0, 5).map((cat) => {
                                    const pct = totalGroupSpendCents > 0 ? Math.round((cat.totalCents / totalGroupSpendCents) * 100) : 0;
                                    return `
                                        <div>
                                            <div style="display: flex; justify-content: space-between; font-size: var(--font-size-xs); margin-bottom: 2px;">
                                                <span style="font-weight: 500; color: var(--text-primary); display: flex; align-items: center; gap: 4px;">
                                                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 2px; background: ${cat.color};"></span>
                                                    <span>${Formatters.escapeHtml(cat.name)}</span>
                                                    <span style="color: var(--text-muted); font-size: var(--font-size-2xs);">(${cat.count})</span>
                                                </span>
                                                <span class="tnum" style="font-weight: 600; color: var(--text-primary);">${Formatters.formatCurrency(cat.totalCents, currency)} (${pct}%)</span>
                                            </div>
                                            <div style="height: 4px; background: var(--surface-secondary); border-radius: 2px; overflow: hidden;">
                                                <div style="height: 100%; width: ${pct}%; background-color: ${cat.color}; border-radius: 2px;"></div>
                                            </div>
                                        </div>
                                    `;
                                }).join('')}
                                ${categories.length > 5 ? `
                                    <div style="text-align: right; font-size: var(--font-size-xs); color: var(--brand-accent); cursor: pointer; font-weight: 600;" id="btn-more-categories">
                                        + ${categories.length - 5} more categories ›
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                ` : ''}
            </div>
        `;

        container.innerHTML = html;

        // Attach Member Statement Drilldown triggers
        const triggers = container.querySelectorAll('.member-statement-trigger');
        triggers.forEach((row) => {
            row.addEventListener('click', () => {
                const memberId = Number(row.dataset.memberId);
                const member = balances.find((b) => Number(b.member_id || b.id) === memberId);
                if (!member || !token) return;
                BalanceSummary.openMemberLedgerModal(token, member, currency);
            });
        });

        // Attach Bilateral Balances view trigger
        const bilateralBtn = container.querySelector('#btn-view-bilateral');
        if (bilateralBtn) {
            bilateralBtn.addEventListener('click', () => {
                if (!token) return;
                BalanceSummary.openBilateralModal(token, currency);
            });
        }

        // Attach Visual Spend Analytics Modal trigger
        const analyticsBtn = container.querySelector('#btn-view-analytics');
        const moreCategoriesBtn = container.querySelector('#btn-more-categories');

        if (analyticsBtn) analyticsBtn.addEventListener('click', () => BalanceSummary.openAnalyticsModal(token, currency));
        if (moreCategoriesBtn) moreCategoriesBtn.addEventListener('click', () => BalanceSummary.openAnalyticsModal(token, currency));
    }

    /**
     * Open 1-on-1 Bilateral Balances drilldown modal.
     * @param {string} token Group invite token
     * @param {string} [currency] Default: 'INR'
     */
    static openBilateralModal(token, currency = 'INR') {
        if (!token) return;

        const skeletonContent = `
            <div style="margin-bottom: var(--space-3);">
                <p style="font-size: var(--font-size-xs); color: var(--text-muted); margin-bottom: var(--space-3);">
                    Direct 1-on-1 debt relationships computed before multi-party greedy graph simplification.
                </p>
                <div id="bilateral-modal-content" style="display: flex; flex-direction: column; gap: var(--space-2); max-height: 280px; overflow-y: auto;">
                    ${[1, 2, 3].map(() => `
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-2) var(--space-3); background: var(--surface-secondary); border-radius: var(--radius-xs); font-size: var(--font-size-xs); border: 1px solid var(--border-subtle);">
                            <div style="display: flex; align-items: center; gap: var(--space-2);">
                                <span class="skeleton-shimmer" style="width: 20px; height: 20px; border-radius: 50%;"></span>
                                <span class="skeleton-shimmer" style="width: 60px; height: 12px;"></span>
                                <span class="skeleton-shimmer" style="width: 30px; height: 10px;"></span>
                                <span class="skeleton-shimmer" style="width: 20px; height: 20px; border-radius: 50%;"></span>
                                <span class="skeleton-shimmer" style="width: 60px; height: 12px;"></span>
                            </div>
                            <div>
                                <span class="skeleton-shimmer" style="width: 50px; height: 14px;"></span>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        Modal.open({
            title: '1-on-1 Bilateral Debt Relationships',
            content: skeletonContent,
            size: 'md',
            confirmText: 'Done',
            confirmClass: 'btn-primary',
            showCancel: false,
            onMount: (modalEl) => {
                const loadBilateral = async () => {
                    const contentContainer = modalEl.querySelector('#bilateral-modal-content');
                    try {
                        const res = await api.getBilateralBalances(token);
                        const overlay = document.getElementById('modal-overlay');
                        if (!overlay || !overlay.classList.contains('active') || !modalEl.isConnected || !contentContainer) return;

                        const pairs = res.data?.pairs || [];
                        if (pairs.length === 0) {
                            contentContainer.innerHTML = `
                                <div class="empty-state" style="padding: var(--space-4);">
                                    <div class="empty-state-text">No bilateral debts recorded yet.</div>
                                </div>
                            `;
                            return;
                        }

                        contentContainer.innerHTML = pairs.map((p) => {
                            const isSettled = p.status === 'SETTLED' || p.amount_cents === 0;
                            return `
                                <div style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-2) var(--space-3); background: var(--surface-secondary); border-radius: var(--radius-xs); font-size: var(--font-size-xs); border: 1px solid var(--border-subtle);">
                                    <div style="display: flex; align-items: center; gap: var(--space-2);">
                                        ${Formatters.renderMemberAvatar(p.from_member_name, token, { size: 20 })}
                                        <span><strong>${Formatters.escapeHtml(p.from_member_name)}</strong></span>
                                        <span style="color: var(--text-muted); font-size: var(--font-size-2xs);">${isSettled ? 'is settled with' : 'owes'}</span>
                                        ${Formatters.renderMemberAvatar(p.to_member_name, token, { size: 20 })}
                                        <span><strong>${Formatters.escapeHtml(p.to_member_name)}</strong></span>
                                    </div>
                                    <div>
                                        <strong class="tnum" style="color: ${isSettled ? 'var(--text-muted)' : 'var(--financial-debt)'};">
                                            ${isSettled ? '₹0.00' : Formatters.formatCurrency(p.amount_cents, currency)}
                                        </strong>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    } catch (err) {
                        const overlay = document.getElementById('modal-overlay');
                        if (!overlay || !overlay.classList.contains('active') || !modalEl.isConnected || !contentContainer) return;
                        contentContainer.innerHTML = `
                            <div style="text-align: center; padding: var(--space-4); color: var(--financial-debt); font-size: var(--font-size-xs);">
                                <div style="margin-bottom: var(--space-2);">${Formatters.escapeHtml(err.message || 'Failed to load 1-on-1 balances.')}</div>
                                <button type="button" class="btn btn-secondary btn-xs btn-retry-bilateral">Retry</button>
                            </div>
                        `;
                        const retryBtn = contentContainer.querySelector('.btn-retry-bilateral');
                        if (retryBtn) {
                            retryBtn.addEventListener('click', loadBilateral);
                        }
                    }
                };

                loadBilateral();
            },
        });
    }

    /**
     * Open individual Member Financial Statement Ledger modal.
     * @param {string} token Group invite token
     * @param {Object} member Member summary object from /balances
     * @param {string} [currency] Default: 'INR'
     */
    static openMemberLedgerModal(token, member, currency = 'INR') {
        const memberId = Number(member.member_id || member.id);
        if (!memberId || !token) return;

        const initialContent = `
            <div style="margin-bottom: var(--space-3);">
                <!-- Skeleton Net Position Header -->
                <div id="member-ledger-header" style="display: flex; justify-content: space-between; align-items: center; background: var(--surface-secondary); padding: var(--space-3) var(--space-4); border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: var(--space-3);">
                    <div>
                        <div style="font-size: var(--font-size-2xs); text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.04em;">Net Balance Position</div>
                        <div style="min-height: 28px; display: flex; align-items: center; margin-top: 2px;">
                            <span class="skeleton-shimmer" style="width: 110px; height: 20px;"></span>
                        </div>
                    </div>
                    <div style="text-align: right; font-size: var(--font-size-xs); font-family: var(--font-mono); color: var(--text-secondary); display: flex; flex-direction: column; gap: 4px; align-items: flex-end;">
                        <div style="display: flex; align-items: center; gap: 4px;">Total Paid: <span class="skeleton-shimmer" style="width: 55px; height: 12px;"></span></div>
                        <div style="display: flex; align-items: center; gap: 4px;">Total Share: <span class="skeleton-shimmer" style="width: 55px; height: 12px;"></span></div>
                    </div>
                </div>

                <!-- Ledger Sections Mount Target -->
                <div id="member-ledger-mount">
                    <div style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: var(--space-2);">
                        Outlay & Contributions
                    </div>
                    <div style="border: 1px solid var(--border-color); border-radius: var(--radius-xs); margin-bottom: var(--space-3); padding: var(--space-2); display: flex; flex-direction: column; gap: 6px;">
                        <span class="skeleton-shimmer" style="width: 100%; height: 16px;"></span>
                        <span class="skeleton-shimmer" style="width: 85%; height: 16px;"></span>
                    </div>

                    <div style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: var(--space-2);">
                        Consumed Shares & Owed Items
                    </div>
                    <div style="border: 1px solid var(--border-color); border-radius: var(--radius-xs); padding: var(--space-2); display: flex; flex-direction: column; gap: 6px;">
                        <span class="skeleton-shimmer" style="width: 100%; height: 16px;"></span>
                        <span class="skeleton-shimmer" style="width: 90%; height: 16px;"></span>
                    </div>
                </div>
            </div>
        `;

        Modal.open({
            title: `${Formatters.escapeHtml(member.name)} — Financial Statement`,
            content: initialContent,
            size: 'md',
            confirmText: 'Done',
            confirmClass: 'btn-primary',
            showCancel: false,
            onMount: (modalEl) => {
                const loadLedger = async () => {
                    const headerEl = modalEl.querySelector('#member-ledger-header');
                    const mountEl = modalEl.querySelector('#member-ledger-mount');
                    try {
                        const res = await api.getMemberLedger(token, memberId);
                        const overlay = document.getElementById('modal-overlay');
                        if (!overlay || !overlay.classList.contains('active') || !modalEl.isConnected || !mountEl) return;

                        const ledger = res.data?.ledger || res.data || {};
                        const paidExpenses = ledger.paid_expenses || [];
                        const consumedExpenses = ledger.consumed_expenses || [];
                        const settlementsSent = ledger.settlements_sent || [];
                        const settlementsReceived = ledger.settlements_received || [];

                        const totalPaid = paidExpenses.reduce((sum, p) => sum + (p.amount_paid_cents || 0), 0);
                        const totalOwed = consumedExpenses.reduce((sum, c) => sum + (c.amount_owed_cents || 0), 0);
                        const totalSent = settlementsSent.reduce((sum, s) => sum + (s.amount_cents || 0), 0);
                        const totalReceived = settlementsReceived.reduce((sum, s) => sum + (s.amount_cents || 0), 0);
                        const netBalance = (totalPaid - totalOwed) + (totalSent - totalReceived);

                        if (headerEl) {
                            headerEl.innerHTML = `
                                <div>
                                    <div style="font-size: var(--font-size-2xs); text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.04em;">Net Balance Position</div>
                                    <div style="font-size: var(--font-size-lg); font-weight: 700; font-family: var(--font-mono); color: ${netBalance >= 0 ? 'var(--financial-credit)' : 'var(--financial-debt)'};">
                                        ${netBalance >= 0 ? '+' : ''}${Formatters.formatCurrency(netBalance, currency)}
                                    </div>
                                </div>
                                <div style="text-align: right; font-size: var(--font-size-xs); font-family: var(--font-mono); color: var(--text-secondary);">
                                    <div>Total Paid: <strong>${Formatters.formatCurrency(totalPaid, currency)}</strong></div>
                                    <div>Total Share: <strong>${Formatters.formatCurrency(totalOwed, currency)}</strong></div>
                                </div>
                            `;
                        }

                        mountEl.innerHTML = `
                            <div style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: var(--space-2);">
                                Outlay & Contributions (${paidExpenses.length})
                            </div>
                            ${paidExpenses.length === 0 ? `
                                <div style="color: var(--text-muted); font-size: var(--font-size-xs); margin-bottom: var(--space-3);">No expenses funded by this member.</div>
                            ` : `
                                <div style="max-height: 140px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-xs); margin-bottom: var(--space-3);">
                                    <table class="data-table">
                                        <tbody>
                                            ${paidExpenses.map(p => `
                                                <tr>
                                                    <td class="table-cell-date tnum">${Formatters.formatDate(p.expense_date)}</td>
                                                    <td><strong>${Formatters.escapeHtml(p.title)}</strong></td>
                                                    <td class="table-cell-amount" style="color: var(--financial-credit);">+${Formatters.formatCurrency(p.amount_paid_cents, currency)}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            `}

                            <div style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: var(--space-2);">
                                Consumed Shares & Owed Items (${consumedExpenses.length})
                            </div>
                            ${consumedExpenses.length === 0 ? `
                                <div style="color: var(--text-muted); font-size: var(--font-size-xs);">No expenses shared with this member.</div>
                            ` : `
                                <div style="max-height: 140px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-xs);">
                                    <table class="data-table">
                                        <tbody>
                                            ${consumedExpenses.map(c => `
                                                <tr>
                                                    <td class="table-cell-date tnum">${Formatters.formatDate(c.expense_date)}</td>
                                                    <td><strong>${Formatters.escapeHtml(c.title)}</strong></td>
                                                    <td class="table-cell-amount" style="color: var(--financial-debt);">-${Formatters.formatCurrency(c.amount_owed_cents, currency)}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            `}
                        `;
                    } catch (err) {
                        const overlay = document.getElementById('modal-overlay');
                        if (!overlay || !overlay.classList.contains('active') || !modalEl.isConnected || !mountEl) return;
                        if (headerEl) {
                            headerEl.innerHTML = `
                                <div>
                                    <div style="font-size: var(--font-size-2xs); text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.04em;">Net Balance Position</div>
                                    <div style="font-size: var(--font-size-sm); color: var(--text-muted); font-family: var(--font-mono);">Unavailable</div>
                                </div>
                                <div style="text-align: right; font-size: var(--font-size-xs); font-family: var(--font-mono); color: var(--text-secondary);">
                                    <div>Total Paid: <strong>—</strong></div>
                                    <div>Total Share: <strong>—</strong></div>
                                </div>
                            `;
                        }
                        mountEl.innerHTML = `
                            <div style="text-align: center; padding: var(--space-4); color: var(--financial-debt); font-size: var(--font-size-sm);">
                                <div style="margin-bottom: var(--space-2);">${Formatters.escapeHtml(err.message || 'Failed to load member statement.')}</div>
                                <button type="button" class="btn btn-secondary btn-xs btn-retry-ledger">Retry</button>
                            </div>
                        `;
                        const retryBtn = mountEl.querySelector('.btn-retry-ledger');
                        if (retryBtn) {
                            retryBtn.addEventListener('click', loadLedger);
                        }
                    }
                };

                loadLedger();
            },
        });
    }

    /**
     * Open the Visual Spend Analytics Modal dialog.
     * @param {string} token Group invite token
     * @param {string} [currency] Default: 'INR'
     */
    static async openAnalyticsModal(token, currency = 'INR') {
        if (!token) return;

        const skeletonContent = `
            <div id="analytics-modal-content" style="margin-bottom: var(--space-3);">
                <!-- Skeleton KPI Grid -->
                <div class="analytics-kpi-grid">
                    ${[1, 2, 3, 4].map(() => `
                        <div class="analytics-kpi-card">
                            <span class="skeleton-shimmer" style="width: 60px; height: 10px; margin-bottom: 6px;"></span>
                            <span class="skeleton-shimmer" style="width: 90px; height: 18px; margin-bottom: 4px;"></span>
                            <span class="skeleton-shimmer" style="width: 70px; height: 10px;"></span>
                        </div>
                    `).join('')}
                </div>

                <!-- Skeleton Category Distribution -->
                <div class="analytics-section-title">
                    <span>Category Distribution</span>
                    <span class="skeleton-shimmer" style="width: 70px; height: 16px; border-radius: var(--radius-xs);"></span>
                </div>
                <div class="analytics-chart-container">
                    <div class="analytics-donut-wrapper">
                        <div style="flex-shrink: 0; width: 150px; height: 150px; display: flex; align-items: center; justify-content: center;">
                            <span class="skeleton-shimmer" style="width: 120px; height: 120px; border-radius: 50%;"></span>
                        </div>
                        <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: var(--space-3); padding-right: 4px;">
                            ${[1, 2, 3, 4].map(() => `
                                <div>
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                        <span class="skeleton-shimmer" style="width: 80px; height: 12px;"></span>
                                        <span class="skeleton-shimmer" style="width: 50px; height: 12px;"></span>
                                    </div>
                                    <span class="skeleton-shimmer" style="width: 100%; height: 4px;"></span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>

                <!-- Skeleton Daily Burn Velocity -->
                <div class="analytics-section-title">
                    <span>Daily Burn Rate & Velocity</span>
                    <span class="skeleton-shimmer" style="width: 110px; height: 12px;"></span>
                </div>
                <div class="analytics-chart-container" style="display: flex; align-items: flex-end; justify-content: space-around; height: 150px; padding: var(--space-3);">
                    ${[40, 70, 30, 85, 55, 90, 60, 45, 75, 50].map(h => `
                        <div class="skeleton-shimmer" style="width: 6%; height: ${h}%; border-radius: 2px 2px 0 0;"></div>
                    `).join('')}
                </div>

                <!-- Skeleton Capital Outlay -->
                <div class="analytics-section-title">
                    <span>Capital Outlay vs Net Consumption Share</span>
                    <span class="skeleton-shimmer" style="width: 120px; height: 12px;"></span>
                </div>
                <div class="analytics-chart-container" style="padding: var(--space-3) var(--space-4); display: flex; flex-direction: column; gap: var(--space-3);">
                    ${[1, 2, 3].map(() => `
                        <div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span class="skeleton-shimmer" style="width: 90px; height: 12px;"></span>
                                <span class="skeleton-shimmer" style="width: 60px; height: 12px;"></span>
                            </div>
                            <span class="skeleton-shimmer" style="width: 100%; height: 6px;"></span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        Modal.open({
            title: 'Visual Spend Analytics & Intelligence',
            content: skeletonContent,
            size: 'lg',
            confirmText: 'Done',
            confirmClass: 'btn-primary',
            showCancel: false,
            onMount: (modalEl) => {
                const loadAnalytics = async () => {
                    const contentContainer = modalEl.querySelector('#analytics-modal-content');
                    try {
                        const res = await api.getAnalyticsSummary(token);
                        const overlay = document.getElementById('modal-overlay');
                        if (!overlay || !overlay.classList.contains('active') || !modalEl.isConnected || !contentContainer) return;

                        const data = res.data?.analytics || res.data || {};
                        const totalSpend = data.total_spending_cents || 0;
                        const totalTx = data.total_transactions_count || 0;
                        const avgDaily = data.average_daily_spend_cents || 0;
                        const topCategory = data.highest_category || null;
                        const topFunder = data.top_funder || null;
                        const categoryBreakdown = data.categories || data.category_breakdown || [];
                        const dailyTrends = data.daily_trends || [];
                        const memberOutlay = data.member_outlay || [];

                        // Render Analytics Dashboard
                        const donutSvg = BalanceSummary.generateDonutSvg(categoryBreakdown, totalSpend, currency, 150);
                        const histogramSvg = BalanceSummary.generateHistogramSvg(dailyTrends, avgDaily, currency, 480, 150);
                        const outlayHtml = BalanceSummary.generateMemberOutlayHtml(memberOutlay, currency, token);

                        contentContainer.innerHTML = `
                            <!-- Financial Intelligence KPI Grid -->
                            <div class="analytics-kpi-grid">
                                <div class="analytics-kpi-card">
                                    <span class="analytics-kpi-label">Total Spend</span>
                                    <span class="analytics-kpi-value">${Formatters.formatCurrency(totalSpend, currency)}</span>
                                    <span class="analytics-kpi-sub">${totalTx} transaction${totalTx === 1 ? '' : 's'}</span>
                                </div>
                                <div class="analytics-kpi-card">
                                    <span class="analytics-kpi-label">Top Funder</span>
                                    <span class="analytics-kpi-value" style="font-size: var(--font-size-sm);">${topFunder ? Formatters.escapeHtml(topFunder.name) : '—'}</span>
                                    <span class="analytics-kpi-sub">${topFunder ? Formatters.formatCurrency(topFunder.paid_cents, currency) : '₹0.00'} paid</span>
                                </div>
                                <div class="analytics-kpi-card">
                                    <span class="analytics-kpi-label">Burn Velocity</span>
                                    <span class="analytics-kpi-value">${Formatters.formatCurrency(avgDaily, currency)}</span>
                                    <span class="analytics-kpi-sub">per active day</span>
                                </div>
                                <div class="analytics-kpi-card">
                                    <span class="analytics-kpi-label">Top Category</span>
                                    <span class="analytics-kpi-value" style="font-size: var(--font-size-sm);">${topCategory ? Formatters.escapeHtml(topCategory.name) : '—'}</span>
                                    <span class="analytics-kpi-sub">${topCategory ? `${topCategory.percentage}% share` : '—'}</span>
                                </div>
                            </div>

                            <!-- Category Distribution Donut Ring -->
                            <div class="analytics-section-title">
                                <span>Category Distribution</span>
                                <span class="badge badge-settled badge-mono">${categoryBreakdown.length} Categories</span>
                            </div>
                            <div class="analytics-chart-container">
                                <div class="analytics-donut-wrapper">
                                    <div style="flex-shrink: 0; width: 150px; display: flex; justify-content: center;">
                                        ${donutSvg}
                                    </div>
                                    <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: var(--space-2); max-height: 180px; overflow-y: auto; padding-right: 4px;">
                                        ${categoryBreakdown.length === 0 ? `
                                            <div style="color: var(--text-muted); font-size: var(--font-size-xs);">No categories tracked yet.</div>
                                        ` : categoryBreakdown.map(cat => `
                                            <div style="display: flex; align-items: center; justify-content: space-between; font-size: var(--font-size-xs);">
                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 2px; background: ${cat.color};"></span>
                                                    <span style="font-weight: 500;">${Formatters.escapeHtml(cat.name)}</span>
                                                </div>
                                                <div style="text-align: right;">
                                                    <span class="tnum" style="font-weight: 600;">${Formatters.formatCurrency(cat.spent_cents, currency)}</span>
                                                    <span style="color: var(--text-muted); font-size: var(--font-size-2xs); margin-left: 4px;">(${cat.percentage}%)</span>
                                                </div>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            </div>

                            <!-- Daily Burn Velocity Histogram -->
                            <div class="analytics-section-title">
                                <span>Daily Burn Rate & Velocity</span>
                                <span style="font-size: var(--font-size-2xs); color: var(--financial-credit); font-weight: 600;">Avg Velocity Reference</span>
                            </div>
                            <div class="analytics-chart-container">
                                ${histogramSvg}
                            </div>

                            <!-- Member Outlay vs Net Consumption Comparison Matrix -->
                            <div class="analytics-section-title">
                                <span>Capital Outlay vs Net Consumption Share</span>
                                <span style="font-size: var(--font-size-2xs); color: var(--text-muted);">Paid Upfront vs Owed Share</span>
                            </div>
                            <div class="analytics-chart-container" style="padding: var(--space-3) var(--space-4);">
                                ${outlayHtml}
                            </div>
                        `;
                    } catch (err) {
                        const overlay = document.getElementById('modal-overlay');
                        if (!overlay || !overlay.classList.contains('active') || !modalEl.isConnected || !contentContainer) return;
                        contentContainer.innerHTML = `
                            <div style="text-align: center; padding: var(--space-6) var(--space-4); color: var(--financial-debt);">
                                <p style="margin-bottom: var(--space-3); font-size: var(--font-size-sm);">${Formatters.escapeHtml(err.message || 'Failed to load visual analytics.')}</p>
                                <button type="button" class="btn btn-secondary btn-sm btn-retry-analytics">Retry</button>
                            </div>
                        `;
                        const retryBtn = contentContainer.querySelector('.btn-retry-analytics');
                        if (retryBtn) {
                            retryBtn.addEventListener('click', loadAnalytics);
                        }
                    }
                };

                loadAnalytics();
            },
        });
    }

    /**
     * Generate an interactive pure SVG Donut Ring chart with category segments.
     * @param {Array} categories
     * @param {number} totalSpendCents
     * @param {string} currency
     * @param {number} size
     * @returns {string} SVG HTML string
     */
    static generateDonutSvg(categories = [], totalSpendCents = 0, currency = 'INR', size = 150) {
        const radius = 46;
        const strokeWidth = 14;
        const center = size / 2;
        const circumference = 2 * Math.PI * radius;

        if (!categories || categories.length === 0 || totalSpendCents <= 0) {
            return `
                <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" style="display: block; max-width: 100%; height: auto;">
                    <circle cx="${center}" cy="${center}" r="${radius}" fill="none" stroke="var(--border-color)" stroke-width="${strokeWidth}" />
                    <text x="${center}" y="${center + 4}" text-anchor="middle" font-size="10" font-weight="600" fill="var(--text-muted)">₹0.00</text>
                </svg>
            `;
        }

        let cumulativeFraction = 0;
        const slicesSvg = categories.map((cat) => {
            const spent = cat.spent_cents || cat.total_cents || 0;
            if (spent <= 0) return '';

            const fraction = spent / totalSpendCents;
            const strokeDash = fraction * circumference;
            const strokeOffset = -(cumulativeFraction * circumference);
            cumulativeFraction += fraction;

            return `
                <circle cx="${center}" cy="${center}" r="${radius}"
                    fill="none"
                    stroke="${cat.color || '#475569'}"
                    stroke-width="${strokeWidth}"
                    stroke-dasharray="${strokeDash.toFixed(2)} ${(circumference - strokeDash).toFixed(2)}"
                    stroke-dashoffset="${strokeOffset.toFixed(2)}"
                    transform="rotate(-90 ${center} ${center})"
                    class="chart-donut-slice">
                    <title>${Formatters.escapeHtml(cat.name)}: ${Formatters.formatCurrency(spent, currency)} (${cat.percentage || Math.round(fraction * 100)}%)</title>
                </circle>
            `;
        }).join('');

        const formattedTotal = Formatters.formatCurrency(totalSpendCents, currency);
        const totalFontSize = size >= 140 ? 11 : 9;
        const labelFontSize = size >= 140 ? 8 : 7;

        return `
            <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" style="display: block; max-width: 100%; height: auto;">
                <circle cx="${center}" cy="${center}" r="${radius}" fill="none" stroke="var(--surface-secondary)" stroke-width="${strokeWidth}" />
                ${slicesSvg}
                <text x="${center}" y="${center - 5}" text-anchor="middle" font-size="${labelFontSize}" font-weight="700" fill="var(--text-muted)" letter-spacing="0.05em">TOTAL</text>
                <text x="${center}" y="${center + 9}" text-anchor="middle" font-size="${totalFontSize}" font-weight="700" font-family="var(--font-mono)" fill="var(--text-primary)">${formattedTotal}</text>
            </svg>
        `;
    }

    /**
     * Generate a responsive pure SVG Bar Histogram for Daily Burn Rate.
     * @param {Array} dailyTrends
     * @param {number} avgDailySpendCents
     * @param {string} currency
     * @param {number} width
     * @param {number} height
     * @returns {string} SVG HTML string
     */
    static generateHistogramSvg(dailyTrends = [], avgDailySpendCents = 0, currency = 'INR', width = 480, height = 150) {
        if (!dailyTrends || dailyTrends.length === 0) {
            return `
                <div style="text-align: center; color: var(--text-muted); padding: var(--space-4); font-size: var(--font-size-xs);">
                    No daily transaction trends recorded yet.
                </div>
            `;
        }

        const marginLeft = 50;
        const marginRight = 15;
        const marginTop = 18;
        const marginBottom = 24;
        const plotWidth = width - marginLeft - marginRight;
        const plotHeight = height - marginTop - marginBottom;
        const baselineY = height - marginBottom;

        const maxDaily = Math.max(...dailyTrends.map(d => d.spent_cents), avgDailySpendCents, 1000) * 1.15;

        // Grid lines (0%, 50%, 100%)
        const gridLines = [
            { pct: 0, label: '₹0' },
            { pct: 0.5, label: Formatters.formatCurrency(Math.round(maxDaily * 0.5), currency) },
            { pct: 1.0, label: Formatters.formatCurrency(Math.round(maxDaily), currency) },
        ].map(g => {
            const y = baselineY - (g.pct * plotHeight);
            return `
                <line x1="${marginLeft}" y1="${y.toFixed(1)}" x2="${width - marginRight}" y2="${y.toFixed(1)}" stroke="var(--border-subtle)" stroke-width="1" stroke-dasharray="${g.pct > 0 ? '2,2' : '0'}" />
                <text x="${marginLeft - 6}" y="${(y + 3).toFixed(1)}" text-anchor="end" font-size="8" font-family="var(--font-mono)" fill="var(--text-muted)">${g.label}</text>
            `;
        }).join('');

        // Bars
        const numBars = dailyTrends.length;
        const slotWidth = plotWidth / numBars;
        const barWidth = Math.max(8, Math.min(28, slotWidth - 6));

        const barsSvg = dailyTrends.map((d, idx) => {
            const barHeight = Math.max(3, (d.spent_cents / maxDaily) * plotHeight);
            const x = marginLeft + idx * slotWidth + (slotWidth - barWidth) / 2;
            const y = baselineY - barHeight;

            const dateParts = d.date.split('-');
            const shortDate = dateParts.length === 3 ? `${dateParts[1]}/${dateParts[2]}` : d.date;

            return `
                <rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${barWidth.toFixed(1)}" height="${barHeight.toFixed(1)}" rx="2" fill="var(--brand-accent)" class="histogram-bar">
                    <title>${d.date}: ${Formatters.formatCurrency(d.spent_cents, currency)} (${d.count} transaction${d.count === 1 ? '' : 's'})</title>
                </rect>
                <text x="${(x + barWidth / 2).toFixed(1)}" y="${height - 8}" text-anchor="middle" font-size="8" font-family="var(--font-mono)" fill="var(--text-muted)">${shortDate}</text>
            `;
        }).join('');

        // Average reference line
        let avgRefLine = '';
        if (avgDailySpendCents > 0) {
            const avgY = baselineY - (avgDailySpendCents / maxDaily) * plotHeight;
            avgRefLine = `
                <line x1="${marginLeft}" y1="${avgY.toFixed(1)}" x2="${width - marginRight}" y2="${avgY.toFixed(1)}" stroke="var(--financial-credit)" stroke-width="1.5" stroke-dasharray="4,4" />
                <text x="${width - marginRight}" y="${(avgY - 4).toFixed(1)}" text-anchor="end" font-size="8" font-weight="700" fill="var(--financial-credit)">
                    Avg: ${Formatters.formatCurrency(avgDailySpendCents, currency)}/day
                </text>
            `;
        }

        return `
            <svg width="100%" height="${height}" viewBox="0 0 ${width} ${height}" preserveAspectRatio="xMidYMid meet" style="display: block; overflow: visible;">
                ${gridLines}
                ${barsSvg}
                ${avgRefLine}
            </svg>
        `;
    }

    /**
     * Generate HTML for Member Capital Outlay vs Net Consumption comparison.
     * @param {Array} memberOutlay
     * @param {string} currency
     * @param {string} [token]
     * @returns {string} HTML string
     */
    static generateMemberOutlayHtml(memberOutlay = [], currency = 'INR', token = '') {
        if (!memberOutlay || memberOutlay.length === 0) {
            return `<div style="color: var(--text-muted); font-size: var(--font-size-xs);">No member outlays recorded yet.</div>`;
        }

        const maxMemberAmount = Math.max(
            ...memberOutlay.map(m => Math.max(m.paid_cents || 0, m.owed_cents || 0)),
            100
        );

        return memberOutlay.map((m) => {
            const paidPct = Math.round(((m.paid_cents || 0) / maxMemberAmount) * 100);
            const owedPct = Math.round(((m.owed_cents || 0) / maxMemberAmount) * 100);
            const net = m.net_balance_cents || 0;
            const isCreditor = net > 0;
            const isDebtor = net < 0;

            let statusBadge = `<span class="badge badge-settled badge-mono" style="font-size: var(--font-size-2xs);">Settled</span>`;
            if (isCreditor) {
                statusBadge = `<span class="badge badge-credit badge-mono" style="font-size: var(--font-size-2xs);">+${Formatters.formatCurrency(net, currency)}</span>`;
            } else if (isDebtor) {
                statusBadge = `<span class="badge badge-debt badge-mono" style="font-size: var(--font-size-2xs);">-${Formatters.formatCurrency(Math.abs(net), currency)}</span>`;
            }

            return `
                <div class="outlay-row">
                    <div class="outlay-header">
                        <span style="font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 6px;">
                            ${Formatters.renderMemberAvatar(m, token, { size: 18 })}
                            <span>${Formatters.escapeHtml(m.name)}</span>
                        </span>
                        <div>${statusBadge}</div>
                    </div>
                    <!-- Paid Upfront Bar -->
                    <div style="display: flex; align-items: center; gap: 8px; font-size: var(--font-size-2xs); margin-bottom: 2px;">
                        <span style="width: 50px; color: var(--financial-credit); font-weight: 600;">Paid:</span>
                        <div style="flex: 1; height: 5px; background: var(--surface-primary); border-radius: 2px; overflow: hidden;">
                            <div class="outlay-bar-paid" style="width: ${paidPct}%;"></div>
                        </div>
                        <span class="tnum" style="width: 75px; text-align: right; color: var(--text-secondary); font-weight: 600;">${Formatters.formatCurrency(m.paid_cents || 0, currency)}</span>
                    </div>
                    <!-- Share Consumed Bar -->
                    <div style="display: flex; align-items: center; gap: 8px; font-size: var(--font-size-2xs);">
                        <span style="width: 50px; color: var(--financial-warning); font-weight: 600;">Share:</span>
                        <div style="flex: 1; height: 5px; background: var(--surface-primary); border-radius: 2px; overflow: hidden;">
                            <div class="outlay-bar-owed" style="width: ${owedPct}%;"></div>
                        </div>
                        <span class="tnum" style="width: 75px; text-align: right; color: var(--text-secondary); font-weight: 600;">${Formatters.formatCurrency(m.owed_cents || 0, currency)}</span>
                    </div>
                </div>
            `;
        }).join('');
    }
}
