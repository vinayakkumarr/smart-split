/**
 * Expense modal component with split calculations and validation.
 * Supports 5 split methodologies, Itemized Line-Items, Reusable Templates, and Recurring Scheduling.
 */

import { api } from '../api.js';
import { store } from '../state.js';
import * as MathUtils from '../utils/math.js';
import * as Formatters from '../utils/formatters.js';
import { Toast } from './Toast.js';
import { Modal } from './Modal.js';
import { SUPPORTED_CURRENCIES, getExchangeRate, convertCurrency } from '../utils/currency.js';
import { renderIcon } from '../utils/icons.js';
import { offlineManager } from '../utils/offline.js';

export class ExpenseModal {
    static _cachedCategories = null;
    static _cachedTemplates = {};

    /**
     * Generate a cryptographically secure UUIDv4 idempotency submission key.
     * Fails closed by returning null if secure browser randomness is unavailable.
     * @returns {string|null}
     */
    static generateSecureSubmissionId() {
        try {
            if (typeof crypto !== 'undefined') {
                if (typeof crypto.randomUUID === 'function') {
                    return crypto.randomUUID();
                }
                if (typeof crypto.getRandomValues === 'function') {
                    const bytes = new Uint8Array(16);
                    crypto.getRandomValues(bytes);
                    bytes[6] = (bytes[6] & 0x0f) | 0x40;
                    bytes[8] = (bytes[8] & 0x3f) | 0x80;
                    const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
                    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
                }
            }
        } catch (_) {}
        return null;
    }

    /**
     * Compute what-if projected balances for members given current balances and proposed transaction.
     * @param {Object} params
     * @param {Array} params.members
     * @param {Array} [params.currentBalances]
     * @param {Object} params.calculatedSplits Map of memberId -> amount_owed_cents
     * @param {Object} params.paidByMap Map of memberId -> amount_paid_cents
     * @param {Object} [params.expenseToEdit]
     * @returns {Array} Array of { memberId, name, currentNetCents, deltaCents, projectedNetCents, isParticipating }
     */
    static calculateProjectedBalances({ members = [], currentBalances = [], calculatedSplits = {}, paidByMap = {}, expenseToEdit = null }) {
        return members.map((m) => {
            const memberId = Number(m.id);
            const balanceRecord = (currentBalances || []).find((b) => Number(b.member_id || b.id) === memberId);
            const currentNetCents = balanceRecord ? (balanceRecord.net_balance_cents || 0) : 0;

            const newPaidCents = paidByMap[memberId] || 0;
            const newOwedCents = calculatedSplits[memberId] || 0;

            let deltaCents = newPaidCents - newOwedCents;

            if (expenseToEdit) {
                let oldPaidCents = 0;
                if (expenseToEdit.payers && Array.isArray(expenseToEdit.payers)) {
                    const oldP = expenseToEdit.payers.find((x) => Number(x.member_id) === memberId);
                    if (oldP) oldPaidCents = oldP.amount_paid_cents || 0;
                }
                let oldOwedCents = 0;
                if (expenseToEdit.splits && Array.isArray(expenseToEdit.splits)) {
                    const oldS = expenseToEdit.splits.find((x) => Number(x.member_id) === memberId);
                    if (oldS) oldOwedCents = oldS.amount_owed_cents || 0;
                }
                const oldDeltaCents = oldPaidCents - oldOwedCents;
                deltaCents = deltaCents - oldDeltaCents;
            }

            const projectedNetCents = currentNetCents + deltaCents;
            const isParticipating = newPaidCents > 0 || newOwedCents > 0 || Math.abs(deltaCents) > 0;

            return {
                memberId,
                name: m.name,
                currentNetCents,
                deltaCents,
                projectedNetCents,
                isParticipating,
            };
        });
    }

    /**
     * Render projected balance chips HTML.
     * @param {Array} projections
     * @param {string} currency
     * @returns {string} HTML string
     */
    static renderProjectedChips(projections = [], currency = 'INR') {
        const participating = projections.filter((p) => p.isParticipating);
        if (participating.length === 0) return '';

        const formatBalanceWithSign = (cents, cur) => {
            if (cents > 0) return `+${Formatters.formatCurrency(cents, cur)}`;
            if (cents < 0) return `-${Formatters.formatCurrency(Math.abs(cents), cur)}`;
            return Formatters.formatCurrency(0, cur);
        };

        return participating.map((p) => {
            const curNet = p.currentNetCents;
            const projNet = p.projectedNetCents;

            const curColor = curNet > 0 ? 'var(--financial-credit)' : (curNet < 0 ? 'var(--financial-debt)' : 'var(--text-muted)');
            const projColor = projNet > 0 ? 'var(--financial-credit)' : (projNet < 0 ? 'var(--financial-debt)' : 'var(--text-muted)');

            return `
                <div class="whatif-balance-chip" style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: var(--surface-primary); border: 1px solid var(--border-subtle); border-radius: var(--radius-xs); font-size: var(--font-size-xs);">
                    <span style="font-weight: 600; color: var(--text-primary);">${Formatters.escapeHtml(p.name)}:</span>
                    <span class="tnum" style="color: ${curColor}; font-family: var(--font-mono); font-size: var(--font-size-2xs);">${formatBalanceWithSign(curNet, currency)}</span>
                    <span style="color: var(--text-muted); font-size: 0.65rem;">➔</span>
                    <span class="tnum" style="font-weight: 700; color: ${projColor}; font-family: var(--font-mono); font-size: var(--font-size-2xs);">${formatBalanceWithSign(projNet, currency)}</span>
                </div>
            `;
        }).join('');
    }

    /**
     * Open the Add/Edit/Duplicate Expense Modal.
     * @param {Object} options
     * @param {string} options.token Group invite token
     * @param {Array} options.members List of group members
     * @param {string} [options.currency] Default: 'INR'
     * @param {Object} [options.expenseToEdit] Optional existing expense object for editing
     * @param {Object} [options.duplicateFrom] Optional expense object to duplicate
     * @param {Function} [options.onSuccess] Callback when expense is created/updated
     */
    static async open({ token, members = [], currency = 'INR', expenseToEdit = null, duplicateFrom = null, onSuccess = null }) {
        if (!members || members.length === 0) {
            Toast.show('Please add members to the workspace before logging transactions.', 'warning');
            return;
        }

        const isEditing = !!expenseToEdit;

        // Secure modal-scoped idempotency key for new expense submission (CSPRNG)
        let submissionId = null;
        if (!isEditing) {
            submissionId = ExpenseModal.generateSecureSubmissionId();
        }

        const source = duplicateFrom || expenseToEdit;
        const initialTitle = source ? source.title : '';
        const initialDate = isEditing ? expenseToEdit.expense_date : new Date().toISOString().split('T')[0];
        let currentSplitType = source ? (source.split_type || 'EQUAL') : 'EQUAL';
        const initialCategoryId = source && source.category ? source.category.id : (source && source.category_id ? source.category_id : 1);
        const initialNotes = source && source.notes ? source.notes : '';
        const initialTax = source && source.tax_cents ? MathUtils.toDecimal(source.tax_cents) : '';
        const initialTip = source && source.tip_cents ? MathUtils.toDecimal(source.tip_cents) : '';
        const initialDiscount = source && source.discount_cents ? MathUtils.toDecimal(source.discount_cents) : '';

        // Multi-Currency initial values
        let currentCurrency = source && source.original_currency_code ? source.original_currency_code : (source && source.original_currency ? source.original_currency : (source && source.currency ? source.currency : currency));
        let currentRate = source && source.exchange_rate ? source.exchange_rate : getExchangeRate(currentCurrency, currency);
        let initialAmount = '';
        if (source) {
            if (source.original_amount_cents) {
                initialAmount = MathUtils.toDecimal(source.original_amount_cents);
            } else if (source.total_amount_cents) {
                initialAmount = MathUtils.toDecimal(source.total_amount_cents);
            } else if (source.amount_cents) {
                initialAmount = MathUtils.toDecimal(source.amount_cents);
            }
        }

        // Determine if initial state is multi-payer
        let isMultiPayer = source && source.payers && source.payers.length > 1;

        // Pending receipt attachment state
        let pendingReceipt = null;

        // Initial items for itemized split
        let initialItems = [];
        if (source && source.items && Array.isArray(source.items) && source.items.length > 0) {
            initialItems = source.items.map((it) => ({
                name: it.name,
                amount: MathUtils.toDecimal(it.amount_cents),
                member_ids: (it.assigned_members || []).map((m) => (typeof m === 'object' ? (m.member_id || m.id) : m)),
            }));
        } else {
            initialItems = [
                { name: 'Item 1', amount: '', member_ids: members.map((m) => m.id) }
            ];
        }

        // State for Itemized line items
        let itemizedState = initialItems.map(it => ({
            name: it.name || '',
            amount: it.amount || '',
            member_ids: it.member_ids && it.member_ids.length > 0 ? [...it.member_ids] : members.map(m => m.id)
        }));

        // Default system categories & cached templates for instantaneous synchronous render
        const defaultCategories = [
            { id: 1, name: 'General', icon: '📦', is_system: true },
            { id: 2, name: 'Food & Dining', icon: '🍽️', is_system: true },
            { id: 3, name: 'Travel & Transport', icon: '✈️', is_system: true },
            { id: 4, name: 'Housing & Rent', icon: '🏠', is_system: true },
            { id: 5, name: 'Utilities & Bills', icon: '⚡', is_system: true },
            { id: 6, name: 'Groceries', icon: '🛒', is_system: true },
            { id: 7, name: 'Entertainment', icon: '🎟️', is_system: true },
        ];

        let categories = ExpenseModal._cachedCategories || defaultCategories;
        let savedTemplates = ExpenseModal._cachedTemplates[token] || [];

        const content = document.createElement('div');
        content.innerHTML = `
            <form id="expense-form" autocomplete="off">
                <!-- Primary expense fields -->
                <!-- Title & Amount in streamlined view -->
                <div style="margin-bottom: var(--space-3);">
                    <label class="form-label" for="modal-expense-title">Description *</label>
                    <input type="text" id="modal-expense-title" class="form-input" placeholder="e.g. Team Dinner, Flight, Rent" value="${Formatters.escapeHtml(initialTitle)}" required autofocus>
                </div>

                <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: var(--space-3); margin-bottom: var(--space-3);">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
                            <label class="form-label" for="modal-expense-amount" style="margin-bottom: 0;">Amount *</label>
                            <select id="modal-expense-currency" class="form-select" style="width: auto; font-size: var(--font-size-xs); padding: 2px 6px; min-height: 22px; background: var(--surface-secondary); border: 1px solid var(--border-subtle); color: var(--text-primary); cursor: pointer;" title="Select Currency">
                                ${SUPPORTED_CURRENCIES.map(c => `
                                    <option value="${c.code}" ${c.code === currentCurrency ? 'selected' : ''}>
                                        ${c.code} (${c.symbol})
                                    </option>
                                `).join('')}
                            </select>
                        </div>
                        <div class="input-currency-wrapper">
                            <span class="input-currency-symbol" id="modal-amount-symbol">${Formatters.getCurrencySymbol(currentCurrency)}</span>
                            <input type="text" inputmode="decimal" id="modal-expense-amount" class="form-input input-currency" placeholder="0.00" value="${initialAmount}" required>
                        </div>
                    </div>

                    <!-- Single Payer Selector (Fast Path) -->
                    <div>
                        <label class="form-label" for="modal-payer-select" style="margin-bottom: 2px;">Paid By *</label>
                        <div id="single-payer-container" style="${isMultiPayer ? 'display: none;' : 'display: block;'}">
                            <select id="modal-payer-select" class="form-select">
                                ${members.map((m) => {
                                    const selected = source && source.payers && source.payers.length > 0 && Number(source.payers[0].member_id || source.payers[0].id) === Number(m.id) ? 'selected' : '';
                                    return `<option value="${m.id}" ${selected}>${Formatters.escapeHtml(m.name)}</option>`;
                                }).join('')}
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Level 1 Progressive Split Indicator Card -->
                <div id="progressive-split-summary-card" class="progressive-split-card" style="margin-bottom: var(--space-3);">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: var(--brand-primary); display: inline-flex; align-items: center;">${renderIcon('users', { size: 15 })}</span>
                        <div style="display: flex; flex-direction: column;">
                            <span id="progressive-split-headline" style="font-size: var(--font-size-xs); font-weight: 700; color: var(--text-primary);">Equal Split · All ${members.length} Members</span>
                            <span id="progressive-split-subtext" style="font-size: var(--font-size-2xs); color: var(--text-muted); font-family: var(--font-mono);">₹0.00 / person</span>
                        </div>
                    </div>
                    <button type="button" id="btn-toggle-advanced-split" class="btn btn-ghost btn-sm" style="font-size: var(--font-size-xs); font-weight: 600; color: var(--brand-primary); padding: 3px 8px; min-height: 24px;">
                        ${renderIcon('sliders', { size: 12 })}
                        <span id="btn-toggle-advanced-split-text">${isEditing || !!duplicateFrom || currentSplitType !== 'EQUAL' ? 'Hide Split Adjustments ▴' : 'Adjust Split ▾'}</span>
                    </button>
                </div>

                <!-- Split mode configuration inputs -->
                <div id="advanced-split-section" class="progressive-disclosure-section" style="${isEditing || !!duplicateFrom || currentSplitType !== 'EQUAL' ? 'display: block;' : 'display: none;'} border-top: 1px dashed var(--border-subtle); padding-top: var(--space-3); margin-bottom: var(--space-3);">
                    <!-- Split Type Selector Tabs -->
                    <div class="form-group" style="margin-bottom: var(--space-3);">
                        <label class="form-label" style="font-size: var(--font-size-xs);">Allocation Methodology</label>
                        <div class="split-type-tabs" id="split-type-tabs">
                            <button type="button" class="split-tab-btn ${currentSplitType === 'EQUAL' ? 'active' : ''}" data-type="EQUAL">Equal (=)</button>
                            <button type="button" class="split-tab-btn ${currentSplitType === 'EXACT' ? 'active' : ''}" data-type="EXACT">Exact (₹)</button>
                            <button type="button" class="split-tab-btn ${currentSplitType === 'PERCENTAGE' ? 'active' : ''}" data-type="PERCENTAGE">Percent (%)</button>
                            <button type="button" class="split-tab-btn ${currentSplitType === 'SHARES' ? 'active' : ''}" data-type="SHARES">Shares (×)</button>
                            <button type="button" class="split-tab-btn ${currentSplitType === 'ITEMIZED' ? 'active' : ''}" data-type="ITEMIZED">Itemized</button>
                        </div>
                    </div>

                    <!-- "What-If" Projected Balance Impact Live Container -->
                    <div id="whatif-balance-container" style="display: none; background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: var(--space-2) var(--space-3); margin-bottom: var(--space-3);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: var(--font-size-2xs); text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.05em; display: flex; align-items: center; gap: 4px;">
                                <span style="display: inline-flex; align-items: center; color: var(--brand-primary);">${renderIcon('sparkles', { size: 12 })}</span>
                                <span>Projected Balance Impact ("What-If")</span>
                            </span>
                            <span style="font-size: var(--font-size-2xs); color: var(--text-subtle);">Post-Save Position</span>
                        </div>
                        <div id="whatif-chips-list" style="display: flex; flex-wrap: wrap; gap: 6px;">
                            <!-- Dynamically rendered member balance projection chips -->
                        </div>
                    </div>

                    <!-- Standard Participants Split Matrix (Used for EQUAL, EXACT, PERCENTAGE, SHARES) -->
                    <div id="standard-split-container" style="${currentSplitType === 'ITEMIZED' ? 'display: none;' : 'display: block;'}">
                        <div class="form-group">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-1);">
                                <label class="form-label" style="margin-bottom: 0;">Participant Shares</label>
                                <button type="button" id="toggle-all-participants-btn" style="background: none; border: none; color: var(--brand-primary); font-size: 0.75rem; font-weight: 600; cursor: pointer;">
                                    Select All
                                </button>
                            </div>

                            <div class="participant-list" id="participant-list">
                                ${members.map((m) => {
                                    let isChecked = true;
                                    let customVal = '';
                                    if (source && source.splits) {
                                        const s = source.splits.find(x => Number(x.member_id || x.id) === Number(m.id));
                                        isChecked = !!s;
                                        if (s) {
                                            if (currentSplitType === 'EXACT') customVal = MathUtils.toDecimal(s.amount_owed_cents ?? s.computed_amount_cents ?? 0);
                                            else if (currentSplitType === 'PERCENTAGE') customVal = s.split_value !== undefined && s.split_value !== null ? String(s.split_value) : (s.percentage !== undefined && s.percentage !== null ? String(s.percentage) : '');
                                            else if (currentSplitType === 'SHARES') customVal = s.split_value !== undefined && s.split_value !== null ? String(s.split_value) : (s.shares !== undefined && s.shares !== null ? String(s.shares) : '1');
                                        }
                                    }

                                    return `
                                        <div class="participant-row ${!isChecked ? 'is-excluded' : ''}" data-member-id="${m.id}">
                                            <div class="participant-info">
                                                <input type="checkbox" class="participant-check" data-member-id="${m.id}" ${isChecked ? 'checked' : ''} style="width: 15px; height: 15px; cursor: pointer; accent-color: var(--brand-primary);">
                                                <span class="participant-avatar-sm">${Formatters.getInitials(m.name)}</span>
                                                <span class="participant-name">${Formatters.escapeHtml(m.name)}</span>
                                            </div>
                                            <div class="participant-input-wrap custom-split-wrap" style="${currentSplitType === 'EQUAL' ? 'display: none;' : 'display: flex;'}">
                                                <input type="text" inputmode="decimal" class="participant-input split-custom-input" data-member-id="${m.id}" placeholder="0" value="${customVal}">
                                            </div>
                                            <div class="participant-preview" data-member-id="${m.id}">₹0.00</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>

                    <!-- Itemized Split Section (Used when split_type === ITEMIZED) -->
                    <div id="itemized-split-container" style="${currentSplitType === 'ITEMIZED' ? 'display: block;' : 'display: none;'}">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2);">
                            <label class="form-label" style="margin-bottom: 0;">Line Items & Member Allocations</label>
                            <button type="button" id="add-item-btn" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 3px 8px;">
                                + Add Line Item
                            </button>
                        </div>

                        <div id="itemized-items-list" style="display: flex; flex-direction: column; gap: var(--space-2); margin-bottom: var(--space-3); max-height: 220px; overflow-y: auto; padding-right: 2px;">
                            <!-- Items rendered dynamically -->
                        </div>

                        <!-- Taxes, Tips, Discounts Surcharges Bar -->
                        <div style="background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: var(--space-2); margin-bottom: var(--space-3);">
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); margin-bottom: var(--space-2); text-transform: uppercase; letter-spacing: 0.05em;">
                                Shared Surcharges & Discounts (Proportionally Distributed)
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-2);">
                                <div>
                                    <label style="display: block; font-size: 0.7rem; color: var(--text-muted); margin-bottom: 2px;">+ Tax / Fee (₹)</label>
                                    <input type="text" inputmode="decimal" id="itemized-tax-input" class="form-input" style="font-size: 0.8rem; padding: 4px 6px;" placeholder="0.00" value="${initialTax}">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 0.7rem; color: var(--text-muted); margin-bottom: 2px;">+ Tip / Gratuity (₹)</label>
                                    <input type="text" inputmode="decimal" id="itemized-tip-input" class="form-input" style="font-size: 0.8rem; padding: 4px 6px;" placeholder="0.00" value="${initialTip}">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 0.7rem; color: var(--text-muted); margin-bottom: 2px;">- Discount (₹)</label>
                                    <input type="text" inputmode="decimal" id="itemized-discount-input" class="form-input" style="font-size: 0.8rem; padding: 4px 6px;" placeholder="0.00" value="${initialDiscount}">
                                </div>
                            </div>
                        </div>

                        <!-- Itemized Live Summary Preview -->
                        <div id="itemized-summary-box" style="background: var(--surface-tertiary, var(--surface-secondary)); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); padding: var(--space-2); margin-bottom: var(--space-3); font-size: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; font-weight: 600; margin-bottom: 4px;">
                                <span>Items Subtotal: <strong id="itemized-subtotal-val">₹0.00</strong></span>
                                <span>Net Total: <strong id="itemized-net-val" style="color: var(--brand-primary);">₹0.00</strong></span>
                            </div>
                            <div id="itemized-member-breakdown" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; padding-top: 6px; border-top: 1px dashed var(--border-subtle);">
                                <!-- Member breakdown chips -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Level 3 Toggle Button -->
                <div style="display: flex; justify-content: flex-end; margin-bottom: var(--space-3);">
                    <button type="button" id="btn-toggle-more-options" class="btn btn-ghost btn-sm" style="font-size: var(--font-size-2xs); color: var(--text-muted); padding: 2px 6px; min-height: 22px;">
                        ${renderIcon('moreHorizontal', { size: 12 })}
                        <span id="btn-toggle-more-options-text">${isEditing || !!duplicateFrom || initialCategoryId !== 1 || initialNotes || isMultiPayer ? 'Fewer Details ▴' : '+ Category, Date, Notes, Receipt ▾'}</span>
                    </button>
                </div>

                <!-- Additional expense options -->
                <div id="more-options-section" class="progressive-disclosure-section" style="${isEditing || !!duplicateFrom || initialCategoryId !== 1 || initialNotes || isMultiPayer ? 'display: block;' : 'display: none;'} border-top: 1px dashed var(--border-subtle); padding-top: var(--space-3); margin-bottom: var(--space-3);">
                    <!-- Reusable Template Quick-Loader (If templates exist) -->
                    ${!isEditing && !duplicateFrom ? `
                        <div id="template-bar" style="display: ${savedTemplates.length > 0 ? 'flex' : 'none'}; align-items: center; justify-content: space-between; background: var(--surface-secondary); padding: 5px 8px; border-radius: var(--radius-sm); margin-bottom: var(--space-3); border: 1px solid var(--border-subtle); font-size: var(--font-size-xs);">
                            <span style="font-weight: 600; color: var(--text-secondary);">Preset Template:</span>
                            <select id="modal-template-select" class="form-select" style="font-size: var(--font-size-xs); padding: 2px 6px; width: auto; min-width: 170px;">
                                <option value="">-- Custom Transaction --</option>
                                ${savedTemplates.map((t) => `<option value="${t.id}">${Formatters.escapeHtml(t.title)} (${t.split_type})</option>`).join('')}
                            </select>
                        </div>
                    ` : ''}

                    <!-- Category & Date Grid -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); margin-bottom: var(--space-3);">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
                                <label class="form-label" for="modal-expense-category" style="margin-bottom: 0;">Category</label>
                                <button type="button" id="btn-modal-add-category" class="btn btn-ghost btn-sm" style="font-size: var(--font-size-2xs); padding: 0 4px; min-height: 20px; color: var(--brand-accent);" title="Create Custom Category">
                                    + Custom
                                </button>
                            </div>
                            <select id="modal-expense-category" class="form-select">
                                ${categories.map((c) => `
                                    <option value="${c.id}" ${initialCategoryId === c.id ? 'selected' : ''}>${Formatters.escapeHtml(c.name)}</option>
                                `).join('')}
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="modal-expense-date">Transaction Date</label>
                            <input type="date" id="modal-expense-date" class="form-input" value="${initialDate}" required>
                        </div>
                    </div>

                    <!-- Multi-Payer Mode Container -->
                    <div class="form-group" style="margin-bottom: var(--space-3);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-1);">
                            <label class="form-label" style="margin-bottom: 0;">Payment Distribution</label>
                            <button type="button" id="toggle-multi-payer-btn" class="btn btn-ghost btn-sm" style="font-size: var(--font-size-xs); padding: 2px 6px; min-height: 24px;">
                                ${isMultiPayer ? 'Single Payer Mode' : 'Multiple Payers?'}
                            </button>
                        </div>

                        <div id="multi-payer-container" style="${isMultiPayer ? 'display: block;' : 'display: none;'} border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: var(--space-2); background: var(--surface-secondary); max-height: 150px; overflow-y: auto;">
                            ${members.map((m) => {
                                let paidVal = '0.00';
                                if (source && source.payers) {
                                    const p = source.payers.find(x => Number(x.member_id || x.id) === Number(m.id));
                                    if (p) paidVal = MathUtils.toDecimal(p.amount_paid_cents ?? p.amount_cents ?? 0);
                                }
                                return `
                                    <div class="participant-row" style="padding: 4px 6px; background: transparent; border-bottom: 1px solid var(--border-subtle);">
                                        <div class="participant-info">
                                            <span class="participant-avatar-sm">${Formatters.getInitials(m.name)}</span>
                                            <span class="participant-name">${Formatters.escapeHtml(m.name)}</span>
                                        </div>
                                        <div class="participant-input-wrap">
                                            <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); font-family: var(--font-mono);">₹</span>
                                            <input type="text" inputmode="decimal" class="participant-input multi-payer-input" data-member-id="${m.id}" placeholder="0.00" value="${paidVal}">
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                            <div id="multi-payer-validation" style="font-size: var(--font-size-2xs); font-weight: 700; text-align: right; padding: 4px 6px; color: var(--financial-debt); font-family: var(--font-mono);">
                                Paid total must equal transaction amount
                            </div>
                        </div>
                    </div>

                    <!-- Foreign Currency Live Conversion Drawer -->
                    <div id="fx-conversion-container" style="display: ${currentCurrency !== currency ? 'block' : 'none'}; background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-xs); padding: var(--space-2) var(--space-3); margin-bottom: var(--space-3);">
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: var(--font-size-xs); margin-bottom: 4px;">
                            <span style="font-weight: 600; color: var(--text-primary);">
                                Foreign Currency Conversion
                            </span>
                            <span id="fx-converted-badge" class="badge badge-credit badge-mono" style="font-size: var(--font-size-2xs);">
                                = ${Formatters.formatCurrency(0, currency)}
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: var(--space-2); font-size: var(--font-size-xs);">
                            <span style="color: var(--text-muted);">Rate: 1 <span id="fx-from-code">${currentCurrency}</span> =</span>
                            <input type="number" step="0.0001" id="modal-fx-rate" class="form-input" style="width: 100px; font-size: var(--font-size-xs); padding: 2px 6px; height: 26px; text-align: right;" value="${currentRate}">
                            <span style="color: var(--text-secondary); font-weight: 600;">${currency}</span>
                        </div>
                    </div>

                    <!-- Notes / Memo -->
                    <div class="form-group" style="margin-bottom: var(--space-3);">
                        <label class="form-label" for="modal-expense-notes" style="font-size: var(--font-size-xs); color: var(--text-muted);">Notes / Bill Memo (Optional)</label>
                        <input type="text" id="modal-expense-notes" class="form-input" placeholder="e.g. Invoice #4092, Table 5, including surge" value="${Formatters.escapeHtml(initialNotes)}">
                    </div>

                    <!-- Receipt Attachment Dropzone -->
                    <div class="form-group" style="margin-bottom: var(--space-3);">
                        <label class="form-label" style="font-size: var(--font-size-xs); color: var(--text-muted); display: flex; justify-content: space-between; align-items: center;">
                            <span>Receipt Attachment (Optional)</span>
                            <span style="font-size: var(--font-size-2xs); color: var(--text-subtle);">JPG, PNG, WebP, PDF (Max 5MB)</span>
                        </label>

                        <div id="receipt-upload-dropzone" style="border: 1px dashed var(--border-color); border-radius: var(--radius-sm); padding: var(--space-2) var(--space-3); background: var(--surface-secondary); text-align: center; cursor: pointer; transition: all var(--transition-fast);">
                            <input type="file" id="modal-receipt-file-input" accept="image/jpeg,image/png,image/webp,application/pdf" style="display: none;">
                            
                            <div id="receipt-dropzone-prompt" style="display: flex; align-items: center; justify-content: center; gap: 8px; font-size: var(--font-size-xs); color: var(--text-secondary);">
                                <span>Click or drag & drop receipt photo or PDF</span>
                            </div>

                            <div id="receipt-preview-container" style="display: none; align-items: center; justify-content: space-between; gap: 8px;">
                                <div style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                                    <img id="receipt-thumb-img" src="" alt="Preview" style="width: 32px; height: 32px; object-fit: cover; border-radius: 4px; display: none;">
                                    <span id="receipt-pdf-icon" style="font-size: 1.1rem; display: none;">PDF</span>
                                    <span id="receipt-file-label" style="font-size: var(--font-size-xs); font-weight: 600; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px;"></span>
                                    <span id="receipt-file-size" class="badge badge-settled" style="font-size: var(--font-size-2xs);"></span>
                                </div>
                                <button type="button" id="btn-remove-receipt" class="btn btn-ghost btn-sm" style="color: var(--financial-debt); padding: 2px 6px; font-size: var(--font-size-xs);">
                                    Remove
                                </button>
                            </div>
                        </div>

                        <!-- Existing Attached Receipts (if editing) -->
                        ${isEditing && expenseToEdit.receipts && expenseToEdit.receipts.length > 0 ? `
                            <div style="margin-top: 6px; display: flex; flex-wrap: wrap; gap: 6px;">
                                ${expenseToEdit.receipts.map(r => `
                                    <div class="badge badge-mono" style="font-size: var(--font-size-2xs); padding: 2px 6px; display: flex; align-items: center; gap: 4px; background: var(--surface-tertiary); color: var(--brand-primary); border: 1px solid var(--border-subtle);">
                                        <span>${Formatters.escapeHtml(r.file_name)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        ` : ''}
                    </div>

                    <!-- Automation & Presets Section (Templates & Recurring Rules) -->
                    ${!isEditing && !duplicateFrom ? `
                        <div style="background: var(--surface-secondary); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); padding: var(--space-2); margin-bottom: var(--space-2); font-size: 0.75rem;">
                            <div style="display: flex; flex-direction: column; gap: var(--space-2);">
                                <label style="display: flex; align-items: center; gap: 6px; color: var(--text-secondary); cursor: pointer; user-select: none;">
                                    <input type="checkbox" id="modal-save-template-check" style="accent-color: var(--brand-primary); cursor: pointer;">
                                    <span style="display: inline-flex; align-items: center; gap: 4px;">${renderIcon('bookmark', { size: 13 })} <span>Save this setup as a reusable template preset</span></span>
                                </label>
                                
                                <label style="display: flex; align-items: center; gap: 6px; color: var(--text-secondary); cursor: pointer; user-select: none;">
                                    <input type="checkbox" id="modal-recurring-check" style="accent-color: var(--brand-primary); cursor: pointer;">
                                    <span style="display: inline-flex; align-items: center; gap: 4px;">${renderIcon('refreshCw', { size: 13 })} <span>Schedule as recurring expense (rent, utilities, subscriptions)</span></span>
                                </label>

                                <div id="modal-recurring-options" style="display: none; grid-template-columns: 1fr 1fr; gap: var(--space-2); margin-top: 4px; padding-top: 6px; border-top: 1px dashed var(--border-subtle);">
                                    <div>
                                        <label style="display: block; font-size: 0.7rem; color: var(--text-muted); margin-bottom: 2px;">Frequency</label>
                                        <select id="modal-recurring-frequency" class="form-select" style="font-size: 0.75rem; padding: 3px 6px;">
                                            <option value="MONTHLY" selected>Monthly (Every month)</option>
                                            <option value="WEEKLY">Weekly</option>
                                            <option value="BIWEEKLY">Bi-weekly (2 weeks)</option>
                                            <option value="YEARLY">Yearly (Annual)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 0.7rem; color: var(--text-muted); margin-bottom: 2px;">Repeat Until (Optional)</label>
                                        <input type="date" id="modal-recurring-end-date" class="form-input" style="font-size: 0.75rem; padding: 3px 6px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    ` : ''}
                </div>

                <!-- Real-Time Validation Alert Bar -->
                <div id="split-validation-bar" class="validation-alert info">
                    <span>Enter total amount to calculate participant allocation</span>
                    <span id="validation-remaining-badge" class="tnum">₹0.00</span>
                </div>
            </form>
        `;

        Modal.open({
            title: isEditing ? 'Edit Transaction' : (duplicateFrom ? 'Duplicate Transaction' : 'Record New Transaction'),
            content,
            size: 'lg',
            confirmText: isEditing ? 'Update Transaction' : (duplicateFrom ? 'Create Duplicate' : 'Save Transaction'),
            confirmClass: 'btn-primary',

            onMount: (overlay) => {
                const titleInput = overlay.querySelector('#modal-expense-title');
                const categorySelect = overlay.querySelector('#modal-expense-category');
                const amountInput = overlay.querySelector('#modal-expense-amount');
                const dateInput = overlay.querySelector('#modal-expense-date');
                const notesInput = overlay.querySelector('#modal-expense-notes');
                const payerSelect = overlay.querySelector('#modal-payer-select');
                const toggleMultiPayerBtn = overlay.querySelector('#toggle-multi-payer-btn');
                const singlePayerContainer = overlay.querySelector('#single-payer-container');
                const multiPayerContainer = overlay.querySelector('#multi-payer-container');
                const multiPayerValidation = overlay.querySelector('#multi-payer-validation');
                const multiPayerInputs = overlay.querySelectorAll('.multi-payer-input');
                const tabButtons = overlay.querySelectorAll('.split-tab-btn');
                const standardSplitContainer = overlay.querySelector('#standard-split-container');
                const itemizedSplitContainer = overlay.querySelector('#itemized-split-container');
                const participantChecks = overlay.querySelectorAll('.participant-check');
                const customSplitWraps = overlay.querySelectorAll('.custom-split-wrap');
                const customSplitInputs = overlay.querySelectorAll('.split-custom-input');
                const participantPreviews = overlay.querySelectorAll('.participant-preview');
                const toggleAllBtn = overlay.querySelector('#toggle-all-participants-btn');
                const validationBar = overlay.querySelector('#split-validation-bar');
                const validationBadge = overlay.querySelector('#validation-remaining-badge');
                const confirmBtn = overlay.querySelector('.modal-btn-confirm');
                const whatIfContainer = overlay.querySelector('#whatif-balance-container');
                const whatIfChipsList = overlay.querySelector('#whatif-chips-list');

                // Progressive Disclosure Controls
                const advancedSplitSection = overlay.querySelector('#advanced-split-section');
                const moreOptionsSection = overlay.querySelector('#more-options-section');
                const btnToggleAdvancedSplit = overlay.querySelector('#btn-toggle-advanced-split');
                const btnToggleAdvancedSplitText = overlay.querySelector('#btn-toggle-advanced-split-text');
                const btnToggleMoreOptions = overlay.querySelector('#btn-toggle-more-options');
                const btnToggleMoreOptionsText = overlay.querySelector('#btn-toggle-more-options-text');
                const progressiveSplitHeadline = overlay.querySelector('#progressive-split-headline');
                const progressiveSplitSubtext = overlay.querySelector('#progressive-split-subtext');

                if (btnToggleAdvancedSplit && advancedSplitSection) {
                    btnToggleAdvancedSplit.addEventListener('click', () => {
                        const isHidden = advancedSplitSection.style.display === 'none';
                        advancedSplitSection.style.display = isHidden ? 'block' : 'none';
                        if (btnToggleAdvancedSplitText) {
                            btnToggleAdvancedSplitText.textContent = isHidden ? 'Hide Split Adjustments ▴' : 'Adjust Split ▾';
                        }
                    });
                }

                if (btnToggleMoreOptions && moreOptionsSection) {
                    btnToggleMoreOptions.addEventListener('click', () => {
                        const isHidden = moreOptionsSection.style.display === 'none';
                        moreOptionsSection.style.display = isHidden ? 'block' : 'none';
                        if (btnToggleMoreOptionsText) {
                            btnToggleMoreOptionsText.textContent = isHidden ? 'Fewer Details ▴' : '+ Category, Date, Notes, Receipt ▾';
                        }
                    });
                }

                // Currency & FX Controls
                const currencySelect = overlay.querySelector('#modal-expense-currency');
                const amountSymbol = overlay.querySelector('#modal-amount-symbol');
                const fxContainer = overlay.querySelector('#fx-conversion-container');
                const fxRateInput = overlay.querySelector('#modal-fx-rate');
                const fxFromCode = overlay.querySelector('#fx-from-code');
                const fxConvertedBadge = overlay.querySelector('#fx-converted-badge');

                if (currencySelect) {
                    currencySelect.addEventListener('change', () => {
                        currentCurrency = currencySelect.value;
                        if (amountSymbol) amountSymbol.textContent = Formatters.getCurrencySymbol(currentCurrency);
                        if (currentCurrency !== currency) {
                            if (fxContainer) fxContainer.style.display = 'block';
                            currentRate = getExchangeRate(currentCurrency, currency);
                            if (fxRateInput) fxRateInput.value = currentRate;
                            if (fxFromCode) fxFromCode.textContent = currentCurrency;
                        } else {
                            if (fxContainer) fxContainer.style.display = 'none';
                            currentRate = 1.0;
                        }
                        recalculate();
                    });
                }

                if (fxRateInput) {
                    fxRateInput.addEventListener('input', () => {
                        currentRate = parseFloat(fxRateInput.value) || getExchangeRate(currentCurrency, currency);
                        recalculate();
                    });
                }

                // Template & Recurring controls
                const templateSelect = overlay.querySelector('#modal-template-select');
                const recurringCheck = overlay.querySelector('#modal-recurring-check');
                const recurringOptions = overlay.querySelector('#modal-recurring-options');

                if (recurringCheck && recurringOptions) {
                    recurringCheck.addEventListener('change', () => {
                        recurringOptions.style.display = recurringCheck.checked ? 'grid' : 'none';
                    });
                }

                // Itemized controls
                const addItemBtn = overlay.querySelector('#add-item-btn');
                const itemizedItemsList = overlay.querySelector('#itemized-items-list');
                const itemizedTaxInput = overlay.querySelector('#itemized-tax-input');
                const itemizedTipInput = overlay.querySelector('#itemized-tip-input');
                const itemizedDiscountInput = overlay.querySelector('#itemized-discount-input');
                const itemizedSubtotalVal = overlay.querySelector('#itemized-subtotal-val');
                const itemizedNetVal = overlay.querySelector('#itemized-net-val');
                const itemizedMemberBreakdown = overlay.querySelector('#itemized-member-breakdown');

                if (confirmBtn) confirmBtn.disabled = true;

                let calculatedSplits = {};
                let isValidState = false;

                // State for Itemized line items
                itemizedState = initialItems.map(it => ({
                    name: it.name || '',
                    amount: it.amount || '',
                    member_ids: it.member_ids && it.member_ids.length > 0 ? [...it.member_ids] : members.map(m => m.id)
                }));

                // Handle template selection
                if (templateSelect) {
                    templateSelect.addEventListener('change', (e) => {
                        const tId = Number(e.target.value);
                        if (!tId) return;
                        const tpl = savedTemplates.find(x => x.id === tId);
                        if (!tpl || !tpl.payload) return;

                        const p = tpl.payload;
                        titleInput.value = p.title || tpl.title || '';
                        if (p.category_id) categorySelect.value = String(p.category_id);
                        if (p.notes) notesInput.value = p.notes;

                        const sType = (p.split_type || tpl.split_type || 'EQUAL').toUpperCase();
                        currentSplitType = sType;
                        tabButtons.forEach(btn => {
                            btn.classList.toggle('active', btn.dataset.type === sType);
                        });

                        if (sType === 'ITEMIZED' && p.items && Array.isArray(p.items)) {
                            itemizedState = p.items.map(it => ({
                                name: it.name,
                                amount: MathUtils.toDecimal(it.amount_cents || 0),
                                member_ids: it.member_ids || members.map(m => m.id)
                            }));
                            itemizedTaxInput.value = p.tax_cents ? MathUtils.toDecimal(p.tax_cents) : '';
                            itemizedTipInput.value = p.tip_cents ? MathUtils.toDecimal(p.tip_cents) : '';
                            itemizedDiscountInput.value = p.discount_cents ? MathUtils.toDecimal(p.discount_cents) : '';
                            renderItemizedRows();
                        } else {
                            if (p.amount_cents) amountInput.value = MathUtils.toDecimal(p.amount_cents);
                            if (p.splits && Array.isArray(p.splits)) {
                                participantChecks.forEach(chk => {
                                    const mId = Number(chk.dataset.memberId);
                                    const s = p.splits.find(x => Number(x.member_id) === mId);
                                    chk.checked = !!s;
                                    const customInp = overlay.querySelector(`.split-custom-input[data-member-id="${mId}"]`);
                                    if (customInp && s) {
                                        if (sType === 'EXACT') customInp.value = MathUtils.toDecimal(s.amount_owed_cents || 0);
                                        else if (sType === 'PERCENTAGE') customInp.value = s.split_value ? String(s.split_value) : '';
                                        else if (sType === 'SHARES') customInp.value = s.split_value ? String(s.split_value) : '1';
                                    }
                                });
                            }
                        }

                        updateSplitInputsUI();
                        recalculate();
                        Toast.show(`Loaded template: "${tpl.title}"`, 'info');
                    });
                }

                // Non-blocking asynchronous hydration for workspace custom categories and templates
                if (!ExpenseModal._cachedCategories || !ExpenseModal._cachedTemplates[token]) {
                    Promise.all([
                        ExpenseModal._cachedTemplates[token]
                            ? Promise.resolve(ExpenseModal._cachedTemplates[token])
                            : api.getTemplates(token).then((r) => r?.data?.templates || []).catch(() => []),
                        ExpenseModal._cachedCategories
                            ? Promise.resolve(ExpenseModal._cachedCategories)
                            : api.getCategories(token).then((r) => r?.data?.categories || []).catch(() => []),
                    ]).then(([tpls, cats]) => {
                        if (tpls && Array.isArray(tpls) && tpls.length > 0) {
                            ExpenseModal._cachedTemplates[token] = tpls;
                            savedTemplates = tpls;
                            const tBar = overlay.querySelector('#template-bar');
                            const tSel = overlay.querySelector('#modal-template-select');
                            if (tSel) {
                                tSel.innerHTML = `
                                    <option value="">-- Custom Transaction --</option>
                                    ${tpls.map((t) => `<option value="${t.id}">${Formatters.escapeHtml(t.title)} (${t.split_type})</option>`).join('')}
                                `;
                                if (tBar) tBar.style.display = 'flex';
                            }
                        }
                        if (cats && Array.isArray(cats) && cats.length > 0) {
                            ExpenseModal._cachedCategories = cats;
                            if (categorySelect) {
                                const currentCatVal = categorySelect.value;
                                categorySelect.innerHTML = cats.map((c) => `
                                    <option value="${c.id}" ${String(currentCatVal) === String(c.id) ? 'selected' : ''}>${Formatters.escapeHtml(c.name)}</option>
                                `).join('');
                            }
                        }
                    }).catch(() => {});
                }

                function renderItemizedRows() {
                    itemizedItemsList.innerHTML = '';
                    itemizedState.forEach((item, index) => {
                        const row = document.createElement('div');
                        row.className = 'itemized-row';
                        row.style.cssText = 'background: var(--surface-secondary); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); padding: var(--space-2); display: flex; flex-direction: column; gap: 6px;';
                        row.dataset.index = index;

                        const memberChipsHtml = members.map(m => {
                            const isSelected = item.member_ids.includes(m.id);
                            const chipBg = isSelected ? 'var(--brand-primary)' : 'var(--surface-tertiary, var(--surface-primary))';
                            const chipColor = isSelected ? '#ffffff' : 'var(--text-muted)';
                            const chipBorder = isSelected ? 'var(--brand-primary)' : 'var(--border-color)';
                            return `
                                <button type="button" class="item-member-toggle" data-member-id="${m.id}" data-item-idx="${index}" style="background: ${chipBg}; color: ${chipColor}; border: 1px solid ${chipBorder}; border-radius: 12px; padding: 2px 8px; font-size: 0.7rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s ease;">
                                    <span>${Formatters.escapeHtml(m.name)}</span>
                                </button>
                            `;
                        }).join('');

                        row.innerHTML = `
                            <div style="display: grid; grid-template-columns: 1fr 100px 32px; gap: var(--space-2); align-items: center;">
                                <input type="text" class="form-input item-name-input" placeholder="Line item name" value="${Formatters.escapeHtml(item.name)}" style="font-size: 0.8rem; padding: 4px 8px;">
                                <div class="input-currency-wrapper">
                                    <span class="input-currency-symbol" style="font-size: 0.75rem;">${Formatters.getCurrencySymbol(currentCurrency)}</span>
                                    <input type="text" inputmode="decimal" class="form-input input-currency item-amount-input" placeholder="0.00" value="${item.amount}" style="font-size: 0.8rem; padding: 4px 6px 4px 18px;">
                                </div>
                                <button type="button" class="btn btn-ghost btn-sm remove-item-btn" title="Remove Item" style="color: var(--financial-debt); padding: 0; min-height: 28px; width: 28px; display: flex; align-items: center; justify-content: center;">
                                    ${renderIcon('trash2', { size: 14 })}
                                </button>
                            </div>
                            <div style="display: flex; flex-wrap: wrap; gap: 4px; align-items: center; padding-top: 2px;">
                                <span style="font-size: 0.68rem; color: var(--text-muted); margin-right: 2px;">Split with:</span>
                                ${memberChipsHtml}
                            </div>
                        `;

                        // Row event listeners
                        const nameInp = row.querySelector('.item-name-input');
                        const amtInp = row.querySelector('.item-amount-input');
                        const removeBtn = row.querySelector('.remove-item-btn');
                        const memberToggles = row.querySelectorAll('.item-member-toggle');

                        nameInp.addEventListener('input', (e) => {
                            itemizedState[index].name = e.target.value;
                        });

                        amtInp.addEventListener('input', (e) => {
                            itemizedState[index].amount = e.target.value;
                            recalculate();
                        });

                        removeBtn.addEventListener('click', () => {
                            if (itemizedState.length <= 1) {
                                itemizedState[0].amount = '';
                                itemizedState[0].name = '';
                                renderItemizedRows();
                                recalculate();
                                return;
                            }
                            itemizedState.splice(index, 1);
                            renderItemizedRows();
                            recalculate();
                        });

                        memberToggles.forEach(tBtn => {
                            tBtn.addEventListener('click', () => {
                                const mId = Number(tBtn.dataset.memberId);
                                const curIds = itemizedState[index].member_ids;
                                if (curIds.includes(mId)) {
                                    itemizedState[index].member_ids = curIds.filter(id => id !== mId);
                                } else {
                                    itemizedState[index].member_ids.push(mId);
                                }
                                renderItemizedRows();
                                recalculate();
                            });
                        });

                        itemizedItemsList.appendChild(row);
                    });
                }

                if (addItemBtn) {
                    addItemBtn.addEventListener('click', () => {
                        itemizedState.push({
                            name: `Item ${itemizedState.length + 1}`,
                            amount: '',
                            member_ids: members.map(m => m.id)
                        });
                        renderItemizedRows();
                        recalculate();
                    });
                }

                if (itemizedTaxInput) itemizedTaxInput.addEventListener('input', recalculate);
                if (itemizedTipInput) itemizedTipInput.addEventListener('input', recalculate);
                if (itemizedDiscountInput) itemizedDiscountInput.addEventListener('input', recalculate);

                if (currentSplitType === 'ITEMIZED') {
                    renderItemizedRows();
                }

                function updateSplitInputsUI() {
                    const isItemized = currentSplitType === 'ITEMIZED';
                    if (standardSplitContainer) standardSplitContainer.style.display = isItemized ? 'none' : 'block';
                    if (itemizedSplitContainer) itemizedSplitContainer.style.display = isItemized ? 'block' : 'none';

                    if (isItemized) {
                        amountInput.readOnly = true;
                        amountInput.style.backgroundColor = 'var(--surface-secondary)';
                    } else {
                        amountInput.readOnly = false;
                        amountInput.style.backgroundColor = '';
                    }

                    customSplitWraps.forEach((wrap) => {
                        const show = currentSplitType !== 'EQUAL' && !isItemized;
                        wrap.style.display = show ? 'inline-flex' : 'none';
                        const prefix = wrap.querySelector('.custom-prefix');
                        const suffix = wrap.querySelector('.custom-suffix');

                        if (currentSplitType === 'EXACT') {
                            if (prefix) prefix.textContent = Formatters.getCurrencySymbol(currency);
                            if (suffix) suffix.textContent = '';
                        } else if (currentSplitType === 'PERCENTAGE') {
                            if (prefix) prefix.textContent = '';
                            if (suffix) suffix.textContent = '%';
                        } else if (currentSplitType === 'SHARES') {
                            if (prefix) prefix.textContent = '';
                            if (suffix) suffix.textContent = 'shr';
                        }
                    });
                }

                tabButtons.forEach((btn) => {
                    btn.addEventListener('click', () => {
                        tabButtons.forEach((b) => b.classList.remove('active'));
                        btn.classList.add('active');
                        currentSplitType = btn.dataset.type;

                        if (advancedSplitSection) {
                            advancedSplitSection.style.display = 'block';
                            if (btnToggleAdvancedSplitText) btnToggleAdvancedSplitText.textContent = 'Hide Split Adjustments ▴';
                        }

                        if (currentSplitType === 'ITEMIZED') {
                            if (itemizedState.length === 0) {
                                itemizedState = [{ name: 'Item 1', amount: '', member_ids: members.map(m => m.id) }];
                            }
                            renderItemizedRows();
                        }

                        updateSplitInputsUI();
                        recalculate();
                    });
                });

                if (toggleAllBtn) {
                    toggleAllBtn.addEventListener('click', () => {
                        const allChecked = Array.from(participantChecks).every((c) => c.checked);
                        participantChecks.forEach((c) => {
                            c.checked = !allChecked;
                        });
                        recalculate();
                    });
                }

                toggleMultiPayerBtn.addEventListener('click', () => {
                    isMultiPayer = !isMultiPayer;
                    singlePayerContainer.style.display = isMultiPayer ? 'none' : 'block';
                    multiPayerContainer.style.display = isMultiPayer ? 'block' : 'none';
                    toggleMultiPayerBtn.textContent = isMultiPayer ? 'Single Payer Mode' : 'Multiple Payers?';

                    if (isMultiPayer && multiPayerInputs.length > 0) {
                        const defaultPayerId = Number(payerSelect.value);
                        multiPayerInputs.forEach((inp) => {
                            if (Number(inp.dataset.memberId) === defaultPayerId && !inp.value) {
                                inp.value = amountInput.value || '';
                            }
                        });
                    }
                    recalculate();
                });

                // Main Live Calculation Function
                function recalculate() {
                    const title = titleInput.value.trim();
                    let totalCents = 0;
                    calculatedSplits = {};
                    let allocatedSumCents = 0;
                    let splitValid = false;
                    let validationMsg = '';

                    if (currentSplitType === 'ITEMIZED') {
                        // Calculate itemized sum
                        let itemsSubtotalCents = 0;
                        const itemsForCalc = [];
                        let allItemsHaveMembers = true;

                        itemizedState.forEach((it) => {
                            const itCents = MathUtils.toCents(it.amount);
                            itemsSubtotalCents += itCents;
                            itemsForCalc.push({
                                name: it.name || 'Item',
                                amount_cents: itCents,
                                member_ids: it.member_ids || [],
                            });
                            if ((!it.member_ids || it.member_ids.length === 0) && itCents > 0) {
                                allItemsHaveMembers = false;
                            }
                        });

                        const taxCents = MathUtils.toCents(itemizedTaxInput.value);
                        const tipCents = MathUtils.toCents(itemizedTipInput.value);
                        const discountCents = MathUtils.toCents(itemizedDiscountInput.value);

                        const netTotalCents = Math.max(0, itemsSubtotalCents + taxCents + tipCents - discountCents);
                        totalCents = netTotalCents;
                        amountInput.value = MathUtils.toDecimal(netTotalCents);

                        itemizedSubtotalVal.textContent = Formatters.formatCurrency(itemsSubtotalCents, currency);
                        itemizedNetVal.textContent = Formatters.formatCurrency(netTotalCents, currency);

                        if (itemsSubtotalCents <= 0) {
                            validationBar.className = 'validation-alert info';
                            validationBar.firstElementChild.textContent = 'Add item amounts to calculate itemized split';
                            validationBadge.textContent = Formatters.formatCurrency(0, currency);
                            itemizedMemberBreakdown.innerHTML = '<span style="color: var(--text-muted);">No items recorded</span>';
                            if (whatIfContainer) whatIfContainer.style.display = 'none';
                            if (confirmBtn) confirmBtn.disabled = true;
                            return;
                        }

                        if (!allItemsHaveMembers) {
                            validationBar.className = 'validation-alert invalid';
                            validationBar.firstElementChild.textContent = 'Every line item with a cost must be assigned to at least one person';
                            validationBadge.textContent = 'Unassigned items';
                            if (whatIfContainer) whatIfContainer.style.display = 'none';
                            if (confirmBtn) confirmBtn.disabled = true;
                            return;
                        }

                        const itemizedResult = MathUtils.calculateItemized(itemsForCalc, taxCents, tipCents, discountCents);
                        calculatedSplits = itemizedResult.splits;
                        allocatedSumCents = netTotalCents;
                        splitValid = netTotalCents > 0 && Object.keys(calculatedSplits).length > 0;

                        // Render member breakdown chips
                        itemizedMemberBreakdown.innerHTML = members.map(m => {
                            const owed = calculatedSplits[m.id] || 0;
                            return `
                                <div style="background: var(--surface-primary); border: 1px solid var(--border-color); border-radius: 4px; padding: 2px 6px; font-size: 0.72rem; display: flex; align-items: center; gap: 4px;">
                                    <span style="font-weight: 600;">${Formatters.escapeHtml(m.name)}:</span>
                                    <span class="tnum" style="color: var(--brand-primary); font-weight: 700;">${Formatters.formatCurrency(owed, currency)}</span>
                                </div>
                            `;
                        }).join('');

                    } else {
                        const rawInputCents = MathUtils.toCents(amountInput.value);

                        if (currentCurrency !== currency) {
                            const effectiveRate = parseFloat(fxRateInput?.value) || currentRate;
                            totalCents = Math.round(rawInputCents * effectiveRate);
                            if (fxConvertedBadge) {
                                fxConvertedBadge.textContent = '= ' + Formatters.formatCurrency(totalCents, currency);
                            }
                        } else {
                            totalCents = rawInputCents;
                        }

                        participantChecks.forEach((chk) => {
                            const row = chk.closest('.participant-row');
                            if (row) {
                                row.classList.toggle('is-excluded', !chk.checked);
                            }
                        });

                        const activeMemberIds = Array.from(participantChecks)
                            .filter((chk) => chk.checked)
                            .map((chk) => Number(chk.dataset.memberId));

                        if (totalCents <= 0) {
                            validationMsg = 'Please enter transaction amount';
                            validationBar.className = 'validation-alert info';
                            validationBar.firstElementChild.textContent = validationMsg;
                            validationBadge.textContent = Formatters.formatCurrency(0, currency);
                            participantPreviews.forEach((p) => (p.textContent = Formatters.formatCurrency(0, currency)));
                            if (whatIfContainer) whatIfContainer.style.display = 'none';
                            if (confirmBtn) confirmBtn.disabled = true;
                            return;
                        }

                        if (activeMemberIds.length === 0) {
                            validationMsg = 'Select at least one participant';
                            validationBar.className = 'validation-alert invalid';
                            validationBar.firstElementChild.textContent = validationMsg;
                            validationBadge.textContent = 'No participants';
                            participantPreviews.forEach((p) => (p.textContent = Formatters.formatCurrency(0, currency)));
                            if (whatIfContainer) whatIfContainer.style.display = 'none';
                            if (confirmBtn) confirmBtn.disabled = true;
                            return;
                        }

                        let totalPct = 0;
                        let totalShares = 0;

                        if (currentSplitType === 'EQUAL') {
                            calculatedSplits = MathUtils.calculateEqual(totalCents, activeMemberIds);
                            allocatedSumCents = totalCents;
                            splitValid = true;
                        } else if (currentSplitType === 'EXACT') {
                            const exactMap = {};
                            activeMemberIds.forEach((id) => {
                                const inp = overlay.querySelector(`.split-custom-input[data-member-id="${id}"]`);
                                exactMap[id] = inp ? inp.value : 0;
                            });
                            const res = MathUtils.calculateExact(totalCents, exactMap);
                            calculatedSplits = res.splits;
                            allocatedSumCents = res.allocatedSumCents ?? res.allocatedSum ?? res.totalAllocatedCents ?? 0;
                            splitValid = res.isValid;
                        } else if (currentSplitType === 'PERCENTAGE') {
                            const pctMap = {};
                            totalPct = 0;
                            activeMemberIds.forEach((id) => {
                                const inp = overlay.querySelector(`.split-custom-input[data-member-id="${id}"]`);
                                const val = parseFloat(inp ? inp.value : 0) || 0;
                                pctMap[id] = val;
                                totalPct += val;
                            });
                            calculatedSplits = MathUtils.calculatePercentage(totalCents, pctMap);
                            allocatedSumCents = Object.values(calculatedSplits).reduce((a, b) => a + b, 0);
                            splitValid = Math.abs(totalPct - 100) < 0.01;
                        } else if (currentSplitType === 'SHARES') {
                            const sharesMap = {};
                            totalShares = 0;
                            activeMemberIds.forEach((id) => {
                                const inp = overlay.querySelector(`.split-custom-input[data-member-id="${id}"]`);
                                const val = parseFloat(inp ? inp.value : 0) || 0;
                                sharesMap[id] = val;
                                totalShares += val;
                            });
                            calculatedSplits = MathUtils.calculateShares(totalCents, sharesMap);
                            allocatedSumCents = Object.values(calculatedSplits).reduce((a, b) => a + b, 0);
                            splitValid = totalShares > 0;
                        }

                        // Update participant preview badges
                        participantChecks.forEach((chk) => {
                            const memberId = Number(chk.dataset.memberId);
                            const preview = overlay.querySelector(`.participant-preview[data-member-id="${memberId}"]`);
                            if (preview) {
                                if (chk.checked && calculatedSplits[memberId] !== undefined) {
                                    preview.textContent = Formatters.formatCurrency(calculatedSplits[memberId], currency);
                                    preview.style.opacity = '1';
                                } else {
                                    preview.textContent = Formatters.formatCurrency(0, currency);
                                    preview.style.opacity = '0.4';
                                }
                            }
                        });
                    }

                    // 2. Validate Payers (if multi-payer)
                    let payerValid = true;
                    let totalPaidCents = 0;
                    const paidByMap = {};

                    if (isMultiPayer) {
                        multiPayerInputs.forEach((inp) => {
                            const mId = Number(inp.dataset.memberId);
                            const pCents = MathUtils.toCents(inp.value);
                            totalPaidCents += pCents;
                            paidByMap[mId] = pCents;
                        });
                        payerValid = totalPaidCents === totalCents;
                        if (!payerValid) {
                            const diff = Math.abs(totalCents - totalPaidCents);
                            multiPayerValidation.textContent = `Paid: ${Formatters.formatCurrency(totalPaidCents, currency)} of ${Formatters.formatCurrency(totalCents, currency)} (${Formatters.formatCurrency(diff, currency)} ${totalPaidCents < totalCents ? 'remaining' : 'over'})`;
                            multiPayerValidation.style.color = 'var(--financial-debt)';
                        } else {
                            multiPayerValidation.textContent = `✓ Total paid matches (${Formatters.formatCurrency(totalPaidCents, currency)})`;
                            multiPayerValidation.style.color = 'var(--financial-credit)';
                        }
                    } else {
                        const singlePayerId = Number(payerSelect.value);
                        paidByMap[singlePayerId] = totalCents;
                    }

                    // 3. Update Validation Bar
                    const diffCents = totalCents - allocatedSumCents;
                    if (splitValid && payerValid && title.length > 0) {
                        validationBar.className = 'validation-alert valid';
                        validationBar.firstElementChild.textContent = `✓ Total ${Formatters.formatCurrency(totalCents, currency)} reconciled`;
                        validationBadge.textContent = 'Matched (0.00 diff)';
                        isValidState = true;
                        if (confirmBtn) confirmBtn.disabled = false;
                    } else {
                        validationBar.className = 'validation-alert invalid';
                        if (!title) {
                            validationBar.firstElementChild.textContent = 'Please enter a description for this transaction';
                            validationBadge.textContent = 'Description required';
                        } else if (!splitValid) {
                            if (currentSplitType === 'PERCENTAGE') {
                                const pctDiff = 100 - totalPct;
                                if (pctDiff > 0.001) {
                                    validationBar.firstElementChild.textContent = `Allocated ${totalPct.toFixed(1)}% of 100% (${Formatters.formatCurrency(allocatedSumCents, currency)} of ${Formatters.formatCurrency(totalCents, currency)})`;
                                    validationBadge.textContent = `${pctDiff.toFixed(1)}% remaining (${Formatters.formatCurrency(diffCents, currency)})`;
                                } else {
                                    validationBar.firstElementChild.textContent = `Allocated ${totalPct.toFixed(1)}% exceeds 100% (${Formatters.formatCurrency(allocatedSumCents, currency)} of ${Formatters.formatCurrency(totalCents, currency)})`;
                                    validationBadge.textContent = `${Math.abs(pctDiff).toFixed(1)}% over (${Formatters.formatCurrency(Math.abs(diffCents), currency)})`;
                                }
                            } else if (currentSplitType === 'SHARES') {
                                if (totalShares <= 0) {
                                    validationBar.firstElementChild.textContent = 'Please allocate at least 1 share across members';
                                    validationBadge.textContent = '0 shares assigned';
                                } else {
                                    validationBar.firstElementChild.textContent = `Allocated across ${totalShares} share${totalShares === 1 ? '' : 's'}`;
                                    validationBadge.textContent = `${totalShares} shares total`;
                                }
                            } else {
                                if (diffCents > 0) {
                                    validationBar.firstElementChild.textContent = `Allocated ${Formatters.formatCurrency(allocatedSumCents, currency)} of ${Formatters.formatCurrency(totalCents, currency)}`;
                                    validationBadge.textContent = `${Formatters.formatCurrency(diffCents, currency)} remaining`;
                                } else {
                                    validationBar.firstElementChild.textContent = `Allocated ${Formatters.formatCurrency(allocatedSumCents, currency)} exceeds total ${Formatters.formatCurrency(totalCents, currency)}`;
                                    validationBadge.textContent = `${Formatters.formatCurrency(Math.abs(diffCents), currency)} over`;
                                }
                            }
                        } else if (!payerValid) {
                            validationBar.firstElementChild.textContent = 'Total paid amounts do not equal transaction total';
                            validationBadge.textContent = 'Payer mismatch';
                        }
                        isValidState = false;
                        if (confirmBtn) confirmBtn.disabled = true;
                    }

                    // 4. Update Live "What-If" Projected Balance Impact Preview
                    if (splitValid && payerValid && totalCents > 0) {
                        const currentBalances = store.getState()?.balances || [];
                        const projectedBalances = ExpenseModal.calculateProjectedBalances({
                            members,
                            currentBalances,
                            calculatedSplits,
                            paidByMap,
                            expenseToEdit,
                        });

                        const chipsHtml = ExpenseModal.renderProjectedChips(projectedBalances, currency);
                        if (whatIfChipsList && whatIfContainer) {
                            if (chipsHtml) {
                                whatIfChipsList.innerHTML = chipsHtml;
                                whatIfContainer.style.display = 'block';
                            } else {
                                whatIfContainer.style.display = 'none';
                            }
                        }
                    }

                    // 5. Update Level 1 Progressive Split Indicator Card
                    if (progressiveSplitHeadline && progressiveSplitSubtext) {
                        if (currentSplitType === 'EQUAL') {
                            const checkedCount = Array.from(participantChecks).filter(c => c.checked).length;
                            progressiveSplitHeadline.textContent = `Equal Split · ${checkedCount} of ${members.length} Member${members.length === 1 ? '' : 's'}`;
                            if (totalCents > 0 && checkedCount > 0) {
                                const perPerson = Math.floor(totalCents / checkedCount);
                                progressiveSplitSubtext.textContent = `${Formatters.formatCurrency(perPerson, currency)} / person`;
                            } else {
                                progressiveSplitSubtext.textContent = 'Enter amount to calculate share';
                            }
                        } else {
                            const typeLabel = currentSplitType.charAt(0) + currentSplitType.slice(1).toLowerCase();
                            const activeCount = Object.keys(calculatedSplits).length;
                            progressiveSplitHeadline.textContent = `${typeLabel} Split · ${activeCount} Participant${activeCount === 1 ? '' : 's'}`;
                            progressiveSplitSubtext.textContent = `Total: ${Formatters.formatCurrency(totalCents, currency)}`;
                        }
                    }
                }

                // Event Listeners for Live Keystrokes
                titleInput.addEventListener('input', recalculate);
                amountInput.addEventListener('input', recalculate);
                dateInput.addEventListener('change', recalculate);
                payerSelect.addEventListener('change', recalculate);

                participantChecks.forEach((chk) => {
                    chk.addEventListener('change', (e) => {
                        const row = e.target.closest('.participant-row');
                        const customInput = row ? row.querySelector('.split-custom-input') : null;
                        if (!e.target.checked && customInput) {
                            customInput.value = '0';
                        }
                        recalculate();
                    });
                });

                customSplitInputs.forEach((inp) => {
                    inp.addEventListener('input', recalculate);
                });

                multiPayerInputs.forEach((inp) => {
                    inp.addEventListener('input', recalculate);
                });

                // Custom Category Trigger
                const addCategoryBtn = overlay.querySelector('#btn-modal-add-category');
                if (addCategoryBtn) {
                    addCategoryBtn.addEventListener('click', () => {
                        ExpenseModal.openCustomCategoryModal({
                            token,
                            onCreated: (newCat) => {
                                const catSelect = overlay.querySelector('#modal-expense-category');
                                if (catSelect) {
                                    const opt = document.createElement('option');
                                    opt.value = newCat.id;
                                    opt.textContent = `${newCat.icon} ${newCat.name} ★`;
                                    opt.selected = true;
                                    catSelect.appendChild(opt);
                                }
                            }
                        });
                    });
                }

                // Receipt Dropzone & File Attachment Listener
                pendingReceipt = null;
                const dropzone = overlay.querySelector('#receipt-upload-dropzone');
                const fileInput = overlay.querySelector('#modal-receipt-file-input');
                const promptEl = overlay.querySelector('#receipt-dropzone-prompt');
                const previewEl = overlay.querySelector('#receipt-preview-container');
                const thumbImg = overlay.querySelector('#receipt-thumb-img');
                const pdfIcon = overlay.querySelector('#receipt-pdf-icon');
                const labelEl = overlay.querySelector('#receipt-file-label');
                const sizeEl = overlay.querySelector('#receipt-file-size');
                const removeBtn = overlay.querySelector('#btn-remove-receipt');

                if (dropzone && fileInput) {
                    dropzone.addEventListener('click', (e) => {
                        if (e.target.closest('#btn-remove-receipt')) return;
                        fileInput.click();
                    });

                    dropzone.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        dropzone.style.borderColor = 'var(--brand-primary)';
                        dropzone.style.background = 'rgba(2, 132, 199, 0.08)';
                    });

                    dropzone.addEventListener('dragleave', () => {
                        dropzone.style.borderColor = 'var(--border-color)';
                        dropzone.style.background = 'var(--surface-secondary)';
                    });

                    dropzone.addEventListener('drop', (e) => {
                        e.preventDefault();
                        dropzone.style.borderColor = 'var(--border-color)';
                        dropzone.style.background = 'var(--surface-secondary)';
                        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                            handleReceiptFile(e.dataTransfer.files[0]);
                        }
                    });

                    fileInput.addEventListener('change', (e) => {
                        if (e.target.files && e.target.files[0]) {
                            handleReceiptFile(e.target.files[0]);
                        }
                    });

                    if (removeBtn) {
                        removeBtn.addEventListener('click', () => {
                            pendingReceipt = null;
                            fileInput.value = '';
                            if (promptEl) promptEl.style.display = 'flex';
                            if (previewEl) previewEl.style.display = 'none';
                        });
                    }

                    function handleReceiptFile(file) {
                        if (file.size > 5 * 1024 * 1024) {
                            Toast.show('File exceeds 5MB limit.', 'error');
                            return;
                        }

                        const isImg = file.type.startsWith('image/');
                        const isPdf = file.type === 'application/pdf';

                        if (!isImg && !isPdf) {
                            Toast.show('Only JPG, PNG, WebP, and PDF files are supported.', 'error');
                            return;
                        }

                        const reader = new FileReader();
                        reader.onload = (ev) => {
                            pendingReceipt = {
                                fileName: file.name,
                                base64: ev.target.result,
                                size: file.size,
                                isImage: isImg,
                            };

                            if (promptEl) promptEl.style.display = 'none';
                            if (previewEl) previewEl.style.display = 'flex';
                            if (labelEl) labelEl.textContent = file.name;
                            if (sizeEl) sizeEl.textContent = (file.size / 1024).toFixed(1) + ' KB';

                            if (isImg && thumbImg) {
                                thumbImg.src = ev.target.result;
                                thumbImg.style.display = 'block';
                                if (pdfIcon) pdfIcon.style.display = 'none';
                            } else if (pdfIcon) {
                                pdfIcon.style.display = 'inline-block';
                                if (thumbImg) thumbImg.style.display = 'none';
                            }
                        };
                        reader.readAsDataURL(file);
                    }
                }

                // Initial setup and trigger
                updateSplitInputsUI();
                recalculate();
            },
            onConfirm: async () => {
                const overlay = Modal.getMountPoint();
                const title = overlay.querySelector('#modal-expense-title').value.trim();
                const categoryId = Number(overlay.querySelector('#modal-expense-category').value) || 1;
                const expenseDate = overlay.querySelector('#modal-expense-date').value;
                const notes = overlay.querySelector('#modal-expense-notes').value.trim();

                const rawAmountCents = MathUtils.toCents(overlay.querySelector('#modal-expense-amount').value);
                const chosenCurrency = overlay.querySelector('#modal-expense-currency')?.value || currency;
                const chosenRate = parseFloat(overlay.querySelector('#modal-fx-rate')?.value) || currentRate;

                let effectiveBaseTotalCents = rawAmountCents;
                let originalCurrencyPayload = null;
                let originalAmountPayload = null;
                let exchangeRatePayload = null;

                if (chosenCurrency !== currency) {
                    originalCurrencyPayload = chosenCurrency;
                    originalAmountPayload = rawAmountCents;
                    exchangeRatePayload = chosenRate;
                    effectiveBaseTotalCents = Math.round(rawAmountCents * chosenRate);
                }

                const totalCents = effectiveBaseTotalCents;

                const saveTemplateCheck = overlay.querySelector('#modal-save-template-check');
                const isSaveTemplate = saveTemplateCheck && saveTemplateCheck.checked;

                const recurringCheck = overlay.querySelector('#modal-recurring-check');
                const isRecurring = recurringCheck && recurringCheck.checked;

                if (!title || totalCents <= 0) {
                    Toast.show('Please fill in valid expense details.', 'error');
                    throw new Error('Validation failed');
                }

                // Compile Payers Array
                const payers = [];
                if (isMultiPayer) {
                    const multiInputs = overlay.querySelectorAll('.multi-payer-input');
                    multiInputs.forEach((inp) => {
                        const cents = MathUtils.toCents(inp.value);
                        if (cents > 0) {
                            payers.push({
                                member_id: Number(inp.dataset.memberId),
                                amount_paid_cents: cents,
                            });
                        }
                    });
                } else {
                    const singlePayerId = Number(overlay.querySelector('#modal-payer-select').value);
                    payers.push({
                        member_id: singlePayerId,
                        amount_paid_cents: totalCents,
                    });
                }

                // Compile Splits & Items Array
                const splits = [];
                let itemsPayload = [];
                let customInputsMap = {};
                let taxCents = 0;
                let tipCents = 0;
                let discountCents = 0;

                if (currentSplitType === 'ITEMIZED') {
                    taxCents = MathUtils.toCents(overlay.querySelector('#itemized-tax-input').value);
                    tipCents = MathUtils.toCents(overlay.querySelector('#itemized-tip-input').value);
                    discountCents = MathUtils.toCents(overlay.querySelector('#itemized-discount-input').value);

                    itemsPayload = itemizedState.map((it, idx) => {
                        const itCents = MathUtils.toCents(it.amount);
                        return {
                            name: (it.name || '').trim() || `Item ${idx + 1}`,
                            amount_cents: itCents,
                            sort_order: idx,
                            member_ids: it.member_ids || [],
                        };
                    }).filter(it => it.amount_cents > 0);

                    const itemizedCalc = MathUtils.calculateItemized(itemsPayload, taxCents, tipCents, discountCents);
                    for (const [memberId, owedCents] of Object.entries(itemizedCalc.splits)) {
                        if (owedCents > 0) {
                            splits.push({
                                member_id: Number(memberId),
                                amount_owed_cents: owedCents,
                            });
                        }
                    }
                } else {
                    const participantChecks = overlay.querySelectorAll('.participant-check:checked');
                    const checkedIds = Array.from(participantChecks).map((c) => Number(c.dataset.memberId));

                    let calculatedSplits = {};
                    customInputsMap = {};
                    if (currentSplitType === 'EQUAL') {
                        calculatedSplits = MathUtils.calculateEqual(totalCents, checkedIds);
                    } else if (currentSplitType === 'EXACT') {
                        const exactMap = {};
                        checkedIds.forEach((id) => {
                            const inp = overlay.querySelector(`.split-custom-input[data-member-id="${id}"]`);
                            exactMap[id] = inp ? inp.value : 0;
                        });
                        customInputsMap = exactMap;
                        calculatedSplits = MathUtils.calculateExact(totalCents, exactMap).splits;
                    } else if (currentSplitType === 'PERCENTAGE') {
                        const pctMap = {};
                        checkedIds.forEach((id) => {
                            const inp = overlay.querySelector(`.split-custom-input[data-member-id="${id}"]`);
                            pctMap[id] = parseFloat(inp ? inp.value : 0) || 0;
                        });
                        customInputsMap = pctMap;
                        calculatedSplits = MathUtils.calculatePercentage(totalCents, pctMap);
                    } else if (currentSplitType === 'SHARES') {
                        const sharesMap = {};
                        checkedIds.forEach((id) => {
                            const inp = overlay.querySelector(`.split-custom-input[data-member-id="${id}"]`);
                            sharesMap[id] = parseFloat(inp ? inp.value : 0) || 0;
                        });
                        customInputsMap = sharesMap;
                        calculatedSplits = MathUtils.calculateShares(totalCents, sharesMap);
                    }

                    for (const [memberId, owedCents] of Object.entries(calculatedSplits)) {
                        if (owedCents > 0 || currentSplitType === 'EXACT') {
                            const splitObj = {
                                member_id: Number(memberId),
                                amount_owed_cents: owedCents,
                            };
                            if (currentSplitType === 'PERCENTAGE') {
                                splitObj.split_value = customInputsMap[memberId] !== undefined ? customInputsMap[memberId] : null;
                                splitObj.percentage = splitObj.split_value;
                                splitObj.value = splitObj.split_value;
                            } else if (currentSplitType === 'SHARES') {
                                splitObj.split_value = customInputsMap[memberId] !== undefined ? customInputsMap[memberId] : null;
                                splitObj.shares = splitObj.split_value;
                                splitObj.value = splitObj.split_value;
                            }
                            splits.push(splitObj);
                        }
                    }
                }

                const payload = {
                    title,
                    category_id: categoryId,
                    amount_cents: totalCents,
                    total_amount_cents: totalCents,
                    original_currency_code: originalCurrencyPayload,
                    original_currency: originalCurrencyPayload,
                    original_amount_cents: originalAmountPayload,
                    exchange_rate: exchangeRatePayload,
                    expense_date: expenseDate,
                    split_type: currentSplitType,
                    payers,
                    splits,
                    notes: notes || null,
                    tax_cents: taxCents,
                    tip_cents: tipCents,
                    discount_cents: discountCents,
                    items: itemsPayload,
                };

                if (currentSplitType === 'PERCENTAGE' && Object.keys(customInputsMap).length > 0) {
                    payload.percentages = customInputsMap;
                } else if (currentSplitType === 'SHARES' && Object.keys(customInputsMap).length > 0) {
                    payload.shares = customInputsMap;
                } else if (currentSplitType === 'EXACT' && Object.keys(customInputsMap).length > 0) {
                    payload.exact_amounts = customInputsMap;
                }

                if (pendingReceipt) {
                    payload.receipt_base64 = pendingReceipt.base64;
                    payload.receipt_file_name = pendingReceipt.fileName;
                }

                try {
                    if (typeof navigator !== 'undefined' && !navigator.onLine) {
                        if (isEditing) {
                            offlineManager.enqueue({ action: 'UPDATE_EXPENSE', token, entityId: expenseToEdit.id, payload });
                            Toast.info('Updated transaction queued offline. Will sync automatically when connected.');
                        } else {
                            offlineManager.enqueue({ action: 'CREATE_EXPENSE', token, payload });
                            Toast.info('Transaction queued offline. Will sync automatically when connected.');
                        }
                        if (typeof onSuccess === 'function') {
                            Promise.resolve().then(() => onSuccess()).catch(() => {});
                        }
                        return;
                    }

                    if (isEditing) {
                        await api.updateExpense(token, expenseToEdit.id, payload);
                        Toast.show('Transaction updated successfully.', 'success');
                    } else {
                        if (!submissionId) {
                            Toast.show('Secure cryptographic randomness is unavailable in this environment. Transaction submission prevented for security.', 'error');
                            return;
                        }
                        await api.createExpense(token, payload, submissionId);
                        Toast.show('Transaction logged successfully.', 'success');

                        // Optionally save as template
                        if (isSaveTemplate) {
                            try {
                                await api.createTemplate(token, payload);
                                Toast.show('Template preset saved for workspace.', 'info');
                            } catch (_) {}
                        }

                        // Optionally schedule as recurring rule
                        if (isRecurring) {
                            try {
                                const frequency = overlay.querySelector('#modal-recurring-frequency').value || 'MONTHLY';
                                const endDate = overlay.querySelector('#modal-recurring-end-date').value || null;
                                await api.createRecurringRule(token, {
                                    ...payload,
                                    frequency,
                                    next_run_date: expenseDate,
                                    end_date: endDate,
                                    total_amount_cents: payload.total_amount_cents || payload.amount_cents,
                                });
                                Toast.show(`Recurring rule scheduled (${frequency}).`, 'info');
                            } catch (_) {}
                        }
                    }
                    if (typeof onSuccess === 'function') {
                        Promise.resolve().then(() => onSuccess()).catch(() => {});
                    }
                } catch (err) {
                    if (err.code === 'NETWORK_OFFLINE' || (typeof navigator !== 'undefined' && !navigator.onLine)) {
                        if (isEditing) {
                            offlineManager.enqueue({ action: 'UPDATE_EXPENSE', token, entityId: expenseToEdit.id, payload });
                            Toast.info('Updated transaction queued offline. Will sync automatically when connected.');
                        } else {
                            offlineManager.enqueue({ action: 'CREATE_EXPENSE', token, payload });
                            Toast.info('Transaction queued offline. Will sync automatically when connected.');
                        }
                        if (typeof onSuccess === 'function') {
                            Promise.resolve().then(() => onSuccess()).catch(() => {});
                        }
                        return;
                    }
                    Toast.show(err.message || 'Failed to save expense.', 'error');
                    throw err;
                }
            },
        });
    }

    /**
     * Open Modal to create a new workspace-level Custom Category.
     * @param {Object} options
     * @param {string} options.token
     * @param {Function} [options.onCreated]
     */
    static openCustomCategoryModal({ token, onCreated = null }) {
        const popularEmojis = ['🐶', '🏋️', '📚', '☕', '🎮', '🏥', '🎁', '👶', '🏖️', '💻', '🚕', '🍻', '👕', '🍕', '🎵', '🎬', '💊', '🎂', '🔧', '🌿'];
        const popularColors = ['#0284c7', '#16a34a', '#ea580c', '#9333ea', '#e11d48', '#ca8a04', '#0d9488', '#475569'];

        let selectedEmoji = '🏷️';
        let selectedColor = '#0284c7';

        const content = document.createElement('div');
        content.innerHTML = `
            <form id="custom-category-form" style="display: flex; flex-direction: column; gap: var(--space-3);" autocomplete="off">
                <div>
                    <label class="form-label" for="custom-category-name">Category Name *</label>
                    <input type="text" id="custom-category-name" class="form-input" placeholder="e.g. Pet Care, Concerts, Fitness" required autofocus>
                </div>

                <div>
                    <label class="form-label">Category Icon / Emoji</label>
                    <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: 6px;">
                        <span id="selected-emoji-display" style="font-size: 1.5rem; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-sm);">${selectedEmoji}</span>
                        <input type="text" id="custom-category-emoji-input" class="form-input" placeholder="Custom emoji" value="${selectedEmoji}" style="max-width: 140px; font-size: 0.85rem;">
                    </div>
                    <div class="emoji-picker-grid">
                        ${popularEmojis.map(em => `
                            <button type="button" class="emoji-chip ${em === selectedEmoji ? 'is-selected' : ''}" data-emoji="${em}">
                                ${em}
                            </button>
                        `).join('')}
                    </div>
                </div>

                <div>
                    <label class="form-label">Color Swatch</label>
                    <div class="color-swatch-grid">
                        ${popularColors.map(col => `
                            <div class="color-swatch ${col === selectedColor ? 'is-selected' : ''}" data-color="${col}" style="background-color: ${col};"></div>
                        `).join('')}
                    </div>
                </div>
            </form>
        `;

        Modal.open({
            title: '🏷️ Create Custom Category',
            content,
            confirmText: 'Save Category',
            confirmClass: 'btn-primary',
            onMount: (overlay) => {
                const nameInput = overlay.querySelector('#custom-category-name');
                const emojiInput = overlay.querySelector('#custom-category-emoji-input');
                const emojiDisplay = overlay.querySelector('#selected-emoji-display');

                overlay.querySelectorAll('.emoji-chip').forEach(chip => {
                    chip.addEventListener('click', () => {
                        overlay.querySelectorAll('.emoji-chip').forEach(c => c.classList.remove('is-selected'));
                        chip.classList.add('is-selected');
                        selectedEmoji = chip.dataset.emoji;
                        emojiInput.value = selectedEmoji;
                        emojiDisplay.textContent = selectedEmoji;
                    });
                });

                emojiInput.addEventListener('input', (e) => {
                    selectedEmoji = e.target.value.trim() || '🏷️';
                    emojiDisplay.textContent = selectedEmoji;
                });

                overlay.querySelectorAll('.color-swatch').forEach(swatch => {
                    swatch.addEventListener('click', () => {
                        overlay.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('is-selected'));
                        swatch.classList.add('is-selected');
                        selectedColor = swatch.dataset.color;
                    });
                });
            },
            onConfirm: async () => {
                const overlay = Modal.getMountPoint();
                const nameInput = overlay.querySelector('#custom-category-name');
                const name = nameInput ? nameInput.value.trim() : '';

                if (!name) {
                    Toast.show('Please enter a category name.', 'warning');
                    throw new Error('Category name required');
                }

                try {
                    const res = await api.createCategory(token, {
                        name,
                        icon: selectedEmoji,
                        color_hex: selectedColor,
                    });
                    const newCategory = res?.data?.category;
                    if (newCategory && ExpenseModal._cachedCategories) {
                        ExpenseModal._cachedCategories.push(newCategory);
                    }
                    Toast.show(`Category "${name}" created!`, 'success');
                    if (typeof onCreated === 'function' && newCategory) {
                        onCreated(newCategory);
                    }
                } catch (err) {
                    Toast.show(err.message || 'Failed to create category.', 'error');
                    throw err;
                }
            }
        });
    }
}
