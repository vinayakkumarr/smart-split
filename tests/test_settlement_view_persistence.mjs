import assert from 'node:assert';
import { SettlementPlan } from '../public/assets/js/components/SettlementPlan.js';

console.log('🧪 Testing SettlementPlan View Persistence & Keyboard Navigation...\n');

// -------------------------------------------------------------
// Test 1: getActiveView and setActiveView Invariants
// -------------------------------------------------------------
{
    const token = 'test_token_' + Date.now();
    
    // Default should be 'cards'
    assert.strictEqual(SettlementPlan.getActiveView(token), 'cards', 'Default view must be cards');

    // Setting to diagram
    SettlementPlan.setActiveView(token, 'diagram');
    assert.strictEqual(SettlementPlan.getActiveView(token), 'diagram', 'Active view updated to diagram');

    // Setting back to cards
    SettlementPlan.setActiveView(token, 'cards');
    assert.strictEqual(SettlementPlan.getActiveView(token), 'cards', 'Active view updated back to cards');

    // Invalid values should be ignored
    SettlementPlan.setActiveView(token, 'invalid_view');
    assert.strictEqual(SettlementPlan.getActiveView(token), 'cards', 'Invalid view should be rejected');

    console.log('✅ Test 1 Passed: View state getter/setter and validation work correctly.');
}

// -------------------------------------------------------------
// Test 2: render() respects active view state on re-render
// -------------------------------------------------------------
{
    const token = 'token_persist_' + Date.now();
    const transactions = [
        {
            from_member_id: 1,
            from_name: 'Alice',
            to_member_id: 2,
            to_name: 'Bob',
            amount_cents: 50000,
        }
    ];

    class MockElement {
        constructor(tagName) {
            this.tagName = tagName;
            this.children = [];
            this.attributes = {};
            this.style = {};
            this.classList = new Set();
            this.dataset = {};
            this._innerHTML = '';
            this.eventListeners = {};
        }

        get innerHTML() {
            return this._innerHTML;
        }

        set innerHTML(val) {
            this._innerHTML = val;
        }

        setAttribute(k, v) {
            this.attributes[k] = String(v);
        }

        getAttribute(k) {
            return this.attributes[k] || null;
        }

        addEventListener(event, fn) {
            if (!this.eventListeners[event]) this.eventListeners[event] = [];
            this.eventListeners[event].push(fn);
        }

        querySelector(sel) {
            return null;
        }

        querySelectorAll(sel) {
            return [];
        }
    }

    // First render with default 'cards'
    const container1 = new MockElement('div');
    SettlementPlan.render(container1, { token, plan: { transactions } });
    assert.ok(container1.innerHTML.includes('id="tab-settlement-cards" tabindex="0"'), 'Cards tab is active initially');
    assert.ok(container1.innerHTML.includes('id="settlement-cards-view" class="settlement-view-content" style="display: block;"'), 'Cards view visible');
    assert.ok(container1.innerHTML.includes('id="settlement-diagram-view" class="settlement-view-content" style="display: none;"'), 'Diagram view hidden');

    // User switches view to 'diagram'
    SettlementPlan.setActiveView(token, 'diagram');

    // Re-render (simulating SSE update, mutation, or background poll)
    const container2 = new MockElement('div');
    SettlementPlan.render(container2, { token, plan: { transactions } });
    assert.ok(container2.innerHTML.includes('id="tab-settlement-diagram" tabindex="0"'), 'Diagram tab is active after re-render');
    assert.ok(container2.innerHTML.includes('id="settlement-cards-view" class="settlement-view-content" style="display: none;"'), 'Cards view hidden after re-render');
    assert.ok(container2.innerHTML.includes('id="settlement-diagram-view" class="settlement-view-content" style="display: block;"'), 'Diagram view visible after re-render');

    console.log('✅ Test 2 Passed: render() preserves active view state across re-renders.');
}

console.log('\n🎉 All Settlement View Persistence tests passed successfully!');
