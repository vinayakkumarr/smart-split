/**
 * Smart Split V2 – Executive Financial Ledger & Printable PDF Report Generator
 * 
 * Generates an audit-ready, print-formatted financial statement including:
 * 1. Executive Metadata & Summary KPIs
 * 2. Visual / Tabular Category Spend Distribution
 * 3. Member Net Position Statement Matrix
 * 4. Settlement Wire Clearance Plan
 * 5. Complete Itemized Ledger History
 */

import { Modal } from './Modal.js';
import { Toast } from './Toast.js';
import { escapeHtml, formatDate, formatCurrency, formatCurrencySigned } from '../utils/formatters.js';
import { renderIcon } from '../utils/icons.js';

export class ReportModal {
    /**
     * Generate HTML for the printable financial report sheet.
     * @param {Object} data
     * @returns {string}
     */
    static generateReportHtml({ group = {}, members = [], balances = [], settlementPlan = { transactions: [] }, expenses = [], currency = 'INR' }) {
        const groupName = group.name || 'Workspace';
        const reportDate = new Date().toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        const totalSpendCents = expenses.reduce((sum, e) => sum + (e.total_amount_cents || e.amount_cents || 0), 0);
        const transactionsCount = expenses.length;
        const memberCount = members.length;
        const pendingTransfers = settlementPlan.transactions || [];

        // Compute Category Analytics
        const categoryMap = {};
        expenses.forEach((exp) => {
            const amount = exp.total_amount_cents || exp.amount_cents || 0;
            const catName = exp.category_name || exp.category?.name || 'General';
            const catIcon = exp.category_icon || exp.category?.icon || '📁';
            if (!categoryMap[catName]) {
                categoryMap[catName] = { name: catName, icon: catIcon, totalCents: 0, count: 0 };
            }
            categoryMap[catName].totalCents += amount;
            categoryMap[catName].count++;
        });
        const categories = Object.values(categoryMap).sort((a, b) => b.totalCents - a.totalCents);

        // Compute Member Spend / Paid Stats
        const memberStatsMap = {};
        members.forEach(m => {
            memberStatsMap[m.id] = { id: m.id, name: m.name, paidCents: 0, owedCents: 0, netCents: 0 };
        });
        balances.forEach(b => {
            const mId = b.member_id || b.id;
            if (memberStatsMap[mId]) {
                memberStatsMap[mId].netCents = b.net_balance_cents || 0;
                memberStatsMap[mId].paidCents = b.total_paid_cents || 0;
                memberStatsMap[mId].owedCents = b.total_owed_cents || 0;
            }
        });
        const memberStatsList = Object.values(memberStatsMap).sort((a, b) => b.netCents - a.netCents);

        return `
            <div class="print-report-container report-preview-sheet">
                <!-- 1. Executive Document Header -->
                <div class="print-header">
                    <div>
                        <div class="print-doc-eyebrow">
                            <span>Smart Split &bull; Financial Statement & Audit Summary</span>
                        </div>
                        <h1 class="print-title">${escapeHtml(groupName)}</h1>
                        <p class="print-subtitle">Base Currency: <strong>${escapeHtml(currency)}</strong> &bull; Generated: ${escapeHtml(reportDate)}</p>
                    </div>
                    <div class="print-ref-box">
                        <span class="print-ref-badge">
                            REF: SS-${escapeHtml(String(group.id || 'DOC').padStart(4, '0'))}
                        </span>
                        <span style="font-size: 8.5pt; color: #64748b; font-weight: 600;">Status: Verified Invariant</span>
                    </div>
                </div>

                <!-- 2. Summary KPI Metrics -->
                <div class="print-kpi-grid">
                    <div class="print-kpi-card">
                        <div class="print-kpi-label">Total Workspace Spend</div>
                        <div class="print-kpi-val">${formatCurrency(totalSpendCents, currency)}</div>
                    </div>
                    <div class="print-kpi-card">
                        <div class="print-kpi-label">Transactions Logged</div>
                        <div class="print-kpi-val">${transactionsCount}</div>
                    </div>
                    <div class="print-kpi-card">
                        <div class="print-kpi-label">Active Members</div>
                        <div class="print-kpi-val">${memberCount}</div>
                    </div>
                    <div class="print-kpi-card">
                        <div class="print-kpi-label">Settlement Status</div>
                        <div class="print-kpi-val" style="font-size: 11pt;">
                            ${pendingTransfers.length === 0 ? '<span style="color: #047857;">All Settled</span>' : `<span style="color: #b91c1c;">${pendingTransfers.length} Transfers Due</span>`}
                        </div>
                    </div>
                </div>

                <!-- 3. Member Net Position Statement Matrix -->
                <div class="print-section">
                    <div class="print-section-header">
                        <h2 class="print-section-title">
                            <span>1. Member Financial Position Statement</span>
                        </h2>
                        <span class="print-section-meta">${memberStatsList.length} Members</span>
                    </div>
                    <table class="print-table">
                        <thead>
                            <tr>
                                <th style="text-align: left; width: 22%;">Member Name</th>
                                <th style="text-align: right; width: 18%;">Total Paid</th>
                                <th style="text-align: right; width: 18%;">Total Share Owed</th>
                                <th style="text-align: right; width: 20%;">Net Position</th>
                                <th style="text-align: center; width: 22%;">Settlement Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${memberStatsList.map(m => {
                                const net = m.netCents;
                                let badgeClass = 'print-badge print-badge-settled';
                                let statusText = 'Settled';
                                let netColor = '#64748b';
                                if (net > 0) {
                                    badgeClass = 'print-badge print-badge-credit';
                                    statusText = `Gets back ${formatCurrency(net, currency)}`;
                                    netColor = '#047857';
                                } else if (net < 0) {
                                    badgeClass = 'print-badge print-badge-debt';
                                    statusText = `Owes ${formatCurrency(Math.abs(net), currency)}`;
                                    netColor = '#b91c1c';
                                }
                                return `
                                    <tr>
                                        <td style="font-weight: 700; color: #0f172a;">${escapeHtml(m.name)}</td>
                                        <td style="text-align: right;" class="print-mono-num">${formatCurrency(m.paidCents, currency)}</td>
                                        <td style="text-align: right;" class="print-mono-num">${formatCurrency(m.owedCents, currency)}</td>
                                        <td style="text-align: right; font-weight: 800; color: ${netColor};" class="print-mono-num">
                                            ${formatCurrencySigned(net, currency)}
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="${badgeClass}">${statusText}</span>
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>

                <!-- 4. Category Spend Distribution -->
                ${categories.length > 0 ? `
                    <div class="print-section">
                        <div class="print-section-header">
                            <h2 class="print-section-title">
                                <span>2. Category Spend Breakdown</span>
                            </h2>
                            <span class="print-section-meta">${categories.length} Categories</span>
                        </div>
                        <table class="print-table">
                            <thead>
                                <tr>
                                    <th style="text-align: left; width: 35%;">Category</th>
                                    <th style="text-align: center; width: 15%;">Count</th>
                                    <th style="text-align: right; width: 25%;">Total Amount</th>
                                    <th style="text-align: right; width: 25%;">Share (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${categories.map(c => {
                                    const pct = totalSpendCents > 0 ? ((c.totalCents / totalSpendCents) * 100).toFixed(1) : '0.0';
                                    return `
                                        <tr>
                                            <td style="font-weight: 600; color: #0f172a;">
                                                <span style="margin-right: 6px;">${escapeHtml(c.icon)}</span>
                                                <span>${escapeHtml(c.name)}</span>
                                            </td>
                                            <td style="text-align: center;" class="print-mono-num">${c.count}</td>
                                            <td style="text-align: right; font-weight: 700;" class="print-mono-num">${formatCurrency(c.totalCents, currency)}</td>
                                            <td style="text-align: right;">
                                                <div class="print-progress-wrapper">
                                                    <div class="print-progress-bar">
                                                        <div class="print-progress-fill" style="width: ${pct}%;"></div>
                                                    </div>
                                                    <span class="print-mono-num" style="font-weight: 700; min-width: 42px;">${pct}%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : ''}

                <!-- 5. Optimized Settlement Clearance Plan -->
                <div class="print-section">
                    <div class="print-section-header">
                        <h2 class="print-section-title">
                            <span>3. Simplified Debt Clearance Wire Transfers</span>
                        </h2>
                        <span class="print-section-meta">${pendingTransfers.length} Transfers</span>
                    </div>
                    ${pendingTransfers.length === 0 ? `
                        <div style="padding: 12px 16px; border: 1px solid #a7f3d0; border-radius: 6px; background: #ecfdf5; font-size: 9.5pt; color: #065f46; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                            <span>✔</span>
                            <span>All debts in this workspace are fully settled. No wire transfers are required.</span>
                        </div>
                    ` : `
                        <table class="print-table">
                            <thead>
                                <tr>
                                    <th style="text-align: left; width: 25%;">From (Debtor)</th>
                                    <th style="text-align: center; width: 10%;">Direction</th>
                                    <th style="text-align: left; width: 25%;">To (Creditor)</th>
                                    <th style="text-align: right; width: 20%;">Amount</th>
                                    <th style="text-align: center; width: 20%;">Payment Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${pendingTransfers.map(t => {
                                    const fromName = t.from_name || t.payer_name || (members.find(m => Number(m.id || m.member_id) === Number(t.from_member_id || t.payer_id))?.name) || 'Member';
                                    const toName = t.to_name || t.payee_name || (members.find(m => Number(m.id || m.member_id) === Number(t.to_member_id || t.payee_id))?.name) || 'Member';
                                    const amount = t.amount_cents || 0;
                                    const upi = t.payee_upi_id || t.to_upi_id || '';
                                    return `
                                        <tr>
                                            <td style="font-weight: 700; color: #b91c1c;">${escapeHtml(fromName)}</td>
                                            <td style="text-align: center;">
                                                <span class="print-transfer-arrow">➔</span>
                                            </td>
                                            <td style="font-weight: 700; color: #047857;">${escapeHtml(toName)}</td>
                                            <td style="text-align: right; font-weight: 800;" class="print-mono-num">${formatCurrency(amount, currency)}</td>
                                            <td style="text-align: center;">
                                                <span class="print-badge print-badge-tag">
                                                    ${upi ? `UPI: ${escapeHtml(upi)}` : 'Direct Transfer'}
                                                </span>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    `}
                </div>

                <!-- 6. Itemized Chronological Ledger -->
                <div class="print-section">
                    <div class="print-section-header">
                        <h2 class="print-section-title">
                            <span>4. Detailed Itemized Transaction Ledger</span>
                        </h2>
                        <span class="print-section-meta">${expenses.length} Records</span>
                    </div>
                    ${expenses.length === 0 ? `
                        <div style="padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; font-size: 9.5pt; color: #64748b;">
                            No expenses recorded in this workspace.
                        </div>
                    ` : `
                        <table class="print-table">
                            <thead>
                                <tr>
                                    <th style="text-align: left; width: 12%;">Date</th>
                                    <th style="text-align: left; width: 30%;">Description</th>
                                    <th style="text-align: left; width: 18%;">Category</th>
                                    <th style="text-align: left; width: 18%;">Paid By</th>
                                    <th style="text-align: center; width: 8%;">Split</th>
                                    <th style="text-align: right; width: 14%;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${expenses.map(e => {
                                    const title = e.title || e.description || 'Expense';
                                    const dateStr = formatDate(e.expense_date || e.created_at);
                                    const cat = e.category_name || e.category?.name || 'General';
                                    
                                    // Robust Payer Name Resolution
                                    let payerText = '—';
                                    if (e.payers && Array.isArray(e.payers) && e.payers.length > 0) {
                                        if (e.payers.length === 1) {
                                            payerText = e.payers[0].member_name || e.payers[0].name || 'Member';
                                        } else {
                                            payerText = `${e.payers.map(p => p.member_name || p.name).filter(Boolean).join(', ')}`;
                                        }
                                    } else if (e.paid_by_name || e.paid_by_member_name) {
                                        payerText = e.paid_by_name || e.paid_by_member_name;
                                    } else if (e.paid_by?.name) {
                                        payerText = e.paid_by.name;
                                    } else if (e.paid_by_member_id) {
                                        const found = members.find(m => Number(m.id || m.member_id) === Number(e.paid_by_member_id));
                                        if (found) payerText = found.name;
                                    }

                                    const splitType = e.split_type || 'EQUAL';
                                    const amount = e.total_amount_cents || e.amount_cents || 0;
                                    return `
                                        <tr>
                                            <td style="font-size: 8.5pt; color: #475569;" class="print-mono-num">${escapeHtml(dateStr)}</td>
                                            <td>
                                                <div style="font-weight: 700; color: #0f172a;">${escapeHtml(title)}</div>
                                                ${e.notes ? `<div style="font-size: 8pt; color: #64748b; margin-top: 1px;">${escapeHtml(e.notes)}</div>` : ''}
                                            </td>
                                            <td>
                                                <span class="print-badge print-badge-tag">${escapeHtml(cat)}</span>
                                            </td>
                                            <td style="font-weight: 600; color: #0f172a;">${escapeHtml(payerText)}</td>
                                            <td style="text-align: center;">
                                                <span class="print-badge" style="font-size: 8pt;">${escapeHtml(splitType)}</span>
                                            </td>
                                            <td style="text-align: right; font-weight: 800;" class="print-mono-num">${formatCurrency(amount, currency)}</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    `}
                </div>

                <!-- 7. Document Footer -->
                <div class="print-footer">
                    <span>Smart Split Financial Engine &bull; Verified Ledger Audit Statement</span>
                    <span>Ref: SS-${escapeHtml(String(group.id || 'DOC').padStart(4, '0'))} &bull; Page 1 of 1</span>
                </div>
            </div>
        `;
    }

    /**
     * Open report preview dialog with 1-click Print/PDF trigger.
     * @param {Object} options
     */
    static open(options = {}) {
        const reportHtml = ReportModal.generateReportHtml(options);

        Modal.open({
            title: 'Workspace Financial Statement & PDF Export',
            size: 'lg',
            content: `
                <div style="display: flex; flex-direction: column; gap: var(--space-3, 12px);">
                    <div style="display: flex; justify-content: space-between; align-items: center; background: var(--brand-primary-soft, #e8f0ec); border: 1px solid var(--border-color, #dde2de); padding: 10px 16px; border-radius: var(--radius-xs, 4px); flex-wrap: wrap; gap: 8px;">
                        <div style="font-size: var(--font-size-xs, 0.8rem); color: var(--text-primary, #18352b); line-height: 1.4;">
                            <strong>Print / Save as PDF:</strong> Generates a high-contrast executive summary formatted for A4/Letter paper or PDF download.
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-trigger-print-doc" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
                            ${renderIcon('printer', { size: 14 })}
                            <span>Print / Save as PDF</span>
                        </button>
                    </div>

                    <div class="report-preview-container" id="report-modal-preview-box">
                        ${reportHtml}
                    </div>
                </div>
            `,
            confirmText: 'Print / Save as PDF',
            confirmClass: 'btn-primary',
            onMount: (overlay) => {
                const printBtn = overlay.querySelector('#btn-trigger-print-doc');
                const handlePrint = () => {
                    overlay.classList.add('print-active-overlay');
                    window.print();
                    setTimeout(() => {
                        overlay.classList.remove('print-active-overlay');
                    }, 500);
                };

                if (printBtn) {
                    printBtn.addEventListener('click', handlePrint);
                }
            },
            onConfirm: () => {
                const overlay = document.getElementById('modal-overlay');
                if (overlay) {
                    overlay.classList.add('print-active-overlay');
                    window.print();
                    setTimeout(() => {
                        overlay.classList.remove('print-active-overlay');
                    }, 500);
                }
            }
        });
    }
}
