/**
 * Automated Verification Suite for Distinctive Custom Member Avatars
 */
import assert from 'assert';
import * as Formatters from '../public/assets/js/utils/formatters.js';
import { MemberList } from '../public/assets/js/components/MemberList.js';
import { BalanceSummary } from '../public/assets/js/components/BalanceSummary.js';
import { SettlementPlan } from '../public/assets/js/components/SettlementPlan.js';
import { ExpenseList } from '../public/assets/js/components/ExpenseList.js';

// Setup Mock DOM & LocalStorage
const mockStorage = new Map();
global.window = {
    localStorage: {
        getItem: (k) => mockStorage.get(k) || null,
        setItem: (k, v) => mockStorage.set(k, String(v)),
        removeItem: (k) => mockStorage.delete(k),
        clear: () => mockStorage.clear(),
    },
    location: { href: 'http://localhost/workspace/abc12345' },
};
global.localStorage = global.window.localStorage;
global.document = {
    createElement: (tag) => ({
        tag,
        style: {},
        innerHTML: '',
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener: () => {},
        appendChild: () => {},
        remove: () => {},
    }),
    querySelector: () => null,
    querySelectorAll: () => [],
    getElementById: () => null,
    body: { appendChild: () => {} },
};

console.log('--- Test 1: Verify Curated Emojis and Palettes ---');
assert(Array.isArray(Formatters.CURATED_MEMBER_EMOJIS), 'CURATED_MEMBER_EMOJIS must be an array');
assert.strictEqual(Formatters.CURATED_MEMBER_EMOJIS.length, 20, 'Should have 20 curated distinct emojis');
assert(Formatters.CURATED_MEMBER_EMOJIS.includes('🦊'), 'Must include 🦊');
assert(Formatters.CURATED_MEMBER_EMOJIS.includes('🦉'), 'Must include 🦉');
assert(Formatters.CURATED_MEMBER_EMOJIS.includes('🥐'), 'Must include 🥐');
assert(Formatters.CURATED_MEMBER_EMOJIS.includes('🪩'), 'Must include 🪩');

assert(Array.isArray(Formatters.AVATAR_PALETTES), 'AVATAR_PALETTES must be an array');
assert(Formatters.AVATAR_PALETTES.length >= 8, 'Must have at least 8 palettes');
const paletteIds = Formatters.AVATAR_PALETTES.map(p => p.id);
['slate', 'sage', 'terracotta', 'ochre', 'plum', 'olive', 'forest'].forEach(id => {
    assert(paletteIds.includes(id), `Missing palette ${id}`);
});
console.log('✓ Curated Emojis and Palettes verified successfully.');

console.log('\n--- Test 2: Verify Avatar Persistence & Retrieval ---');
const testToken = 'ws_token_123';
const memberAlice = { id: 101, name: 'Alice Smith' };
const memberBob = { id: 102, name: 'Bob Jones' };

// Initially null (no customization)
assert.strictEqual(Formatters.getMemberAvatar(testToken, memberAlice), null);

// Set Alice's avatar to 🦊 with sage palette
Formatters.setMemberAvatar(testToken, memberAlice.id, { emoji: '🦊', paletteId: 'sage' });
const aliceAvatar = Formatters.getMemberAvatar(testToken, memberAlice);
assert(aliceAvatar !== null, 'Alice avatar should be found');
assert.strictEqual(aliceAvatar.emoji, '🦊');
assert.strictEqual(aliceAvatar.paletteId, 'sage');
assert(aliceAvatar.bg.startsWith('#'), 'Palette must have bg');
assert(aliceAvatar.border.startsWith('#'), 'Palette must have border');
assert(aliceAvatar.text.startsWith('#'), 'Palette must have text');

// Set Bob's avatar by name
Formatters.setMemberAvatar(testToken, 'Bob Jones', { emoji: '🥐', paletteId: 'terracotta' });
const bobAvatar = Formatters.getMemberAvatar(testToken, memberBob);
assert(bobAvatar !== null, 'Bob avatar should be found by name matching');
assert.strictEqual(bobAvatar.emoji, '🥐');
assert.strictEqual(bobAvatar.paletteId, 'terracotta');

// Clear Alice's avatar
Formatters.clearMemberAvatar(testToken, memberAlice.id);
assert.strictEqual(Formatters.getMemberAvatar(testToken, memberAlice), null, 'Cleared avatar should return null');
console.log('✓ Avatar persistence, retrieval, and clearing verified successfully.');

console.log('\n--- Test 3: Centralized HTML Rendering ---');
// Render Alice (default initials)
const htmlDefault = Formatters.renderMemberAvatar(memberAlice, testToken, { size: 24 });
assert(htmlDefault.includes('default-initials'), 'Should contain default-initials class');
assert(htmlDefault.includes('AS'), 'Should display initials AS');
assert(htmlDefault.includes('24px'), 'Should have 24px sizing');

// Re-set Alice's avatar to 🦉 with plum palette
Formatters.setMemberAvatar(testToken, memberAlice.id, { emoji: '🦉', paletteId: 'plum' });
const htmlCustom = Formatters.renderMemberAvatar(memberAlice, testToken, { size: 24 });
assert(htmlCustom.includes('custom-emoji-avatar'), 'Should contain custom-emoji-avatar class');
assert(htmlCustom.includes('🦉'), 'Should display emoji 🦉');
assert(htmlCustom.includes('style="background-color:'), 'Should have inline styling with palette colors');
console.log('✓ renderMemberAvatar HTML generation verified successfully.');

console.log('\n--- Test 4: Component Integration Check ---');
// Mock container
const container = { innerHTML: '', querySelector: () => null, querySelectorAll: () => [] };

// 1. MemberList
MemberList.render(container, [memberAlice, memberBob], testToken);
assert(container.innerHTML.includes('member-chip-customizable'), 'MemberList must have customizable chips');
assert(container.innerHTML.includes('🦉'), 'MemberList must render Alice emoji');
assert(container.innerHTML.includes('🥐'), 'MemberList must render Bob emoji');

// 2. BalanceSummary
const balances = [
    { member_id: 101, name: 'Alice Smith', net_balance_cents: 1500, total_paid_cents: 3000, total_owed_cents: 1500 },
    { member_id: 102, name: 'Bob Jones', net_balance_cents: -1500, total_paid_cents: 0, total_owed_cents: 1500 },
];
BalanceSummary.render(container, { token: testToken, balances, expenses: [] });
assert(container.innerHTML.includes('🦉'), 'BalanceSummary must render Alice emoji in position matrix');
assert(container.innerHTML.includes('🥐'), 'BalanceSummary must render Bob emoji in position matrix');

// 3. SettlementPlan SVG Diagram & Cards
const plan = {
    transactions: [
        { from_member_id: 102, from_name: 'Bob Jones', to_member_id: 101, to_name: 'Alice Smith', amount_cents: 1500 }
    ]
};
const svgOutput = SettlementPlan.renderFlowDiagramSvg({ transactions: plan.transactions, currency: 'INR', token: testToken });
assert(svgOutput.includes('🥐'), 'Flow diagram SVG must render Bob debtor emoji');
assert(svgOutput.includes('🦉'), 'Flow diagram SVG must render Alice creditor emoji');

SettlementPlan.render(container, { token: testToken, plan, settlements: [{ payer_name: 'Bob Jones', payee_name: 'Alice Smith', amount_cents: 1500, settlement_date: '2026-09-24' }] });
assert(container.innerHTML.includes('🥐'), 'SettlementPlan card must render Bob emoji');
assert(container.innerHTML.includes('🦉'), 'SettlementPlan card must render Alice emoji');

console.log('✓ All Component integrations verified successfully.');
console.log('\n🎉 ALL CUSTOM AVATAR SUITE TESTS PASSED (100%)');
