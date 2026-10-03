import assert from 'node:assert';
import { renderWithHashtags, escapeHtml } from '../public/assets/js/utils/formatters.js';
import { ExpenseList } from '../public/assets/js/components/ExpenseList.js';

console.log('🧪 Testing Hashtag (#tag) Support in Smart Split...\n');

// -------------------------------------------------------------
// Test 1: renderWithHashtags - Core Formatting & Badges
// -------------------------------------------------------------
{
    // Single hashtag
    const res1 = renderWithHashtags('Team Dinner #food');
    assert.strictEqual(
        res1,
        'Team Dinner <span class="badge badge-tag" data-tag="food">#food</span>'
    );

    // Multiple hashtags
    const res2 = renderWithHashtags('Beach shack dinner #vacation #goa2026 #dinner_party');
    assert.ok(res2.includes('<span class="badge badge-tag" data-tag="vacation">#vacation</span>'));
    assert.ok(res2.includes('<span class="badge badge-tag" data-tag="goa2026">#goa2026</span>'));
    assert.ok(res2.includes('<span class="badge badge-tag" data-tag="dinner_party">#dinner_party</span>'));

    // Hyphenated tag
    const res3 = renderWithHashtags('Hotel booking #trip-2026');
    assert.ok(res3.includes('<span class="badge badge-tag" data-tag="trip-2026">#trip-2026</span>'));

    // Plain text without tags
    const res4 = renderWithHashtags('Ordinary expense title');
    assert.strictEqual(res4, 'Ordinary expense title');

    // Null/undefined/empty
    assert.strictEqual(renderWithHashtags(''), '');
    assert.strictEqual(renderWithHashtags(null), '');
    assert.strictEqual(renderWithHashtags(undefined), '');

    console.log('✅ Test 1 Passed: renderWithHashtags converts hashtags into valid semantic badge chips.');
}

// -------------------------------------------------------------
// Test 2: renderWithHashtags - XSS Safety & Escaping
// -------------------------------------------------------------
{
    const malicious = '<script>alert(1)</script> #safeTag & <img src=x onerror=alert(2)>';
    const output = renderWithHashtags(malicious);

    assert.ok(!output.includes('<script>'), 'Script tag must be escaped');
    assert.ok(!output.includes('<img'), 'Img tag must be escaped');
    assert.ok(output.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
    assert.ok(output.includes('<span class="badge badge-tag" data-tag="safeTag">#safeTag</span>'));
    assert.ok(output.includes('&amp; &lt;img src=x onerror=alert(2)&gt;'));

    console.log('✅ Test 2 Passed: renderWithHashtags safely neutralizes XSS payloads.');
}

// -------------------------------------------------------------
// Test 3: ExpenseList Table Row Rendering with Hashtags
// -------------------------------------------------------------
{
    const expenses = [
        {
            id: 1,
            title: 'Dinner at Shack #beach #food',
            notes: 'Includes mocktails #drinks',
            expense_date: '2026-09-20',
            total_amount_cents: 250000,
            category: { id: 2, name: 'Food & Dining' },
            payers: [{ member_id: 1, member_name: 'Rahul', amount_paid_cents: 250000 }],
            splits: [
                { member_id: 1, member_name: 'Rahul', amount_owed_cents: 125000 },
                { member_id: 2, member_name: 'Priya', amount_owed_cents: 125000 }
            ]
        }
    ];

    // Minimal Mock DOM Container
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

    const container = new MockElement('div');
    ExpenseList.render(container, {
        token: 'test-token',
        expenses,
        currency: 'INR'
    });

    assert.ok(container.innerHTML.includes('<span class="badge badge-tag" data-tag="beach">#beach</span>'), 'Title #beach tag rendered');
    assert.ok(container.innerHTML.includes('<span class="badge badge-tag" data-tag="food">#food</span>'), 'Title #food tag rendered');
    assert.ok(container.innerHTML.includes('<span class="badge badge-tag" data-tag="drinks">#drinks</span>'), 'Notes #drinks tag rendered');

    console.log('✅ Test 3 Passed: ExpenseList renders hashtag chips in titles and notes.');
}

console.log('\n🎉 All Hashtag Support tests passed successfully!');
