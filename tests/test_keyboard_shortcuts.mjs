/**
 * Smart Split – Global Keyboard Shortcuts Test Suite
 */
import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

console.log('=====================================================');
console.log(' Smart Split – Keyboard Shortcuts Verification Suite');
console.log('=====================================================\n');

// 1. Source Code Structural Verification
console.log('--- 1. Testing app.js Shortcut Declarations ---');
const appJs = fs.readFileSync(path.join(rootDir, 'public/assets/js/app.js'), 'utf-8');

assert(appJs.includes("e.key === '/'"), "Must handle '/' shortcut");
assert(appJs.includes("e.key === 's'") || appJs.includes("e.key === 'S'"), "Must handle 's'/'S' shortcut");
assert(appJs.includes("e.key === 'a'") || appJs.includes("e.key === 'A'"), "Must handle 'a'/'A' shortcut");
assert(appJs.includes("e.key === 'Escape'"), "Must handle 'Escape' modal dismissal");
assert(appJs.includes("e.ctrlKey || e.metaKey") && appJs.includes("e.key === 'Enter'"), "Must handle Ctrl+Enter / Cmd+Enter form submission");

assert(appJs.includes("ledger-search-input"), "Search shortcut must focus #ledger-search-input");
assert(appJs.includes("settlement-plan-container"), "Scroll shortcut must target #settlement-plan-container");
assert(appJs.includes("BalanceSummary.openAnalyticsModal"), "Analytics shortcut must call openAnalyticsModal");
assert(appJs.includes("Modal.close()"), "Escape shortcut must call Modal.close()");

console.log('  [PASS] All required keyboard shortcut conditions declared in app.js.');

// 2. Behavioral Simulation Testing
console.log('\n--- 2. Simulating Keyboard Events & Prevention Rules ---');

let eventListeners = [];
let focusedElement = null;
let scrolledElement = null;
let modalClosed = false;
let confirmButtonClicked = false;
let analyticsModalOpened = false;
let addExpenseOpened = false;

// Mock Environment
const mockDom = {
    activeElement: { tagName: 'BODY', isContentEditable: false },
    elements: {
        'ledger-search-input': {
            tagName: 'INPUT',
            focus: () => { focusedElement = 'ledger-search-input'; },
            select: () => {},
        },
        'settlement-plan-container': {
            tagName: 'DIV',
            scrollIntoView: (opt) => { scrolledElement = opt; },
        },
        'modal-overlay': {
            tagName: 'DIV',
            classList: {
                contains: (c) => c === 'active' && mockDom.modalActive,
            },
            querySelector: (sel) => {
                if (sel.includes('modal-btn-confirm')) {
                    return {
                        click: () => { confirmButtonClicked = true; },
                        disabled: false,
                    };
                }
                return null;
            }
        }
    },
    modalActive: false,
};

global.document = {
    getElementById: (id) => mockDom.elements[id] || null,
    querySelector: (sel) => null,
    get activeElement() { return mockDom.activeElement; },
    body: { style: {} }
};

global.window = {
    addEventListener: (type, fn) => {
        eventListeners.push({ type, fn });
    },
    isSecureContext: true,
    location: { href: 'http://localhost:8000/#/g/tok123' },
};

function triggerKeydown(key, { ctrlKey = false, metaKey = false } = {}) {
    let prevented = false;
    const event = {
        key,
        ctrlKey,
        metaKey,
        preventDefault: () => { prevented = true; }
    };
    for (const l of eventListeners) {
        if (l.type === 'keydown') {
            l.fn(event);
        }
    }
    return { prevented };
}

// Reset state
function resetState() {
    focusedElement = null;
    scrolledElement = null;
    modalClosed = false;
    confirmButtonClicked = false;
    analyticsModalOpened = false;
    addExpenseOpened = false;
    mockDom.activeElement = { tagName: 'BODY', isContentEditable: false };
    mockDom.modalActive = false;
}

// Extract and eval setupKeyboardShortcuts function in mock context
const setupFnCode = appJs.match(/let activeExpenseTrigger[\s\S]*?setupKeyboardShortcuts\(onAddExpense, onAddMember\) \{[\s\S]*?\n\}/)[0];

const fn = new Function('Modal', 'BalanceSummary', 'store', `${setupFnCode}; return setupKeyboardShortcuts;`)(
    { close: () => { modalClosed = true; } },
    { openAnalyticsModal: () => { analyticsModalOpened = true; } },
    { getState: () => ({ currentGroup: { invite_token: 'tok123', currency: 'INR' } }) }
);

fn(() => { addExpenseOpened = true; }, () => {});

// Test 2.1: Pressing '/' focuses search input and prevents default
resetState();
const resSlash = triggerKeydown('/');
assert.strictEqual(focusedElement, 'ledger-search-input', "Pressing '/' should focus #ledger-search-input");
assert.strictEqual(resSlash.prevented, true, "Pressing '/' should prevent default slash character insertion");
console.log("  [PASS] '/' focuses #ledger-search-input and calls preventDefault()");

// Test 2.2: Pressing 's' or 'S' smooth-scrolls to settlement container
resetState();
const resS = triggerKeydown('s');
assert(scrolledElement !== null && scrolledElement.behavior === 'smooth', "Pressing 's' should smooth-scroll to settlement plan");
console.log("  [PASS] 's' triggers smooth-scroll to #settlement-plan-container");

// Test 2.3: Pressing 'a' opens analytics modal
resetState();
const resA = triggerKeydown('a');
assert.strictEqual(analyticsModalOpened, true, "Pressing 'a' should open analytics modal");
console.log("  [PASS] 'a' opens Visual Spend Analytics modal");

// Test 2.4: Pressing 'e' opens Add Expense modal
resetState();
const resE = triggerKeydown('e');
assert.strictEqual(addExpenseOpened, true, "Pressing 'e' should trigger Add Expense modal");
console.log("  [PASS] 'e' triggers Add Expense modal");

// Test 2.5: Pressing 'Escape' closes active modal
resetState();
mockDom.modalActive = true;
const resEsc = triggerKeydown('Escape');
assert.strictEqual(modalClosed, true, "Pressing 'Escape' when modal is active should call Modal.close()");
console.log("  [PASS] 'Escape' dismisses active modal");

// Test 2.6: Pressing 'Ctrl + Enter' submits active modal form
resetState();
mockDom.modalActive = true;
mockDom.activeElement = { tagName: 'INPUT', isContentEditable: false };
const resCtrlEnter = triggerKeydown('Enter', { ctrlKey: true });
assert.strictEqual(confirmButtonClicked, true, "Pressing 'Ctrl+Enter' when modal is active should click modal confirm button");
console.log("  [PASS] 'Ctrl + Enter' clicks active modal confirm button");

// Test 2.7: Single-key shortcuts ignored when typing in input/textarea/select
resetState();
mockDom.activeElement = { tagName: 'INPUT', isContentEditable: false };
triggerKeydown('/');
triggerKeydown('s');
triggerKeydown('a');
triggerKeydown('e');
assert.strictEqual(focusedElement, null, "Shortcuts must not trigger when input is focused");
assert.strictEqual(scrolledElement, null, "Shortcuts must not trigger when input is focused");
assert.strictEqual(analyticsModalOpened, false, "Shortcuts must not trigger when input is focused");
assert.strictEqual(addExpenseOpened, false, "Shortcuts must not trigger when input is focused");
console.log("  [PASS] Single-key shortcuts strictly suppressed when typing in form inputs");

// Test 2.8: Single-key shortcuts ignored when modal is open
resetState();
mockDom.modalActive = true;
triggerKeydown('/');
triggerKeydown('s');
triggerKeydown('a');
triggerKeydown('e');
assert.strictEqual(focusedElement, null, "Shortcuts must not trigger when modal is active");
assert.strictEqual(scrolledElement, null, "Shortcuts must not trigger when modal is active");
assert.strictEqual(analyticsModalOpened, false, "Shortcuts must not trigger when modal is active");
assert.strictEqual(addExpenseOpened, false, "Shortcuts must not trigger when modal is active");
console.log("  [PASS] Single-key shortcuts strictly suppressed when modal overlay is active");

console.log('\n=====================================================');
console.log(' ALL KEYBOARD SHORTCUT TESTS PASSED (100%)');
console.log('=====================================================');
