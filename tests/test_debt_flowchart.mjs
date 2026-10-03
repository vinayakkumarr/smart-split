import assert from 'node:assert';
import { SettlementPlan } from '../public/assets/js/components/SettlementPlan.js';
import * as Formatters from '../public/assets/js/utils/formatters.js';

console.log('🧪 Testing SettlementPlan Interactive SVG Debt Flowchart Diagram...\n');

// -------------------------------------------------------------
// Test 1: SettlementPlan.buildDebtNodes - Node Aggregations
// -------------------------------------------------------------
{
    const transactions = [
        {
            from_member_id: 1,
            from_name: 'Rahul Sharma',
            to_member_id: 2,
            to_name: 'Priya Patel',
            amount_cents: 50000,
            amount_formatted: '₹500.00'
        },
        {
            from_member_id: 1,
            from_name: 'Rahul Sharma',
            to_member_id: 3,
            to_name: 'Amit Verma',
            amount_cents: 30000,
            amount_formatted: '₹300.00'
        },
        {
            from_member_id: 4,
            from_name: 'Sneha Rao',
            to_member_id: 2,
            to_name: 'Priya Patel',
            amount_cents: 20000,
            amount_formatted: '₹200.00'
        }
    ];

    const { debtors, creditors } = SettlementPlan.buildDebtNodes(transactions);

    assert.strictEqual(debtors.length, 2, 'Should aggregate into 2 distinct debtors');
    assert.strictEqual(creditors.length, 2, 'Should aggregate into 2 distinct creditors');

    // Rahul Sharma: 50000 + 30000 = 80000
    const rahul = debtors.find(d => d.name === 'Rahul Sharma');
    assert.ok(rahul, 'Rahul found in debtors');
    assert.strictEqual(rahul.totalOutgoingCents, 80000);

    // Sneha Rao: 20000
    const sneha = debtors.find(d => d.name === 'Sneha Rao');
    assert.ok(sneha, 'Sneha found in debtors');
    assert.strictEqual(sneha.totalOutgoingCents, 20000);

    // Priya Patel: 50000 + 20000 = 70000
    const priya = creditors.find(c => c.name === 'Priya Patel');
    assert.ok(priya, 'Priya found in creditors');
    assert.strictEqual(priya.totalIncomingCents, 70000);

    // Amit Verma: 30000
    const amit = creditors.find(c => c.name === 'Amit Verma');
    assert.ok(amit, 'Amit found in creditors');
    assert.strictEqual(amit.totalIncomingCents, 30000);

    console.log('✅ Test 1 Passed: buildDebtNodes aggregates outgoing/incoming totals accurately.');
}

// -------------------------------------------------------------
// Test 2: SettlementPlan.renderFlowDiagramSvg - Empty State
// -------------------------------------------------------------
{
    const emptySvgHtml = SettlementPlan.renderFlowDiagramSvg({ transactions: [] });
    assert.ok(emptySvgHtml.includes('empty-state'), 'Includes empty-state container');
    assert.ok(emptySvgHtml.includes('No transfers required. Everyone is settled.'), 'Shows settled message');

    console.log('✅ Test 2 Passed: renderFlowDiagramSvg handles zero debt empty state.');
}

// -------------------------------------------------------------
// Test 3: SettlementPlan.renderFlowDiagramSvg - SVG Geometry & Attributes
// -------------------------------------------------------------
{
    const transactions = [
        {
            from_member_id: 10,
            from_name: 'Vikram',
            to_member_id: 20,
            to_name: 'Ananya',
            amount_cents: 75000,
            amount_formatted: '₹750.00'
        }
    ];

    const svgHtml = SettlementPlan.renderFlowDiagramSvg({ transactions, currency: 'INR' });

    // Check SVG wrapper & viewBox
    assert.ok(svgHtml.includes('<svg viewBox="0 0 760'), 'SVG contains responsive viewBox');
    assert.ok(svgHtml.includes('class="debt-flow-svg"'), 'SVG contains class');
    assert.ok(svgHtml.includes('marker id="debt-arrow-marker"'), 'Defs contain arrow marker');
    assert.ok(svgHtml.includes('marker id="debt-arrow-marker-active"'), 'Defs contain active arrow marker');

    // Check Debtor node
    assert.ok(svgHtml.includes('class="flow-node flow-debtor-node"'), 'Contains debtor node');
    assert.ok(svgHtml.includes('Pays ₹750.00'), 'Debtor node displays Pays total');
    assert.ok(svgHtml.includes('VI'), 'Debtor initials displayed');

    // Check Creditor node
    assert.ok(svgHtml.includes('class="flow-node flow-creditor-node"'), 'Contains creditor node');
    assert.ok(svgHtml.includes('Receives ₹750.00'), 'Creditor node displays Receives total');
    assert.ok(svgHtml.includes('AN'), 'Creditor initials displayed');

    // Check Connecting Flow Edge
    assert.ok(svgHtml.includes('class="flow-edge"'), 'Contains flow-edge group');
    assert.ok(svgHtml.includes('role="button"'), 'Flow edge has role=button');
    assert.ok(svgHtml.includes('tabindex="0"'), 'Flow edge is keyboard focusable');
    assert.ok(svgHtml.includes('aria-label="Transfer ₹750.00 from Vikram to Ananya. Click to settle payment."'), 'Flow edge has descriptive aria-label');

    // Check Bezier path & marker-end
    assert.ok(svgHtml.includes('marker-end="url(#debt-arrow-marker)"'), 'Path uses marker-end');
    assert.ok(svgHtml.includes('class="flow-hit-path"'), 'Contains invisible wide hit path');
    assert.ok(svgHtml.includes('class="flow-visible-path"'), 'Contains visible curved line');

    // Check Amount pill
    assert.ok(svgHtml.includes('class="flow-badge-bg"'), 'Badge background rect exists');
    assert.ok(svgHtml.includes('class="flow-badge-text"'), 'Badge text exists');
    assert.ok(svgHtml.includes('₹750.00'), 'Badge displays exact formatted amount');

    // Column Headers
    assert.ok(svgHtml.includes('DEBTORS (OUTGOING)'), 'Contains Debtors header');
    assert.ok(svgHtml.includes('CREDITORS (INCOMING)'), 'Contains Creditors header');
    assert.ok(svgHtml.includes('CLICK ARROW TO SETTLE'), 'Contains interaction prompt');

    console.log('✅ Test 3 Passed: SVG structure, markers, paths, pills, and accessibility verified.');
}

// -------------------------------------------------------------
// Test 4: SettlementPlan.render - DOM Assembly & View Switching
// -------------------------------------------------------------
{
    // Minimal DOM Mock environment
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
            this._parse(val);
        }

        _parse(html) {
            this.children = [];
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

        dispatchEvent(event) {
            const list = this.eventListeners[event.type || event] || [];
            list.forEach(fn => fn(event));
        }

        querySelector(sel) {
            return this.querySelectorAll(sel)[0] || null;
        }

        querySelectorAll(sel) {
            const matches = [];
            const walk = (node) => {
                if (matchesSelector(node, sel)) matches.push(node);
                (node.children || []).forEach(walk);
            };
            (this.children || []).forEach(walk);
            return matches;
        }
    }

    function matchesSelector(node, sel) {
        if (!node) return false;
        if (sel.startsWith('#')) return node.attributes['id'] === sel.slice(1);
        if (sel.startsWith('.')) return node.classList && node.classList.has(sel.slice(1));
        return false;
    }

    const transactions = [
        {
            from_member_id: 1,
            from_name: 'Alice',
            to_member_id: 2,
            to_name: 'Bob',
            amount_cents: 100000,
            amount_formatted: '₹1,000.00'
        }
    ];

    const container = new MockElement('div');
    SettlementPlan.render(container, {
        token: 'test-token',
        plan: { transactions },
        currency: 'INR',
        groupName: 'Test Workspace'
    });

    assert.ok(container.innerHTML.includes('id="settlement-cards-view"'), 'Card View container rendered');
    assert.ok(container.innerHTML.includes('id="settlement-diagram-view"'), 'Diagram View container rendered');
    assert.ok(container.innerHTML.includes('class="settlement-view-tabs"'), 'View tabs rendered');
    assert.ok(container.innerHTML.includes('data-view="cards"'), 'Card tab button exists');
    assert.ok(container.innerHTML.includes('data-view="diagram"'), 'Diagram tab button exists');

    console.log('✅ Test 4 Passed: SettlementPlan.render generates tabs and both view containers.');
}

console.log('\n🎉 All SettlementPlan Debt Flowchart tests passed successfully!');
