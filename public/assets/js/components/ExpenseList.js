/**
 * Smart Split V2 – High-Density Financial Expense Ledger Table Component
 * Features: Multi-Dimensional Search, Category Filtering, Receipt Attachments, In-Place Editing, RFC 4180 CSV Export
 */

import { api } from '../api.js';
import { store } from '../state.js';
import * as Formatters from '../utils/formatters.js';
import { Toast } from './Toast.js';
import { Modal } from './Modal.js';
import { ActivityTimeline } from './ActivityTimeline.js';
import { ReceiptLightbox } from './ReceiptLightbox.js';
import { ReportModal } from './ReportModal.js';
import { renderIcon } from '../utils/icons.js';

export class ExpenseList {
    /**
     * Copy text to clipboard with legacy fallback.
     * @param {string} text
     * @param {Object} [options]
     * @returns {Promise<boolean>}
     */
    static async copyTextToClipboard(text, { modalTitle = 'Copy Ledger Data' } = {}) {
        if (!text) return false;

        // Tier 1: Modern Native Clipboard API (Secure Context)
        if (typeof navigator !== 'undefined' && navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);
                return true;
            } catch (err) {
                console.warn('[Clipboard] Tier 1 async writeText failed, attempting Tier 2:', err);
            }
        }

        // Tier 2: Enhanced In-Viewport Ephemeral Sandbox (iOS Safari & HTTP fallback)
        try {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.top = '0';
            textArea.style.left = '0';
            textArea.style.width = '20px';
            textArea.style.height = '20px';
            textArea.style.padding = '0';
            textArea.style.border = 'none';
            textArea.style.outline = 'none';
            textArea.style.boxShadow = 'none';
            textArea.style.background = 'transparent';
            textArea.style.opacity = '0.01';
            textArea.style.zIndex = '999999';
            document.body.appendChild(textArea);

            textArea.focus();
            textArea.select();
            textArea.setSelectionRange(0, 999999);

            const successful = document.execCommand('copy');
            textArea.remove();

            if (successful) return true;
        } catch (err) {
            console.warn('[Clipboard] Tier 2 execCommand failed, attempting Tier 3:', err);
        }

        // Tier 3: Interactive Modal Fallback (If browser sandbox blocks automated writes)
        return new Promise((resolve) => {
            Modal.open({
                title: modalTitle,
                size: 'md',
                showCancel: false,
                confirmText: 'Done',
                content: `
                    <div style="font-size: var(--font-size-xs); color: var(--text-secondary); margin-bottom: var(--space-3);">
                        Your browser restricted automatic clipboard access. The ledger text below is selected—press <kbd class="kbd-badge">Ctrl + C</kbd> (or <kbd class="kbd-badge">Cmd + C</kbd>) to copy:
                    </div>
                    <textarea id="modal-fallback-copy-text" readonly style="width: 100%; height: 160px; font-family: var(--font-mono); font-size: 0.72rem; padding: 8px; border: 1px solid var(--border-color); border-radius: var(--radius-xs); background: var(--surface-secondary); color: var(--text-primary); resize: none;">${Formatters.escapeHtml(text)}</textarea>
                `,
                onMount: (overlay) => {
                    const txt = overlay.querySelector('#modal-fallback-copy-text');
                    if (txt) {
                        txt.focus();
                        txt.select();
                    }
                },
                onConfirm: () => {
                    resolve(true);
                }
            });
        });
    }

    /**
     * Format an array of expense records into Tab-Separated Values (TSV) for Sheets/Excel.
     * Header: Date\tDescription\tCategory\tPaid By\tSplit Detail\tAmount\tNotes
     * Includes formula injection protection and defensive schema resolution.
     * @param {Array} expenses
     * @returns {string} TSV text
     */
    static formatExpensesToTsv(expenses = []) {
        const headers = ['Date', 'Description', 'Category', 'Paid By', 'Split Detail', 'Amount', 'Notes'];
        const cleanCell = (str) => {
            let s = String(str ?? '').replace(/[\t\r\n]+/g, ' ').trim();
            // Formula Injection Guard: neutralize =, +, -, @ prefixes unless it's a standard number
            if (/^[=+\-@]/.test(s) && !/^[+\-]?\d+(\.\d+)?$/.test(s)) {
                s = `'${s}`;
            }
            return s;
        };

        const rows = (expenses || []).map((exp) => {
            const date = cleanCell(exp.expense_date);
            const desc = cleanCell(exp.title || 'Untitled Expense');
            const catName = exp.category?.name || (typeof exp.category === 'string' ? exp.category : 'General');
            const category = cleanCell(catName);

            let paidBy = '';
            if (exp.payers && exp.payers.length > 0) {
                if (exp.payers.length === 1) {
                    const p = exp.payers[0];
                    paidBy = cleanCell(p.member_name || p.name || 'Member');
                } else {
                    paidBy = cleanCell(exp.payers.map(p => {
                        const name = p.member_name || p.name || 'Member';
                        const paid = ((p.amount_paid_cents ?? p.amount_cents ?? 0) / 100).toFixed(2);
                        return `${name} (${paid})`;
                    }).join(', '));
                }
            }

            let splitDetail = '';
            if (exp.splits && exp.splits.length > 0) {
                splitDetail = cleanCell(exp.splits.map(s => {
                    const name = s.member_name || s.name || 'Member';
                    const owed = ((s.amount_owed_cents ?? s.computed_amount_cents ?? 0) / 100).toFixed(2);
                    return `${name} (${owed})`;
                }).join(', '));
            } else {
                splitDetail = cleanCell(exp.split_type || 'EQUAL');
            }

            const totalAmountCents = exp.total_amount_cents ?? exp.amount_cents ?? 0;
            const amount = (totalAmountCents / 100).toFixed(2);

            let notesText = exp.notes || '';
            if (exp.original_currency_code && exp.original_amount_cents && exp.exchange_rate) {
                const origAmt = (exp.original_amount_cents / 100).toFixed(2);
                const fxInfo = `[FX: ${exp.original_currency_code} ${origAmt} @ ${Number(exp.exchange_rate).toFixed(2)}]`;
                notesText = notesText ? `${notesText} ${fxInfo}` : fxInfo;
            }
            const notes = cleanCell(notesText);

            return [date, desc, category, paidBy, splitDetail, amount, notes].join('\t');
        });

        return [headers.join('\t'), ...rows].join('\n');
    }

    /**
     * Open Trash Bin modal to view and restore soft-deleted transactions.
     * @param {Object} options
     * @param {string} options.token
     * @param {string} [options.currency='INR']
     * @param {Function} [options.onRestore] Callback after a transaction is restored
     */
    static async openTrashModal({ token, currency = 'INR', onRestore = null }) {
        let deletedExpenses = [];
        try {
            const res = await api.getTrashExpenses(token);
            deletedExpenses = res?.data?.expenses || res?.expenses || [];
        } catch (err) {
            Toast.error(err.message || 'Failed to load deleted items.');
            return;
        }

        const renderTrashContent = (items) => {
            if (!items || items.length === 0) {
                return `
                    <div style="padding: var(--space-8) var(--space-4); text-align: center;">
                        <div style="margin-bottom: var(--space-2); color: var(--text-subtle); display: flex; justify-content: center;">
                            ${renderIcon('trash2', { size: 32 })}
                        </div>
                        <div style="font-weight: 700; color: var(--text-primary); font-size: var(--font-size-sm); margin-bottom: 4px;">Trash Bin is Empty</div>
                        <div style="color: var(--text-muted); font-size: var(--font-size-xs);">No deleted transactions found in this workspace.</div>
                    </div>
                `;
            }

            return `
                <div style="margin-bottom: var(--space-3); color: var(--text-secondary); font-size: var(--font-size-xs); line-height: 1.4;">
                    Deleted transactions do not affect active group balances. Click <strong>Restore</strong> to reactivate a transaction into the ledger.
                </div>
                <div class="table-container" style="max-height: 380px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    <table class="data-table" style="font-size: var(--font-size-xs);">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Paid By</th>
                                <th style="text-align: right;">Amount</th>
                                <th style="text-align: right; width: 85px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${items.map(exp => {
                                const totalAmt = exp.total_amount_cents || exp.amount_cents || 0;
                                const catName = exp.category?.name || 'General';
                                let payerText = '—';
                                if (exp.payers && exp.payers.length > 0) {
                                    if (exp.payers.length === 1) {
                                        payerText = `
                                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                ${Formatters.renderMemberAvatar(exp.payers[0], token, { size: 18 })}
                                                <span>${Formatters.escapeHtml(exp.payers[0].member_name)}</span>
                                            </div>
                                        `;
                                    } else {
                                        payerText = `
                                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                <div style="display: inline-flex; align-items: center; margin-right: 2px;">
                                                    ${exp.payers.slice(0, 2).map((p, i) => Formatters.renderMemberAvatar(p, token, { size: 16, style: i > 0 ? 'margin-left: -4px;' : '' })).join('')}
                                                </div>
                                                <span>${exp.payers.length} Payers</span>
                                            </div>
                                        `;
                                    }
                                }

                                return `
                                    <tr data-trash-id="${exp.id}">
                                        <td class="table-cell-date tnum">${Formatters.formatDate(exp.expense_date)}</td>
                                        <td>
                                            <div style="font-weight: 600; color: var(--text-primary);">${Formatters.escapeHtml(exp.title)}</div>
                                            ${exp.notes ? `<div style="color: var(--text-muted); font-size: 0.72rem;">${Formatters.escapeHtml(exp.notes)}</div>` : ''}
                                        </td>
                                        <td>
                                            <span class="badge badge-settled" style="font-size: 0.7rem;">${Formatters.escapeHtml(catName)}</span>
                                        </td>
                                        <td><span style="font-weight: 500;">${payerText}</span></td>
                                        <td class="table-cell-amount" style="font-size: var(--font-size-xs);">
                                            ${Formatters.formatCurrency(totalAmt, currency)}
                                        </td>
                                        <td style="text-align: right;">
                                            <button type="button" class="btn btn-secondary btn-sm btn-restore-item" data-expense-id="${exp.id}" style="padding: 2px 8px; font-size: var(--font-size-xs); color: var(--brand-primary); border-color: var(--border-color);" title="Restore this expense">
                                                <span>Restore</span>
                                            </button>
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        };

        Modal.open({
            title: `Trash / Deleted Transactions (${deletedExpenses.length})`,
            content: `<div id="trash-modal-container">${renderTrashContent(deletedExpenses)}</div>`,
            showFooter: true,
            showCancel: false,
            confirmText: 'Close',
            confirmClass: 'btn-secondary',
            size: 'lg',
            onMount: (overlay) => {
                const attachRestoreListeners = () => {
                    const restoreBtns = overlay.querySelectorAll('.btn-restore-item');
                    restoreBtns.forEach(btn => {
                        btn.addEventListener('click', async () => {
                            const expenseId = Number(btn.dataset.expenseId);
                            btn.disabled = true;
                            btn.textContent = 'Restoring...';

                            try {
                                await api.restoreExpense(token, expenseId);
                                Toast.success('Transaction restored successfully!');

                                deletedExpenses = deletedExpenses.filter(e => Number(e.id) !== expenseId);

                                const titleEl = overlay.querySelector('.modal-title');
                                if (titleEl) {
                                    titleEl.textContent = `Trash / Deleted Transactions (${deletedExpenses.length})`;
                                }

                                const containerEl = overlay.querySelector('#trash-modal-container');
                                if (containerEl) {
                                    containerEl.innerHTML = renderTrashContent(deletedExpenses);
                                    attachRestoreListeners();
                                }

                                if (typeof onRestore === 'function') {
                                    onRestore();
                                }
                            } catch (err) {
                                btn.disabled = false;
                                btn.textContent = 'Restore';
                                Toast.error(err.message || 'Failed to restore transaction.');
                            }
                        });
                    });
                };

                attachRestoreListeners();
            }
        });
    }

    /**
     * Render chronological financial expense ledger table with multi-dimensional search, categories, and edit/delete triggers.
     * @param {HTMLElement} container
     * @param {Object} options
     * @param {string} options.token Group invite token
     * @param {Array} options.expenses List of expenses from /expenses API
     * @param {Array} [options.members] List of group members
     * @param {string} [options.currency] Default: 'INR'
     * @param {Function} [options.onAddExpense] Callback when Add Expense button is clicked
     * @param {Function} [options.onEditExpense] Callback when Edit Expense button is clicked
     * @param {Function} [options.onDuplicateExpense] Callback when Duplicate Expense button is clicked
     * @param {Function} [options.onDelete] Callback after an expense is deleted
     */
    static render(container, { token, expenses = [], members = [], currency = 'INR', onAddExpense = null, onEditExpense = null, onDuplicateExpense = null, onDelete = null }) {
        if (!container) return;

        // Internal Filtering State
        const filterState = {
            search: '',
            categoryId: 'all',
            datePreset: 'all',
            fromDate: '',
            toDate: '',
            memberId: 'all',
            memberRole: 'involved', // 'involved' | 'payer' | 'debtor'
            splitType: 'all',
            minAmount: '',
            maxAmount: '',
            drawerOpen: false,
        };

        // Extract available categories and counts
        const standardCategories = [
            { id: 1, slug: 'general', name: 'General', icon: 'General' },
            { id: 2, slug: 'food-dining', name: 'Food & Dining', icon: 'Food' },
            { id: 3, slug: 'travel-transport', name: 'Travel & Transport', icon: 'Travel' },
            { id: 4, slug: 'housing-rent', name: 'Housing & Rent', icon: 'Rent' },
            { id: 5, slug: 'utilities-bills', name: 'Utilities & Bills', icon: 'Bills' },
            { id: 6, slug: 'groceries', name: 'Groceries', icon: 'Groceries' },
            { id: 7, slug: 'entertainment', name: 'Entertainment', icon: 'Entertainment' },
        ];

        // Gather all categories from standard + present in expenses
        const categoryMap = new Map();
        standardCategories.forEach(c => categoryMap.set(c.id, { ...c, count: 0 }));
        expenses.forEach(exp => {
            if (exp.category && exp.category.id) {
                if (!categoryMap.has(exp.category.id)) {
                    categoryMap.set(exp.category.id, {
                        id: exp.category.id,
                        slug: exp.category.slug || '',
                        name: exp.category.name || 'Custom',
                        icon: exp.category.icon || '',
                        count: 0,
                    });
                }
            }
        });

        // Compute counts per category
        expenses.forEach(exp => {
            const catId = exp.category?.id || exp.category_id;
            if (catId && categoryMap.has(catId)) {
                categoryMap.get(catId).count++;
            }
        });

        const categoriesList = Array.from(categoryMap.values());

        function computeFilteredExpenses() {
            const today = new Date();
            const currentYearMonth = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
            
            const prevMonthDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const prevYearMonth = `${prevMonthDate.getFullYear()}-${String(prevMonthDate.getMonth() + 1).padStart(2, '0')}`;

            const thirtyDaysAgo = new Date();
            thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
            const thirtyDaysAgoStr = thirtyDaysAgo.toISOString().split('T')[0];

            return expenses.filter(exp => {
                // 1. Text Search Filter
                if (filterState.search) {
                    const q = filterState.search.toLowerCase().trim();
                    const title = (exp.title || '').toLowerCase();
                    const notes = (exp.notes || '').toLowerCase();
                    const catName = (exp.category?.name || '').toLowerCase();
                    const payers = (exp.payers || []).map(p => (p.member_name || '').toLowerCase()).join(' ');
                    const splits = (exp.splits || []).map(s => (s.member_name || '').toLowerCase()).join(' ');
                    const items = (exp.items || []).map(i => (i.name || '').toLowerCase()).join(' ');
                    const amountStr = ((exp.total_amount_cents || exp.amount_cents || 0) / 100).toFixed(2);
                    const rawAmt = String(exp.total_amount_cents || exp.amount_cents || 0);

                    const origCur = (exp.original_currency_code || '').toLowerCase();
                    const origAmtStr = exp.original_amount_cents ? ((exp.original_amount_cents) / 100).toFixed(2) : '';

                    const matchesText = title.includes(q) ||
                        notes.includes(q) ||
                        catName.includes(q) ||
                        payers.includes(q) ||
                        splits.includes(q) ||
                        items.includes(q) ||
                        amountStr.includes(q) ||
                        rawAmt.includes(q) ||
                        origCur.includes(q) ||
                        origAmtStr.includes(q);

                    if (!matchesText) return false;
                }

                // 2. Category Filter
                if (filterState.categoryId !== 'all') {
                    const cId = Number(filterState.categoryId);
                    const expCatId = exp.category?.id || exp.category_id;
                    if (expCatId !== cId) return false;
                }

                // 3. Date Preset Filter
                const expDate = exp.expense_date || '';
                if (filterState.datePreset === 'this_month') {
                    if (!expDate.startsWith(currentYearMonth)) return false;
                } else if (filterState.datePreset === 'last_month') {
                    if (!expDate.startsWith(prevYearMonth)) return false;
                } else if (filterState.datePreset === 'last_30_days') {
                    if (expDate < thirtyDaysAgoStr) return false;
                } else if (filterState.datePreset === 'custom') {
                    if (filterState.fromDate && expDate < filterState.fromDate) return false;
                    if (filterState.toDate && expDate > filterState.toDate) return false;
                }

                // 4. Member Role Filter
                if (filterState.memberId !== 'all') {
                    const mId = Number(filterState.memberId);
                    const isPayer = (exp.payers || []).some(p => Number(p.member_id) === mId);
                    const isDebtor = (exp.splits || []).some(s => Number(s.member_id) === mId);

                    if (filterState.memberRole === 'payer' && !isPayer) return false;
                    if (filterState.memberRole === 'debtor' && !isDebtor) return false;
                    if (filterState.memberRole === 'involved' && !isPayer && !isDebtor) return false;
                }

                // 5. Split Type Filter
                if (filterState.splitType !== 'all') {
                    if (exp.split_type !== filterState.splitType) return false;
                }

                // 6. Min/Max Amount Filter
                const totalAmt = exp.total_amount_cents || exp.amount_cents || 0;
                if (filterState.minAmount !== '') {
                    const minCents = Math.round(parseFloat(filterState.minAmount) * 100);
                    if (!isNaN(minCents) && totalAmt < minCents) return false;
                }
                if (filterState.maxAmount !== '') {
                    const maxCents = Math.round(parseFloat(filterState.maxAmount) * 100);
                    if (!isNaN(maxCents) && totalAmt > maxCents) return false;
                }

                return true;
            });
        }

        function renderLedger() {
            const existingTableContainer = container.querySelector('.table-container');
            const prevScrollLeft = existingTableContainer ? existingTableContainer.scrollLeft : 0;

            const filteredExpenses = computeFilteredExpenses();
            const totalFilteredSpendCents = filteredExpenses.reduce((sum, exp) => sum + (exp.total_amount_cents || exp.amount_cents || 0), 0);
            const isFilterActive = filterState.search || filterState.categoryId !== 'all' || filterState.datePreset !== 'all' || filterState.memberId !== 'all' || filterState.splitType !== 'all' || filterState.minAmount !== '' || filterState.maxAmount !== '';

            const splitTypeBadges = {
                'EQUAL': '=',
                'PERCENTAGE': '%',
                'SHARES': 'x',
                'EXACT': '₹',
                'ITEMIZED': 'items',
                'ADJUSTMENT': '+/-'
            };

            container.innerHTML = `
                <div class="panel">
                    <!-- Panel Header -->
                    <div class="panel-header">
                        <div class="panel-title-text">
                            <span>Transaction Ledger</span>
                            <span class="badge badge-settled badge-mono">${filteredExpenses.length}${isFilterActive ? ` / ${expenses.length}` : ''}</span>
                        </div>
                        <div class="panel-header-actions" style="display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-view-activity-feed" title="View workspace audit timeline">
                                ${renderIcon('clock', { size: 13 })}
                                <span>Activity</span>
                            </button>
                            <div class="dropdown-container" id="ledger-actions-dropdown-wrapper">
                                <button type="button" class="btn btn-secondary btn-sm" id="btn-ledger-more-toggle" aria-haspopup="true" aria-expanded="false" title="Export and ledger tools">
                                    ${renderIcon('moreHorizontal', { size: 14 })}
                                    <span>Actions</span>
                                    ${renderIcon('chevronDown', { size: 10 })}
                                </button>
                                <div class="dropdown-menu" id="ledger-actions-dropdown-menu">
                                    <button type="button" class="dropdown-item" id="btn-copy-sheets" title="Copy Ledger for Google Sheets / Excel (TSV)">
                                        ${renderIcon('copy', { size: 13 })}
                                        <span>Copy for Sheets</span>
                                    </button>
                                    <button type="button" class="dropdown-item" id="btn-open-sheets" title="Launch Google Sheets and copy ledger">
                                        ${renderIcon('externalLink', { size: 13 })}
                                        <span>Open in Google Sheets</span>
                                    </button>
                                    <a href="/api/groups/${encodeURIComponent(token)}/export.csv" class="dropdown-item" id="btn-export-csv" title="Export Ledger to RFC 4180 CSV">
                                        ${renderIcon('fileText', { size: 13 })}
                                        <span>Export to CSV</span>
                                    </a>
                                    <button type="button" class="dropdown-item" id="btn-export-pdf-report" title="Generate Print-Ready PDF & Financial Statement">
                                        ${renderIcon('printer', { size: 13 })}
                                        <span>Print / PDF Report</span>
                                    </button>
                                    <div class="dropdown-divider"></div>
                                    <button type="button" class="dropdown-item" id="btn-view-trash-bin" title="View deleted transactions & restore">
                                        ${renderIcon('trash2', { size: 13 })}
                                        <span>Trash Bin & Restore</span>
                                    </button>
                                </div>
                            </div>
                            ${onAddExpense ? `
                                <button type="button" class="btn btn-primary btn-sm" id="btn-panel-add-expense" style="display: none;">
                                    ${renderIcon('plus', { size: 13 })}
                                    <span>Log Expense</span>
                                </button>
                            ` : ''}
                        </div>
                    </div>

                    <!-- Multi-Dimensional Search & Filtering Toolbar -->
                    <div class="filter-toolbar">
                        <div class="filter-toolbar-row">
                            <!-- Instant Search Bar -->
                            <div class="search-wrapper">
                                <span class="search-icon-prefix" style="display: inline-flex; align-items: center;">${renderIcon('search', { size: 13 })}</span>
                                <input 
                                    type="text" 
                                    id="ledger-search-input" 
                                    class="form-input" 
                                    placeholder="Search transactions, payers, participants, or notes..." 
                                    value="${Formatters.escapeHtml(filterState.search)}"
                                >
                                ${filterState.search ? `<button type="button" class="search-clear-btn" id="btn-clear-search">&times;</button>` : ''}
                            </div>

                            <!-- Advanced Filter Drawer Toggle -->
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-toggle-filters" style="display: inline-flex; align-items: center; gap: 4px;">
                                <span>Filter</span>
                                <span>${filterState.drawerOpen ? renderIcon('chevronUp', { size: 11 }) : renderIcon('chevronDown', { size: 11 })}</span>
                            </button>
                        </div>

                        <!-- Category Chips Filter Row -->
                        <div class="category-chips-row">
                            <button type="button" class="category-chip ${filterState.categoryId === 'all' ? 'is-active' : ''}" data-category-id="all">
                                <span>All Categories</span>
                                <span class="badge badge-settled badge-mono">${expenses.length}</span>
                            </button>
                            ${categoriesList.filter(c => c.count > 0 || c.id <= 7).map(c => `
                                <button type="button" class="category-chip ${String(filterState.categoryId) === String(c.id) ? 'is-active' : ''}" data-category-id="${c.id}">
                                    <span>${Formatters.escapeHtml(c.name)}</span>
                                    <span class="badge badge-settled badge-mono">${c.count}</span>
                                </button>
                            `).join('')}
                        </div>

                        <!-- Expandable Advanced Filter Drawer -->
                        ${filterState.drawerOpen ? `
                            <div class="filter-drawer">
                                <div class="filter-drawer-grid">
                                    <!-- Date Preset -->
                                    <div class="filter-field">
                                        <label class="filter-label">Date Range</label>
                                        <select id="filter-date-preset" class="form-select" style="min-height: 30px; font-size: var(--font-size-xs);">
                                            <option value="all" ${filterState.datePreset === 'all' ? 'selected' : ''}>All Time</option>
                                            <option value="this_month" ${filterState.datePreset === 'this_month' ? 'selected' : ''}>This Month</option>
                                            <option value="last_month" ${filterState.datePreset === 'last_month' ? 'selected' : ''}>Last Month</option>
                                            <option value="last_30_days" ${filterState.datePreset === 'last_30_days' ? 'selected' : ''}>Last 30 Days</option>
                                            <option value="custom" ${filterState.datePreset === 'custom' ? 'selected' : ''}>Custom Range...</option>
                                        </select>
                                    </div>

                                    <!-- Member Selector -->
                                    <div class="filter-field">
                                        <label class="filter-label">Member Filter</label>
                                        <select id="filter-member-id" class="form-select" style="min-height: 30px; font-size: var(--font-size-xs);">
                                            <option value="all" ${filterState.memberId === 'all' ? 'selected' : ''}>All Members</option>
                                            ${members.map(m => `<option value="${m.id}" ${String(filterState.memberId) === String(m.id) ? 'selected' : ''}>${Formatters.escapeHtml(m.name)}</option>`).join('')}
                                        </select>
                                    </div>

                                    <!-- Member Role -->
                                    <div class="filter-field">
                                        <label class="filter-label">Member Role</label>
                                        <select id="filter-member-role" class="form-select" style="min-height: 30px; font-size: var(--font-size-xs);">
                                            <option value="involved" ${filterState.memberRole === 'involved' ? 'selected' : ''}>Paid or Split</option>
                                            <option value="payer" ${filterState.memberRole === 'payer' ? 'selected' : ''}>Paid Upfront</option>
                                            <option value="debtor" ${filterState.memberRole === 'debtor' ? 'selected' : ''}>Split Share</option>
                                        </select>
                                    </div>

                                    <!-- Split Method -->
                                    <div class="filter-field">
                                        <label class="filter-label">Split Type</label>
                                        <select id="filter-split-type" class="form-select" style="min-height: 30px; font-size: var(--font-size-xs);">
                                            <option value="all" ${filterState.splitType === 'all' ? 'selected' : ''}>All Methods</option>
                                            <option value="EQUAL" ${filterState.splitType === 'EQUAL' ? 'selected' : ''}>Equal (=)</option>
                                            <option value="EXACT" ${filterState.splitType === 'EXACT' ? 'selected' : ''}>Exact (₹)</option>
                                            <option value="PERCENTAGE" ${filterState.splitType === 'PERCENTAGE' ? 'selected' : ''}>Percentage (%)</option>
                                            <option value="SHARES" ${filterState.splitType === 'SHARES' ? 'selected' : ''}>Shares (x)</option>
                                            <option value="ADJUSTMENT" ${filterState.splitType === 'ADJUSTMENT' ? 'selected' : ''}>Adjustment (+/-)</option>
                                            <option value="ITEMIZED" ${filterState.splitType === 'ITEMIZED' ? 'selected' : ''}>Itemized (Line-Items)</option>
                                        </select>
                                    </div>

                                    <!-- Min/Max Amount Bounds -->
                                    <div class="filter-field">
                                        <label class="filter-label">Amount Range (₹)</label>
                                        <div style="display: flex; gap: 4px;">
                                            <input type="number" id="filter-min-amount" class="form-input" placeholder="Min" value="${filterState.minAmount}" style="min-height: 30px; font-size: var(--font-size-xs); width: 50%;">
                                            <input type="number" id="filter-max-amount" class="form-input" placeholder="Max" value="${filterState.maxAmount}" style="min-height: 30px; font-size: var(--font-size-xs); width: 50%;">
                                        </div>
                                    </div>
                                </div>

                                ${filterState.datePreset === 'custom' ? `
                                    <div style="display: flex; gap: var(--space-3); align-items: center; padding-top: var(--space-2); border-top: 1px solid var(--border-color);">
                                        <div class="filter-field" style="flex: 1;">
                                             <label class="filter-label">From Date</label>
                                            <input type="date" id="filter-from-date" class="form-input" value="${filterState.fromDate}" style="min-height: 30px; font-size: var(--font-size-xs);">
                                        </div>
                                        <div class="filter-field" style="flex: 1;">
                                            <label class="filter-label">To Date</label>
                                            <input type="date" id="filter-to-date" class="form-input" value="${filterState.toDate}" style="min-height: 30px; font-size: var(--font-size-xs);">
                                        </div>
                                    </div>
                                ` : ''}

                                <div style="display: flex; justify-content: flex-end; gap: var(--space-2); padding-top: var(--space-2);">
                                    <button type="button" class="btn btn-ghost btn-sm" id="btn-reset-filters">Reset All Filters</button>
                                </div>
                            </div>
                        ` : ''}

                        <!-- Active Filter Summary Bar -->
                        ${isFilterActive ? `
                            <div class="active-filters-bar">
                                <span style="font-weight: 700; color: var(--text-muted); font-size: var(--font-size-2xs); text-transform: uppercase;">Active Filters:</span>
                                ${filterState.search ? `
                                    <span class="filter-tag">
                                        Search: "${Formatters.escapeHtml(filterState.search)}"
                                        <span class="filter-tag-remove" data-clear="search">&times;</span>
                                    </span>
                                ` : ''}
                                ${filterState.categoryId !== 'all' ? `
                                    <span class="filter-tag">
                                        Category: ${Formatters.escapeHtml(categoryMap.get(Number(filterState.categoryId))?.name || 'Category')}
                                        <span class="filter-tag-remove" data-clear="categoryId">&times;</span>
                                    </span>
                                ` : ''}
                                ${filterState.datePreset !== 'all' ? `
                                    <span class="filter-tag">
                                        Date: ${filterState.datePreset}
                                        <span class="filter-tag-remove" data-clear="datePreset">&times;</span>
                                    </span>
                                ` : ''}
                                ${filterState.memberId !== 'all' ? `
                                    <span class="filter-tag">
                                        Member: ${Formatters.escapeHtml(members.find(m => String(m.id) === String(filterState.memberId))?.name || 'Member')}
                                        <span class="filter-tag-remove" data-clear="memberId">&times;</span>
                                    </span>
                                ` : ''}
                                ${filterState.splitType !== 'all' ? `
                                    <span class="filter-tag">
                                        Split: ${filterState.splitType}
                                        <span class="filter-tag-remove" data-clear="splitType">&times;</span>
                                    </span>
                                ` : ''}
                                ${filterState.minAmount || filterState.maxAmount ? `
                                    <span class="filter-tag">
                                        Amount: ₹${filterState.minAmount || '0'} - ₹${filterState.maxAmount || '∞'}
                                        <span class="filter-tag-remove" data-clear="amount">&times;</span>
                                    </span>
                                ` : ''}
                                <span style="margin-left: auto; color: var(--text-muted); font-family: var(--font-mono); font-size: var(--font-size-2xs);">
                                    Filtered Total: <strong>${Formatters.formatCurrency(totalFilteredSpendCents, currency)}</strong>
                                </span>
                            </div>
                        ` : ''}
                    </div>

                    <!-- Ledger Table / Empty State -->
                    <div class="panel-body" style="padding: 0;">
                        ${filteredExpenses.length === 0 ? `
                            <div class="empty-state">
                                <div class="empty-state-title">No transactions found</div>
                                <div class="empty-state-text">
                                    ${isFilterActive 
                                        ? 'No transactions matched your active filter criteria. Try resetting filters.' 
                                        : 'Log your first group expense to initialize the shared balance ledger.'}
                                </div>
                                ${isFilterActive ? `
                                    <button type="button" class="btn btn-secondary btn-sm" id="btn-empty-reset-filters" style="margin-top: var(--space-3);">
                                        Reset Filters
                                    </button>
                                ` : (onAddExpense ? `
                                    <button type="button" class="btn btn-primary btn-sm" id="btn-empty-add-expense" style="margin-top: var(--space-3);">
                                        + Log First Expense
                                    </button>
                                ` : '')}
                            </div>
                        ` : `
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Category</th>
                                            <th>Paid By</th>
                                            <th>Split Detail</th>
                                            <th style="text-align: right;">Amount</th>
                                            <th style="text-align: right; width: 85px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${filteredExpenses.map((exp) => {
                                            const totalAmount = exp.total_amount_cents || exp.amount_cents || 0;
                                            const catName = exp.category?.name || 'General';
                                            const isForeign = exp.original_currency_code && exp.original_currency_code !== currency;
                                            const hasReceipts = exp.receipt_count && exp.receipt_count > 0;

                                            // Payers summary
                                            let payerText = '—';
                                            if (exp.payers && exp.payers.length > 0) {
                                                if (exp.payers.length === 1) {
                                                    payerText = `
                                                        <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                            ${Formatters.renderMemberAvatar(exp.payers[0], token, { size: 18 })}
                                                            <span>${Formatters.escapeHtml(exp.payers[0].member_name)}</span>
                                                        </div>
                                                    `;
                                                } else {
                                                    payerText = `
                                                        <div style="display: inline-flex; align-items: center; gap: 6px;" title="${exp.payers.map(p => `${p.member_name}: ${Formatters.formatCurrency(p.amount_paid_cents, currency)}`).join(', ')}">
                                                            <div style="display: inline-flex; align-items: center; margin-right: 2px;">
                                                                ${exp.payers.slice(0, 2).map((p, i) => Formatters.renderMemberAvatar(p, token, { size: 16, style: i > 0 ? 'margin-left: -4px;' : '' })).join('')}
                                                            </div>
                                                            <span>${exp.payers.length} Payers</span>
                                                        </div>
                                                    `;
                                                }
                                            }

                                            // Splits count summary with concise indicator pill
                                            const splitCount = (exp.splits || []).length;
                                            const splitBadge = splitTypeBadges[exp.split_type] || exp.split_type || '=';
                                            const splitSummary = `${splitCount} ${splitCount === 1 ? 'member' : 'members'} <span class="split-mode-pill" title="Split Type: ${exp.split_type || 'EQUAL'}">${splitBadge}</span>`;

                                            return `
                                                <tr data-expense-id="${exp.id}">
                                                    <td class="table-cell-date tnum">${Formatters.formatDate(exp.expense_date)}</td>
                                                    <td>
                                                        <div class="table-cell-title">${Formatters.renderWithHashtags(exp.title)}</div>
                                                        ${exp.notes ? `<div class="table-cell-meta">${Formatters.renderWithHashtags(exp.notes)}</div>` : ''}
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-settled" style="font-size: var(--font-size-2xs);">
                                                            ${Formatters.escapeHtml(catName)}
                                                        </span>
                                                    </td>
                                                    <td><span style="font-weight: 500;">${payerText}</span></td>
                                                    <td><span style="color: var(--text-muted); font-size: var(--font-size-xs);">${splitSummary}</span></td>
                                                    <td class="table-cell-amount">
                                                        <div>${Formatters.formatCurrency(totalAmount, currency)}</div>
                                                        ${isForeign ? `
                                                            <div style="font-size: var(--font-size-2xs); color: var(--brand-accent); font-family: var(--font-mono);">
                                                                ${exp.original_currency_code} ${Formatters.formatCurrency(exp.original_amount_cents || totalAmount, exp.original_currency_code)}
                                                            </div>
                                                        ` : ''}
                                                    </td>
                                                    <td class="table-cell-actions">
                                                        <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 3px;">
                                                            ${hasReceipts ? `
                                                                <button type="button" class="btn-icon-action btn-view-receipts" data-expense-id="${exp.id}" title="View Attached Receipts (${exp.receipt_count})" aria-label="View Receipts">
                                                                    ${renderIcon('fileText', { size: 14 })}
                                                                </button>
                                                            ` : ''}
                                                            ${onEditExpense ? `
                                                                <button type="button" class="btn-icon-action btn-edit-expense" data-expense-id="${exp.id}" title="Edit Expense" aria-label="Edit Expense">
                                                                    ${renderIcon('edit2', { size: 14 })}
                                                                </button>
                                                            ` : ''}
                                                            ${onDuplicateExpense ? `
                                                                <button type="button" class="btn-icon-action btn-duplicate-expense" data-expense-id="${exp.id}" title="Duplicate Expense" aria-label="Duplicate Expense">
                                                                    ${renderIcon('copy', { size: 14 })}
                                                                </button>
                                                            ` : ''}
                                                            ${onDelete ? `
                                                                <button type="button" class="btn-icon-action btn-delete-action btn-delete-expense" data-expense-id="${exp.id}" title="Delete Expense" aria-label="Delete Expense">
                                                                    ${renderIcon('trash2', { size: 14 })}
                                                                </button>
                                                            ` : ''}
                                                        </div>
                                                    </td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `}
                    </div>
                </div>
            `;

            attachEventListeners(filteredExpenses, isFilterActive);

            // Restore scroll position if previously scrolled
            if (prevScrollLeft > 0) {
                const newTableContainer = container.querySelector('.table-container');
                if (newTableContainer) {
                    newTableContainer.scrollLeft = prevScrollLeft;
                }
            }
        }

        function attachEventListeners(filteredExpenses = [], isFilterActive = false) {
            // Add Expense button
            const addBtn = container.querySelector('#btn-panel-add-expense');
            if (addBtn && typeof onAddExpense === 'function') addBtn.addEventListener('click', onAddExpense);

            const emptyAddBtn = container.querySelector('#btn-empty-add-expense');
            if (emptyAddBtn && typeof onAddExpense === 'function') emptyAddBtn.addEventListener('click', onAddExpense);

            // Ledger Actions Dropdown Toggle Handler
            const moreToggleBtn = container.querySelector('#btn-ledger-more-toggle');
            const moreMenu = container.querySelector('#ledger-actions-dropdown-menu');
            if (moreToggleBtn && moreMenu) {
                moreToggleBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = moreMenu.classList.toggle('is-active');
                    moreToggleBtn.setAttribute('aria-expanded', String(isOpen));
                });
                document.addEventListener('click', (e) => {
                    if (!e.target.closest('#ledger-actions-dropdown-wrapper')) {
                        moreMenu.classList.remove('is-active');
                        moreToggleBtn.setAttribute('aria-expanded', 'false');
                    }
                });
            }

            // Activity feed button
            const activityBtn = container.querySelector('#btn-view-activity-feed');
            if (activityBtn) activityBtn.addEventListener('click', () => ActivityTimeline.open(token, currency));

            // Trash bin button
            const trashBtn = container.querySelector('#btn-view-trash-bin');
            if (trashBtn) {
                trashBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (moreMenu) moreMenu.classList.remove('is-active');
                    ExpenseList.openTrashModal({
                        token,
                        currency,
                        onRestore: onDelete,
                    });
                });
            }

            // Copy for Sheets / Excel TSV button
            const copySheetsBtn = container.querySelector('#btn-copy-sheets');
            if (copySheetsBtn) {
                copySheetsBtn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    if (moreMenu) moreMenu.classList.remove('is-active');

                    const currentFiltered = computeFilteredExpenses();
                    const isCurrentFilterActive = Boolean(
                        filterState.search ||
                        filterState.categoryId !== 'all' ||
                        filterState.datePreset !== 'all' ||
                        filterState.memberId !== 'all' ||
                        filterState.splitType !== 'all' ||
                        filterState.minAmount !== '' ||
                        filterState.maxAmount !== ''
                    );

                    let targetList = currentFiltered;
                    let isFallbackAll = false;

                    if (targetList.length === 0) {
                        if (expenses.length > 0) {
                            targetList = expenses;
                            isFallbackAll = true;
                        } else {
                            Toast.info('No transactions to copy.');
                            return;
                        }
                    }

                    const tsvContent = ExpenseList.formatExpensesToTsv(targetList);

                    try {
                        const success = await ExpenseList.copyTextToClipboard(tsvContent, { modalTitle: 'Copy Ledger for Sheets / Excel' });
                        if (success) {
                            // Reactive UI micro-feedback
                            const origHtml = copySheetsBtn.innerHTML;
                            copySheetsBtn.innerHTML = `
                                <span style="color: var(--financial-credit); display: inline-flex; align-items: center;">${renderIcon('check', { size: 13 })}</span>
                                <span style="color: var(--financial-credit); font-weight: 600;">Copied!</span>
                            `;
                            setTimeout(() => {
                                if (copySheetsBtn) copySheetsBtn.innerHTML = origHtml;
                            }, 1800);

                            if (isFallbackAll) {
                                Toast.info(`No matches for current filter. Copied all ${expenses.length} workspace transactions (TSV).`);
                            } else if (isCurrentFilterActive) {
                                Toast.success(`Copied ${targetList.length} filtered transactions (of ${expenses.length} total) for Sheets / Excel.`);
                            } else {
                                Toast.success('Ledger copied! Paste directly into Google Sheets or Excel (Ctrl+V).');
                            }
                        } else {
                            Toast.error('Failed to copy ledger to clipboard.');
                        }
                    } catch (err) {
                        console.error('[ExpenseList] Copy error:', err);
                        Toast.error('Failed to copy ledger to clipboard.');
                    }
                });
            }

            // Open in Google Sheets direct action
            const openSheetsBtn = container.querySelector('#btn-open-sheets');
            if (openSheetsBtn) {
                openSheetsBtn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    if (moreMenu) moreMenu.classList.remove('is-active');

                    const currentFiltered = computeFilteredExpenses();
                    let targetList = currentFiltered.length > 0 ? currentFiltered : expenses;
                    if (targetList.length === 0) {
                        Toast.info('No transactions to export to Google Sheets.');
                        return;
                    }

                    const tsvContent = ExpenseList.formatExpensesToTsv(targetList);
                    await ExpenseList.copyTextToClipboard(tsvContent, { modalTitle: 'Google Sheets Export' });

                    const newTab = window.open('https://sheets.new', '_blank');
                    if (newTab) {
                        Toast.success('Opening Google Sheets! Press Ctrl+V (or Cmd+V) to paste your ledger.');
                    } else {
                        Toast.success('Ledger copied to clipboard! Open Google Sheets and paste (Ctrl+V).');
                    }
                });
            }

            // Print / PDF Report Action
            const printReportBtn = container.querySelector('#btn-export-pdf-report');
            if (printReportBtn) {
                printReportBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    if (moreMenu) moreMenu.classList.remove('is-active');

                    const state = (typeof store !== 'undefined' && store.getState) ? store.getState() : {};
                    ReportModal.open({
                        group: state.currentGroup || { name: 'Workspace', invite_token: token, currency_code: currency },
                        members: state.members || members || [],
                        balances: state.balances || [],
                        settlementPlan: state.settlementPlan || { transactions: [] },
                        expenses: expenses || [],
                        currency: currency || 'INR',
                    });
                });
            }

            // Instant Search Input
            const searchInput = container.querySelector('#ledger-search-input');
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    filterState.search = e.target.value;
                    renderLedger();
                    // Restore focus
                    const newSearch = container.querySelector('#ledger-search-input');
                    if (newSearch) {
                        newSearch.focus({ preventScroll: true });
                        newSearch.setSelectionRange(newSearch.value.length, newSearch.value.length);
                    }
                });
            }

            const clearSearchBtn = container.querySelector('#btn-clear-search');
            if (clearSearchBtn) {
                clearSearchBtn.addEventListener('click', () => {
                    filterState.search = '';
                    renderLedger();
                });
            }

            // Hashtag Badges Click Trigger
            const tagBadges = container.querySelectorAll('.badge-tag');
            tagBadges.forEach(badge => {
                badge.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const tag = badge.dataset.tag || badge.textContent.trim().replace(/^#/, '');
                    filterState.search = `#${tag}`;
                    renderLedger();
                    const searchInputEl = container.querySelector('#ledger-search-input');
                    if (searchInputEl) {
                        searchInputEl.value = `#${tag}`;
                        searchInputEl.focus({ preventScroll: true });
                    }
                });
            });

            // Category Chips Click
            const catChips = container.querySelectorAll('.category-chip');
            catChips.forEach(chip => {
                chip.addEventListener('click', () => {
                    filterState.categoryId = chip.dataset.categoryId;
                    renderLedger();
                });
            });

            // Filter Drawer Toggle
            const toggleFiltersBtn = container.querySelector('#btn-toggle-filters');
            if (toggleFiltersBtn) {
                toggleFiltersBtn.addEventListener('click', () => {
                    filterState.drawerOpen = !filterState.drawerOpen;
                    renderLedger();
                });
            }

            // Filter Drawer Controls
            const datePresetSel = container.querySelector('#filter-date-preset');
            if (datePresetSel) {
                datePresetSel.addEventListener('change', (e) => {
                    filterState.datePreset = e.target.value;
                    renderLedger();
                });
            }

            const fromDateInput = container.querySelector('#filter-from-date');
            if (fromDateInput) {
                fromDateInput.addEventListener('change', (e) => {
                    filterState.fromDate = e.target.value;
                    renderLedger();
                });
            }

            const toDateInput = container.querySelector('#filter-to-date');
            if (toDateInput) {
                toDateInput.addEventListener('change', (e) => {
                    filterState.toDate = e.target.value;
                    renderLedger();
                });
            }

            const memberSel = container.querySelector('#filter-member-id');
            if (memberSel) {
                memberSel.addEventListener('change', (e) => {
                    filterState.memberId = e.target.value;
                    renderLedger();
                });
            }

            const roleSel = container.querySelector('#filter-member-role');
            if (roleSel) {
                roleSel.addEventListener('change', (e) => {
                    filterState.memberRole = e.target.value;
                    renderLedger();
                });
            }

            const splitSel = container.querySelector('#filter-split-type');
            if (splitSel) {
                splitSel.addEventListener('change', (e) => {
                    filterState.splitType = e.target.value;
                    renderLedger();
                });
            }

            const minAmtInput = container.querySelector('#filter-min-amount');
            if (minAmtInput) {
                minAmtInput.addEventListener('input', (e) => {
                    filterState.minAmount = e.target.value;
                    renderLedger();
                });
            }

            const maxAmtInput = container.querySelector('#filter-max-amount');
            if (maxAmtInput) {
                maxAmtInput.addEventListener('input', (e) => {
                    filterState.maxAmount = e.target.value;
                    renderLedger();
                });
            }

            const resetBtn = container.querySelector('#btn-reset-filters');
            if (resetBtn) {
                resetBtn.addEventListener('click', () => {
                    filterState.search = '';
                    filterState.categoryId = 'all';
                    filterState.datePreset = 'all';
                    filterState.fromDate = '';
                    filterState.toDate = '';
                    filterState.memberId = 'all';
                    filterState.memberRole = 'involved';
                    filterState.splitType = 'all';
                    filterState.minAmount = '';
                    filterState.maxAmount = '';
                    renderLedger();
                });
            }

            const emptyResetBtn = container.querySelector('#btn-empty-reset-filters');
            if (emptyResetBtn) {
                emptyResetBtn.addEventListener('click', () => {
                    filterState.search = '';
                    filterState.categoryId = 'all';
                    filterState.datePreset = 'all';
                    filterState.fromDate = '';
                    filterState.toDate = '';
                    filterState.memberId = 'all';
                    filterState.memberRole = 'involved';
                    filterState.splitType = 'all';
                    filterState.minAmount = '';
                    filterState.maxAmount = '';
                    renderLedger();
                });
            }

            // Tag Clear triggers
            const tagRemoves = container.querySelectorAll('.filter-tag-remove');
            tagRemoves.forEach(tr => {
                tr.addEventListener('click', () => {
                    const clearType = tr.dataset.clear;
                    if (clearType === 'search') filterState.search = '';
                    else if (clearType === 'categoryId') filterState.categoryId = 'all';
                    else if (clearType === 'datePreset') { filterState.datePreset = 'all'; filterState.fromDate = ''; filterState.toDate = ''; }
                    else if (clearType === 'memberId') filterState.memberId = 'all';
                    else if (clearType === 'splitType') filterState.splitType = 'all';
                    else if (clearType === 'amount') { filterState.minAmount = ''; filterState.maxAmount = ''; }
                    renderLedger();
                });
            });

            // Edit Expense buttons
            const editButtons = container.querySelectorAll('.btn-edit-expense');
            editButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const expenseId = Number(btn.dataset.expenseId);
                    const expense = expenses.find(e => Number(e.id) === expenseId);
                    if (expense && typeof onEditExpense === 'function') {
                        onEditExpense(expense);
                    }
                });
            });

            // Duplicate Expense buttons
            const duplicateButtons = container.querySelectorAll('.btn-duplicate-expense');
            duplicateButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const expenseId = Number(btn.dataset.expenseId);
                    const expense = expenses.find(e => Number(e.id) === expenseId);
                    if (expense && typeof onDuplicateExpense === 'function') {
                        onDuplicateExpense(expense);
                    }
                });
            });

            // Delete Expense buttons
            const deleteButtons = container.querySelectorAll('.btn-delete-expense');
            deleteButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const expenseId = Number(btn.dataset.expenseId);
                    const expense = expenses.find(e => Number(e.id) === expenseId);
                    if (!expense) return;

                    Modal.open({
                        title: 'Delete Expense',
                        content: `
                            <p style="color: var(--text-primary); font-size: var(--font-size-sm); margin-bottom: var(--space-2);">
                                Are you sure you want to delete <strong>${Formatters.escapeHtml(expense.title)}</strong> (${Formatters.formatCurrency(expense.total_amount_cents || expense.amount_cents, currency)})?
                            </p>
                            <p style="color: var(--text-muted); font-size: var(--font-size-xs);">
                                This transaction will be soft-deleted and group balances will be recalculated.
                            </p>
                        `,
                        confirmText: 'Delete Expense',
                        confirmClass: 'btn-danger',
                        size: 'sm',
                        onConfirm: async () => {
                            try {
                                await api.deleteExpense(token, expenseId);
                                Toast.success('Expense deleted successfully.');
                                if (typeof onDelete === 'function') onDelete();
                            } catch (err) {
                                Toast.error(err.message || 'Failed to delete expense.');
                                throw err;
                            }
                        }
                    });
                });
            });

            // Receipt Lightbox buttons
            const receiptButtons = container.querySelectorAll('.btn-view-receipts');
            receiptButtons.forEach(btn => {
                btn.addEventListener('click', async () => {
                    const expenseId = Number(btn.dataset.expenseId);
                    const expense = expenses.find(e => Number(e.id) === expenseId);
                    if (!expense) return;
                    ReceiptLightbox.open(token, expenseId, expense.title, () => {
                        if (typeof onDelete === 'function') onDelete();
                    });
                });
            });
        }

        renderLedger();
    }
}
