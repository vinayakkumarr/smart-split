/**
 * Automated Verification Script for Smart Split Trash Bin & 1-Click Restore Feature
 */

import assert from 'node:assert/strict';

// Mock Browser DOM Environment
class MockElement {
    constructor(tagName = 'div') {
        this.tagName = tagName;
        this.className = '';
        this.id = '';
        this.style = {};
        this._innerHTML = '';
        this.dataset = {};
        this.children = [];
        this.listeners = {};
        this.classList = {
            add: (c) => {},
            remove: (c) => {},
            contains: (c) => false
        };
    }

    get innerHTML() {
        return this._innerHTML;
    }

    set innerHTML(val) {
        this._innerHTML = val;
    }

    addEventListener(event, handler) {
        if (!this.listeners[event]) this.listeners[event] = [];
        this.listeners[event].push(handler);
    }

    appendChild(child) {
        this.children.push(child);
    }

    remove() {}
    focus() {}
    select() {}

    querySelector(selector) {
        if (selector === '#modal-overlay') return mockOverlay;
        if (selector === '#trash-modal-container') return mockOverlay;
        if (selector === '.modal-title') return { textContent: '' };
        if (selector === '.modal-close' || selector === '.modal-btn-cancel' || selector === '.modal-btn-confirm' || selector === '.toast-close') {
            return { addEventListener: () => {} };
        }
        if (selector === '#btn-view-trash-bin') {
            return { addEventListener: () => {} };
        }
        return new MockElement();
    }

    querySelectorAll(selector) {
        if (selector === '.btn-restore-item') {
            return [
                {
                    dataset: { expenseId: '101' },
                    disabled: false,
                    textContent: 'Restore',
                    addEventListener: (evt, fn) => {
                        mockOverlay.restoreHandler = fn;
                    }
                }
            ];
        }
        return [];
    }
}

const mockOverlay = new MockElement('div');
mockOverlay.id = 'modal-overlay';

global.HTMLElement = MockElement;

global.document = {
    activeElement: null,
    body: {
        appendChild: () => {},
        style: {}
    },
    getElementById: (id) => {
        if (id === 'modal-overlay') return mockOverlay;
        return null;
    },
    createElement: (tag) => {
        return mockOverlay;
    }
};

global.window = {
    location: { href: 'http://localhost:8000/#/groups/trash_test_token' },
    navigator: { onLine: true, clipboard: { writeText: async () => {} } },
    addEventListener: () => {},
    removeEventListener: () => {}
};

let lastFetchUrl = '';
let lastFetchOptions = {};
global.fetch = async (url, options = {}) => {
    lastFetchUrl = url;
    lastFetchOptions = options;

    if (url.includes('/expenses/trash')) {
        return {
            ok: true,
            json: async () => ({
                success: true,
                data: {
                    expenses: [
                        {
                            id: 101,
                            group_id: 1,
                            title: 'Flight Tickets',
                            total_amount_cents: 850000,
                            expense_date: '2026-09-18',
                            category: { name: 'Travel & Transport' },
                            payers: [{ member_name: 'Rahul' }],
                            splits: [{ member_name: 'Rahul' }, { member_name: 'Priya' }],
                            is_deleted: true,
                            notes: 'Round-trip flights'
                        },
                        {
                            id: 102,
                            group_id: 1,
                            title: 'Coffee & Snacks',
                            total_amount_cents: 45000,
                            expense_date: '2026-09-19',
                            category: { name: 'Food & Dining' },
                            payers: [{ member_name: 'Priya' }],
                            splits: [{ member_name: 'Rahul' }, { member_name: 'Priya' }],
                            is_deleted: true,
                            notes: null
                        }
                    ],
                    total_count: 2
                }
            })
        };
    }

    if (url.includes('/restore') && options.method === 'PUT') {
        return {
            ok: true,
            json: async () => ({
                success: true,
                data: {
                    restored: true,
                    expense_id: 101
                }
            })
        };
    }

    return {
        ok: true,
        json: async () => ({ success: true, data: {} })
    };
};

console.log('--- TEST 1: Testing ApiClient Trash & Restore Methods ---');
const { api } = await import('../public/assets/js/api.js');
assert(typeof api.getTrashExpenses === 'function', 'api.getTrashExpenses should exist');
assert(typeof api.restoreExpense === 'function', 'api.restoreExpense should exist');

const trashData = await api.getTrashExpenses('my_token_123');
assert.equal(lastFetchUrl, '/api/groups/my_token_123/expenses/trash');
assert.equal(trashData.data.total_count, 2);
console.log('✔ api.getTrashExpenses endpoint and payload verified');

const restoreData = await api.restoreExpense('my_token_123', 101);
assert.equal(lastFetchUrl, '/api/groups/my_token_123/expenses/101/restore');
assert.equal(lastFetchOptions.method, 'PUT');
assert.equal(restoreData.data.restored, true);
console.log('✔ api.restoreExpense PUT endpoint and response verified');

console.log('\n--- TEST 2: Testing ExpenseList Component Toolbar & Markup ---');
const { ExpenseList } = await import('../public/assets/js/components/ExpenseList.js');
assert(typeof ExpenseList.openTrashModal === 'function', 'ExpenseList.openTrashModal should be a static method');

const container = {
    innerHTML: '',
    querySelector: function(sel) {
        if (sel === '#btn-view-trash-bin') return { addEventListener: () => {} };
        return null;
    },
    querySelectorAll: () => []
};

ExpenseList.render(container, {
    token: 'test_token',
    expenses: [],
    members: [{ id: 1, name: 'Alice' }],
    currency: 'INR'
});

assert(container.innerHTML.includes('id="btn-view-trash-bin"'), 'Toolbar must include #btn-view-trash-bin button');
assert(container.innerHTML.includes('Trash Bin'), 'Toolbar button must be labeled Trash Bin');
console.log('✔ ExpenseList ledger toolbar includes Trash Bin trigger button');

console.log('\n--- TEST 3: Testing ExpenseList.openTrashModal Flow ---');
let restoreCallbackFired = false;
await ExpenseList.openTrashModal({
    token: 'test_token',
    currency: 'INR',
    onRestore: () => { restoreCallbackFired = true; }
});

assert(mockOverlay.innerHTML.includes('Flight Tickets'), 'Modal content must render deleted expense title');
assert(mockOverlay.innerHTML.includes('8,500.00'), 'Modal content must format deleted expense amount');
assert(mockOverlay.innerHTML.includes('btn-restore-item'), 'Modal content must contain .btn-restore-item buttons');
assert(mockOverlay.innerHTML.includes('data-expense-id="101"'), 'Modal item must have expense ID data attribute');

// Trigger restore button handler
assert(typeof mockOverlay.restoreHandler === 'function', 'Restore button listener must be attached');
await mockOverlay.restoreHandler();
assert.equal(restoreCallbackFired, true, 'onRestore callback must fire upon restoring transaction');
console.log('✔ openTrashModal correctly renders deleted items table, triggers PUT restore, and invokes callback');

console.log('\n=========================================');
console.log('🎉 ALL CLIENT TRASH & RESTORE TESTS PASSED! 🎉');
console.log('=========================================');
