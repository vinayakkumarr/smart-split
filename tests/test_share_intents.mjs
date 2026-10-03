/**
 * Unit Test for WhatsApp & Web Share Payment Intents
 */
import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

// Setup Mock Browser Environment
global.window = {
    location: { href: 'http://localhost:8000/#/group/test-token-123' },
    open: null,
    isSecureContext: true,
};
global.document = {
    querySelector: (selector) => {
        if (selector === '.exec-title') return { textContent: 'Goa Trip 2026' };
        return null;
    }
};

import { SettlementPlan } from '../public/assets/js/components/SettlementPlan.js';
import { GroupHeader } from '../public/assets/js/components/GroupHeader.js';

let openedUrls = [];
let sharedPayloads = [];

function resetMocks({ supportShare = false, abortShare = false } = {}) {
    openedUrls = [];
    sharedPayloads = [];
    global.window.open = (url, target, features) => {
        openedUrls.push({ url, target, features });
    };

    if (supportShare) {
        Object.defineProperty(global.navigator, 'share', {
            value: async (payload) => {
                if (abortShare) {
                    const err = new Error('User cancelled share');
                    err.name = 'AbortError';
                    throw err;
                }
                sharedPayloads.push(payload);
            },
            configurable: true,
            writable: true,
        });
    } else {
        delete global.navigator.share;
    }
}

console.log('=====================================================');
console.log(' Smart Split – WhatsApp & Web Share Intent Tests');
console.log('=====================================================');

// Test 1: SettlementPlan.sharePaymentIntent with Fallback to WhatsApp URL
resetMocks({ supportShare: false });
await SettlementPlan.sharePaymentIntent({
    groupName: 'Goa Trip 2026',
    fromName: 'Rahul Sharma',
    toName: 'Priya Patel',
    amountFormatted: '₹1,250.00',
    payeeUpiId: 'priya@upi',
    upiUrl: 'upi://pay?pa=priya%40upi&pn=Priya%20Patel&am=1250.00&cu=INR&tn=SmartSplit%2BSettlement',
    workspaceUrl: 'http://localhost:8000/#/group/test-token-123',
});

assert.strictEqual(openedUrls.length, 1, 'Should open 1 WhatsApp window');
const openedUrl1 = openedUrls[0].url;
assert(openedUrl1.startsWith('https://api.whatsapp.com/send?text='), 'URL must start with WhatsApp api prefix');
const decodedText1 = decodeURIComponent(openedUrl1.replace('https://api.whatsapp.com/send?text=', ''));

console.log('\n--- Test 1: Settlement WhatsApp Intent Payload ---');
console.log(decodedText1);

assert(decodedText1.includes('Workspace: Goa Trip 2026'), 'Must contain workspace name');
assert(decodedText1.includes('Rahul Sharma ➔ Priya Patel'), 'Must contain debtor and creditor');
assert(decodedText1.includes('Amount: ₹1,250.00'), 'Must contain formatted amount');
assert(decodedText1.includes('Payee UPI: priya@upi'), 'Must contain payee UPI ID');
assert(decodedText1.includes('Pay Link: upi://pay?'), 'Must contain UPI payment link');
assert(decodedText1.includes('Workspace: http://localhost:8000/#/group/test-token-123'), 'Must contain workspace URL');
console.log('  [PASS] SettlementPlan WhatsApp fallback payload is formatted and encoded correctly.');

// Test 2: SettlementPlan.sharePaymentIntent with Native Web Share
resetMocks({ supportShare: true });
await SettlementPlan.sharePaymentIntent({
    groupName: 'Goa Trip 2026',
    fromName: 'Alice',
    toName: 'Bob',
    amountFormatted: '₹500.00',
    payeeUpiId: 'bob@upi',
    upiUrl: 'upi://pay?pa=bob%40upi&pn=Bob&am=500.00&cu=INR&tn=SmartSplit%2BSettlement',
    workspaceUrl: 'http://localhost:8000/#/group/test-token-123',
});

assert.strictEqual(sharedPayloads.length, 1, 'Should call navigator.share once');
assert.strictEqual(openedUrls.length, 0, 'Should not open WhatsApp when navigator.share succeeds');
assert(sharedPayloads[0].title.includes('Alice to Bob'), 'Share title should mention parties');
assert(sharedPayloads[0].text.includes('Alice ➔ Bob'), 'Share text should contain details');
assert(sharedPayloads[0].url === 'http://localhost:8000/#/group/test-token-123', 'Share url should match workspace');
console.log('  [PASS] SettlementPlan native Web Share invocation functions correctly.');

// Test 3: SettlementPlan.sharePaymentIntent when user cancels Native Share (AbortError)
resetMocks({ supportShare: true, abortShare: true });
await SettlementPlan.sharePaymentIntent({
    groupName: 'Goa Trip 2026',
    fromName: 'Alice',
    toName: 'Bob',
    amountFormatted: '₹500.00',
});
assert.strictEqual(openedUrls.length, 0, 'Should not open WhatsApp if user aborts native share');
console.log('  [PASS] User dismissal of native share gracefully does not trigger WhatsApp window.');

// Test 4: GroupHeader.shareInvite with WhatsApp Fallback
resetMocks({ supportShare: false });
await GroupHeader.shareInvite({ name: 'Goa Trip 2026' }, 'http://localhost:8000/#/group/test-token-123');
assert.strictEqual(openedUrls.length, 1, 'Should open WhatsApp for group invite');
const groupShareText = decodeURIComponent(openedUrls[0].url.replace('https://api.whatsapp.com/send?text=', ''));

console.log('\n--- Test 4: Group Invite WhatsApp Payload ---');
console.log(groupShareText);

assert(groupShareText.includes('Join "Goa Trip 2026" on Smart Split'), 'Invite must include group name');
assert(groupShareText.includes('http://localhost:8000/#/group/test-token-123'), 'Invite must include URL');
console.log('  [PASS] GroupHeader WhatsApp invite is formatted and encoded correctly.');

// Test 5: GroupHeader.shareInvite with Native Web Share
resetMocks({ supportShare: true });
await GroupHeader.shareInvite({ name: 'Goa Trip 2026' }, 'http://localhost:8000/#/group/test-token-123');
assert.strictEqual(sharedPayloads.length, 1, 'Should trigger navigator.share for group invite');
assert.strictEqual(openedUrls.length, 0, 'Should not open WhatsApp when native share succeeds');
assert(sharedPayloads[0].title.includes('Goa Trip 2026'), 'Title should include group name');
console.log('  [PASS] GroupHeader native Web Share functions correctly.');

// Test 6: Code Analysis and Component Assertions
console.log('\n--- Test 6: Component Code & Markup Verification ---');
const settlementPlanJs = fs.readFileSync(path.join(rootDir, 'public/assets/js/components/SettlementPlan.js'), 'utf-8');
const groupHeaderJs = fs.readFileSync(path.join(rootDir, 'public/assets/js/components/GroupHeader.js'), 'utf-8');

assert(settlementPlanJs.includes('btn-share-settlement'), 'SettlementPlan must render .btn-share-settlement button');
assert(settlementPlanJs.includes('btn-whatsapp-settle'), 'SettlementPlan must render #btn-whatsapp-settle button');
assert(settlementPlanJs.includes('sharePaymentIntent'), 'SettlementPlan must have static sharePaymentIntent');
assert(settlementPlanJs.includes('encodeURIComponent'), 'SettlementPlan must properly encode URI components');
console.log('  [PASS] SettlementPlan.js markup and methods validated.');

assert(groupHeaderJs.includes('btn-whatsapp-invite'), 'GroupHeader must render #btn-whatsapp-invite button');
assert(groupHeaderJs.includes('btn-copy-invite'), 'GroupHeader must retain #btn-copy-invite button');
assert(groupHeaderJs.includes('shareInvite'), 'GroupHeader must have static shareInvite');
assert(groupHeaderJs.includes('encodeURIComponent'), 'GroupHeader must properly encode URI components');
console.log('  [PASS] GroupHeader.js markup and methods validated.');

console.log('\n=====================================================');
console.log(' ALL 6 / 6 SHARE INTENT TESTS PASSED!');
console.log('=====================================================');
