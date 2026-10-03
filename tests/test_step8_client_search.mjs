/**
 * Node.js Unit Verification for Multi-Dimensional Search & Filtering Logic
 */

console.log("====================================================================");
console.log(" STEP 8: CLIENT MULTI-DIMENSIONAL SEARCH & FILTERING LOGIC VALIDATION");
console.log("====================================================================\n");

let passed = 0;
let total = 0;

function assert(condition, name) {
    total++;
    if (condition) {
        console.log(`  [PASS] ${name}`);
        passed++;
    } else {
        console.error(`  [FAIL] ${name}`);
        process.exit(1);
    }
}

// Sample Mock Dataset
const mockExpenses = [
    {
        id: 1,
        title: 'Weekly Supermarket Haul',
        total_amount_cents: 200000,
        amount_cents: 200000,
        split_type: 'EQUAL',
        category_id: 6,
        category: { id: 6, name: 'Groceries', slug: 'groceries' },
        expense_date: '2026-08-15',
        notes: 'Organic vegetables and dairy',
        payers: [{ member_id: 1, member_name: 'Dev', amount_paid_cents: 200000 }],
        splits: [
            { member_id: 1, member_name: 'Dev', amount_owed_cents: 66667 },
            { member_id: 2, member_name: 'Kavya', amount_owed_cents: 66667 },
            { member_id: 3, member_name: 'Siddharth', amount_owed_cents: 66666 },
        ],
        items: []
    },
    {
        id: 2,
        title: 'Italian Pasta Night',
        total_amount_cents: 450000,
        amount_cents: 450000,
        split_type: 'EXACT',
        category_id: 2,
        category: { id: 2, name: 'Food & Dining', slug: 'food-dining' },
        expense_date: '2026-09-05',
        notes: 'Truffle pasta and tiramisu',
        payers: [{ member_id: 2, member_name: 'Kavya', amount_paid_cents: 450000 }],
        splits: [
            { member_id: 1, member_name: 'Dev', amount_owed_cents: 150000 },
            { member_id: 2, member_name: 'Kavya', amount_owed_cents: 150000 },
            { member_id: 3, member_name: 'Siddharth', amount_owed_cents: 150000 },
        ],
        items: []
    },
    {
        id: 3,
        title: 'Goa Flight Tickets',
        total_amount_cents: 1800000,
        amount_cents: 1800000,
        split_type: 'EQUAL',
        category_id: 3,
        category: { id: 3, name: 'Travel & Transport', slug: 'travel-transport' },
        expense_date: '2026-09-10',
        notes: 'Indigo round trip',
        payers: [{ member_id: 3, member_name: 'Siddharth', amount_paid_cents: 1800000 }],
        splits: [
            { member_id: 1, member_name: 'Dev', amount_owed_cents: 900000 },
            { member_id: 3, member_name: 'Siddharth', amount_owed_cents: 900000 },
        ],
        items: []
    },
    {
        id: 4,
        title: 'Airtel Fiber Broadband',
        total_amount_cents: 120000,
        amount_cents: 120000,
        split_type: 'EQUAL',
        category_id: 5,
        category: { id: 5, name: 'Utilities & Bills', slug: 'utilities-bills' },
        expense_date: '2026-09-15',
        notes: 'Monthly 300Mbps plan',
        payers: [{ member_id: 1, member_name: 'Dev', amount_paid_cents: 120000 }],
        splits: [
            { member_id: 1, member_name: 'Dev', amount_owed_cents: 40000 },
            { member_id: 2, member_name: 'Kavya', amount_owed_cents: 40000 },
            { member_id: 3, member_name: 'Siddharth', amount_owed_cents: 40000 },
        ],
        items: []
    },
    {
        id: 5,
        title: 'Artisan Cafe Brunch',
        total_amount_cents: 160000,
        amount_cents: 160000,
        split_type: 'ITEMIZED',
        category_id: 2,
        category: { id: 2, name: 'Food & Dining', slug: 'food-dining' },
        expense_date: '2026-09-18',
        notes: 'Special breakfast blend',
        payers: [{ member_id: 2, member_name: 'Kavya', amount_paid_cents: 160000 }],
        splits: [
            { member_id: 1, member_name: 'Dev', amount_owed_cents: 68571 },
            { member_id: 2, member_name: 'Kavya', amount_owed_cents: 62857 },
            { member_id: 3, member_name: 'Siddharth', amount_owed_cents: 28572 },
        ],
        items: [
            { name: 'Avocado Toast', amount_cents: 80000, member_ids: [1, 2] },
            { name: 'Matcha Latte', amount_cents: 60000, member_ids: [2, 3] },
        ]
    }
];

function filterExpenses(expenses, filterState) {
    const today = new Date('2026-09-21');
    const currentYearMonth = '2026-09';
    const prevYearMonth = '2026-08';
    const thirtyDaysAgoStr = '2026-08-22';

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

            const matchesText = title.includes(q) ||
                notes.includes(q) ||
                catName.includes(q) ||
                payers.includes(q) ||
                splits.includes(q) ||
                items.includes(q) ||
                amountStr.includes(q) ||
                rawAmt.includes(q);

            if (!matchesText) return false;
        }

        // 2. Category Filter
        if (filterState.categoryId && filterState.categoryId !== 'all') {
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

        // 4. Participant / Member Filter
        if (filterState.memberId && filterState.memberId !== 'all') {
            const mId = Number(filterState.memberId);
            if (filterState.memberRole === 'payer') {
                const isPayer = (exp.payers || []).some(p => Number(p.member_id) === mId && Number(p.amount_paid_cents) > 0);
                if (!isPayer) return false;
            } else if (filterState.memberRole === 'debtor') {
                const isDebtor = (exp.splits || []).some(s => Number(s.member_id) === mId && Number(s.amount_owed_cents) > 0);
                if (!isDebtor) return false;
            } else {
                const isPayer = (exp.payers || []).some(p => Number(p.member_id) === mId && Number(p.amount_paid_cents) > 0);
                const isDebtor = (exp.splits || []).some(s => Number(s.member_id) === mId && Number(s.amount_owed_cents) > 0);
                if (!isPayer && !isDebtor) return false;
            }
        }

        // 5. Split Type Filter
        if (filterState.splitType && filterState.splitType !== 'all') {
            if ((exp.split_type || 'EQUAL').toUpperCase() !== filterState.splitType.toUpperCase()) {
                return false;
            }
        }

        // 6. Amount Range Filter
        const expAmountCents = exp.total_amount_cents || exp.amount_cents || 0;
        if (filterState.minAmount !== '' && filterState.minAmount !== undefined && !isNaN(Number(filterState.minAmount))) {
            if (expAmountCents < Number(filterState.minAmount) * 100) return false;
        }
        if (filterState.maxAmount !== '' && filterState.maxAmount !== undefined && !isNaN(Number(filterState.maxAmount))) {
            if (expAmountCents > Number(filterState.maxAmount) * 100) return false;
        }

        return true;
    });
}

// Tests
console.log("--- 1. Testing Default Unfiltered State ---");
const r0 = filterExpenses(mockExpenses, {});
assert(r0.length === 5, "Unfiltered returns all 5 expenses");

console.log("\n--- 2. Testing Text Search Matching ---");
const rSearch1 = filterExpenses(mockExpenses, { search: 'pasta' });
assert(rSearch1.length === 1 && rSearch1[0].title === 'Italian Pasta Night', "Search 'pasta' matches Italian Pasta");

const rSearchItem = filterExpenses(mockExpenses, { search: 'avocado' });
assert(rSearchItem.length === 1 && rSearchItem[0].title === 'Artisan Cafe Brunch', "Search line item 'avocado' matches Cafe Brunch");

const rSearchPayer = filterExpenses(mockExpenses, { search: 'Kavya' });
assert(rSearchPayer.length === 4, "Search 'Kavya' matches expenses involving Kavya");

const rSearchAmount = filterExpenses(mockExpenses, { search: '18000' });
assert(rSearchAmount.length === 1 && rSearchAmount[0].title === 'Goa Flight Tickets', "Search formatted amount '18000' matches Flight Tickets");

console.log("\n--- 3. Testing Category Filter ---");
const rCatFood = filterExpenses(mockExpenses, { categoryId: 2 });
assert(rCatFood.length === 2, "Category ID 2 (Food & Dining) matches 2 expenses");

const rCatGroc = filterExpenses(mockExpenses, { categoryId: 6 });
assert(rCatGroc.length === 1 && rCatGroc[0].title === 'Weekly Supermarket Haul', "Category ID 6 (Groceries) matches 1 expense");

console.log("\n--- 4. Testing Date Range Presets ---");
const rThisMonth = filterExpenses(mockExpenses, { datePreset: 'this_month' });
assert(rThisMonth.length === 4, "datePreset: 'this_month' (Sep 2026) returns 4 expenses");

const rLastMonth = filterExpenses(mockExpenses, { datePreset: 'last_month' });
assert(rLastMonth.length === 1 && rLastMonth[0].title === 'Weekly Supermarket Haul', "datePreset: 'last_month' (Aug 2026) returns 1 expense");

const rCustomDate = filterExpenses(mockExpenses, { datePreset: 'custom', fromDate: '2026-09-08', toDate: '2026-09-16' });
assert(rCustomDate.length === 2, "Custom date range 2026-09-08 to 2026-09-16 returns 2 expenses (Flights and Broadband)");

console.log("\n--- 5. Testing Participant & Role Filters ---");
const rPayerDev = filterExpenses(mockExpenses, { memberId: 1, memberRole: 'payer' });
assert(rPayerDev.length === 2, "Payer Dev paid for 2 expenses (Groceries and Broadband)");

const rDebtorKavya = filterExpenses(mockExpenses, { memberId: 2, memberRole: 'debtor' });
assert(rDebtorKavya.length === 4, "Debtor Kavya owes in 4 expenses");

const rInvolvedSid = filterExpenses(mockExpenses, { memberId: 3, memberRole: 'involved' });
assert(rInvolvedSid.length === 5, "Involved Siddharth is in all 5 expenses");

console.log("\n--- 6. Testing Split Type & Amount Bounds ---");
const rItemized = filterExpenses(mockExpenses, { splitType: 'ITEMIZED' });
assert(rItemized.length === 1 && rItemized[0].title === 'Artisan Cafe Brunch', "splitType 'ITEMIZED' matches 1 expense");

const rAmountMin = filterExpenses(mockExpenses, { minAmount: 4000 });
assert(rAmountMin.length === 2, "minAmount >= ₹4000 matches 2 expenses (Pasta ₹4500 and Flights ₹18000)");

const rAmountMax = filterExpenses(mockExpenses, { maxAmount: 1500 });
assert(rAmountMax.length === 1 && rAmountMax[0].title === 'Airtel Fiber Broadband', "maxAmount <= ₹1500 matches Broadband ₹1200");

console.log("\n--- 7. Testing Combined Multi-Dimensional Criteria ---");
const rCombo = filterExpenses(mockExpenses, {
    categoryId: 2, // Food & Dining
    memberId: 1, // Dev
    memberRole: 'debtor',
    minAmount: 2000,
});
assert(rCombo.length === 1 && rCombo[0].title === 'Italian Pasta Night', "Combined (Food + Debtor Dev + Amount >= ₹2000) matches Italian Pasta Night");

console.log("\n====================================================================");
console.log(` STEP 8 CLIENT VALIDATION: ${passed} / ${total} Passed (100%)`);
console.log("====================================================================\n");
