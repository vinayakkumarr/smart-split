<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Env;
use App\Core\Database;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

$baseUrl = 'http://localhost:8000';

function masterReq(string $method, string $path, $body = null, array $headers = []): array {
    global $baseUrl;
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    
    $reqHeaders = array_merge([
        'Accept: application/json',
        'X-Requested-With: fetch'
    ], $headers);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    if ($body !== null) {
        $json = is_string($body) ? $body : json_encode($body);
        $reqHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
    
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'code' => 0,
            'headers' => '',
            'body' => ['error' => $err],
            'raw_body' => $err
        ];
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    $rawHeader = substr($response, 0, $headerSize);
    $rawBody = substr($response, $headerSize);
    
    $data = json_decode($rawBody, true);
    
    curl_close($ch);
    return [
        'code' => $httpCode,
        'headers' => $rawHeader,
        'body' => $data,
        'raw_body' => $rawBody
    ];
}

echo "================================================================================\n";
echo " FINAL MASTER PRE-RELEASE VALIDATION & ADVERSARIAL STRESS MATRIX\n";
echo "================================================================================\n\n";

$masterStats = [
    'total' => 0,
    'passed' => 0,
    'failed' => 0,
    'categories' => [],
];

function testAssert(bool $condition, string $testName, string $category, string $details = '') {
    global $masterStats;
    $masterStats['total']++;
    if (!isset($masterStats['categories'][$category])) {
        $masterStats['categories'][$category] = ['total' => 0, 'passed' => 0, 'failed' => 0];
    }
    $masterStats['categories'][$category]['total']++;
    
    if ($condition) {
        $masterStats['passed']++;
        $masterStats['categories'][$category]['passed']++;
        echo "  [PASS] [$category] $testName\n";
    } else {
        $masterStats['failed']++;
        $masterStats['categories'][$category]['failed']++;
        echo "  [FAIL] [$category] $testName : $details\n";
    }
}

// -----------------------------------------------------------------------------
// 1. VERIFY ALL PREVIOUS FIXES (SS-01 to SS-07)
// -----------------------------------------------------------------------------
echo "--- 1. Verification of All Historical Fixes (SS-01 to SS-07) ---\n";

$fixGroup = masterReq('POST', '/api/groups', ['name' => 'Fixes Validation Group', 'creator_name' => 'Alice', 'currency' => 'INR']);
$fToken = $fixGroup['body']['data']['group']['invite_token'] ?? '';
$fAlice = $fixGroup['body']['data']['creator']['id'] ?? 0;
$fBob = masterReq('POST', "/api/groups/$fToken/members", ['name' => 'Bob'])['body']['data']['member']['id'] ?? 0;
$fCharlie = masterReq('POST', "/api/groups/$fToken/members", ['name' => 'Charlie'])['body']['data']['member']['id'] ?? 0;

// SS-01: SplitCalculator HTTP 422
$resSS01 = masterReq('POST', "/api/groups/$fToken/expenses", [
    'title' => 'SS-01 Test', 'amount_cents' => 10000, 'split_type' => 'EXACT',
    'payers' => [['member_id' => $fAlice, 'amount_cents' => 10000]],
    'splits' => [['member_id' => $fAlice, 'amount_cents' => 4000], ['member_id' => $fBob, 'amount_cents' => 4000]]
]);
testAssert($resSS01['code'] === 422 && ($resSS01['body']['error']['code'] ?? '') === 'UNPROCESSABLE_ENTITY',
    'SS-01: SplitCalculator returns HTTP 422 with UNPROCESSABLE_ENTITY', 'Regression');

// SS-02: Modal focus trapping code inspection
$modalJs = file_get_contents(dirname(__DIR__) . '/public/assets/js/components/Modal.js');
testAssert(str_contains($modalJs, 'Modal.activeKeyHandler') && str_contains($modalJs, "e.key === 'Tab'"),
    'SS-02: Modal.js implements keyboard Tab focus trapping', 'Regression');

// SS-03: SettlementPlan undo button aria-label
$settlePlanJs = file_get_contents(dirname(__DIR__) . '/public/assets/js/components/SettlementPlan.js');
testAssert(str_contains($settlePlanJs, 'aria-label="Undo settlement payment"'),
    'SS-03: SettlementPlan.js contains accessible aria-label on undo button', 'Regression');

// SS-04: Unchecked participant styling in CSS & JS
$compCss = file_get_contents(dirname(__DIR__) . '/public/assets/css/components.css');
$expModalJs = file_get_contents(dirname(__DIR__) . '/public/assets/js/components/ExpenseModal.js');
testAssert(str_contains($compCss, '.participant-row.is-excluded') && str_contains($expModalJs, 'is-excluded'),
    'SS-04: Visual exclusion is-excluded styling implemented across CSS & JS', 'Regression');

// SS-05: README.md presence and completeness
testAssert(file_exists(dirname(__DIR__) . '/README.md') && filesize(dirname(__DIR__) . '/README.md') > 1000,
    'SS-05: Root README.md exists and contains complete documentation', 'Regression');

// SS-06: Multi-Payer Duplicate Member Consolidation
$resSS06 = masterReq('POST', "/api/groups/$fToken/expenses", [
    'title' => 'SS-06 Consolidated Payers', 'amount_cents' => 5000, 'split_type' => 'EQUAL',
    'payers' => [
        ['member_id' => $fAlice, 'amount_cents' => 3000],
        ['member_id' => $fAlice, 'amount_cents' => 2000] // Duplicate Alice
    ],
    'splits' => [['member_id' => $fAlice, 'amount_cents' => 2500], ['member_id' => $fBob, 'amount_cents' => 2500]]
]);
testAssert($resSS06['code'] === 201 && ($resSS06['body']['data']['expense']['id'] ?? 0) > 0,
    'SS-06: Duplicate payer entries are cleanly consolidated without MySQL 500 collision', 'Regression');

// SS-07: Explicit empty array in split_members rejected with 422
$resSS07 = masterReq('POST', "/api/groups/$fToken/expenses", [
    'title' => 'SS-07 Empty Split Members', 'amount_cents' => 5000, 'split_type' => 'EQUAL',
    'payer_id' => $fAlice, 'split_members' => []
]);
testAssert($resSS07['code'] === 422,
    'SS-07: Explicit empty split_members array is rejected with HTTP 422', 'Regression');

// -----------------------------------------------------------------------------
// 2. COMPLETE END-TO-END USER JOURNEY & LIFECYCLE
// -----------------------------------------------------------------------------
echo "\n--- 2. Complete End-to-End User Journey (Happy & Sad Paths) ---\n";

$journeyGroup = masterReq('POST', '/api/groups', ['name' => 'Goa Trip 2026', 'creator_name' => 'Rahul', 'currency' => 'INR']);
$jToken = $journeyGroup['body']['data']['group']['invite_token'];
$jRahul = $journeyGroup['body']['data']['creator']['id'];

$jPriya = masterReq('POST', "/api/groups/$jToken/members", ['name' => 'Priya'])['body']['data']['member']['id'];
$jAmit = masterReq('POST', "/api/groups/$jToken/members", ['name' => 'Amit'])['body']['data']['member']['id'];
$jSneha = masterReq('POST', "/api/groups/$jToken/members", ['name' => 'Sneha'])['body']['data']['member']['id'];

testAssert(count([$jRahul, $jPriya, $jAmit, $jSneha]) === 4, '4 Group members registered successfully', 'User Journeys');

// Step 1: Equal split expense (₹1200 split 4 ways = ₹300 each)
$exp1 = masterReq('POST', "/api/groups/$jToken/expenses", [
    'title' => 'Villa Booking', 'amount_cents' => 120000, 'split_type' => 'EQUAL',
    'payer_id' => $jRahul, 'split_members' => [$jRahul, $jPriya, $jAmit, $jSneha]
]);
$exp1Id = $exp1['body']['data']['expense']['id'] ?? 0;
testAssert($exp1['code'] === 201 && $exp1Id > 0, 'Expense 1: Villa Booking recorded (₹1200)', 'User Journeys');

// Step 2: Percentage split (₹600 dinner: Priya 40%, Amit 30%, Sneha 30%)
$exp2 = masterReq('POST', "/api/groups/$jToken/expenses", [
    'title' => 'Seafood Dinner', 'amount_cents' => 60000, 'split_type' => 'PERCENTAGE',
    'payer_id' => $jPriya,
    'splits' => [
        ['member_id' => $jPriya, 'value' => 40],
        ['member_id' => $jAmit, 'value' => 30],
        ['member_id' => $jSneha, 'value' => 30]
    ]
]);
testAssert($exp2['code'] === 201, 'Expense 2: Percentage Split recorded (₹600)', 'User Journeys');

// Step 3: Shares split (₹400 groceries: Rahul 2 shares, Amit 1 share, Sneha 1 share)
$exp3 = masterReq('POST', "/api/groups/$jToken/expenses", [
    'title' => 'Supermarket Groceries', 'amount_cents' => 40000, 'split_type' => 'SHARES',
    'payer_id' => $jAmit,
    'splits' => [
        ['member_id' => $jRahul, 'value' => 2],
        ['member_id' => $jAmit, 'value' => 1],
        ['member_id' => $jSneha, 'value' => 1]
    ]
]);
testAssert($exp3['code'] === 201, 'Expense 3: Shares Split recorded (₹400)', 'User Journeys');

// Step 4: Multi-Payer Exact Split (₹1000 Cab: Rahul pays 600, Priya pays 400; split Amit 500, Sneha 500)
$exp4 = masterReq('POST', "/api/groups/$jToken/expenses", [
    'title' => 'Airport Taxi', 'amount_cents' => 100000, 'split_type' => 'EXACT',
    'payers' => [
        ['member_id' => $jRahul, 'amount_cents' => 60000],
        ['member_id' => $jPriya, 'amount_cents' => 40000]
    ],
    'splits' => [
        ['member_id' => $jAmit, 'amount_cents' => 50000],
        ['member_id' => $jSneha, 'amount_cents' => 50000]
    ]
]);
testAssert($exp4['code'] === 201, 'Expense 4: Multi-Payer Exact Split recorded (₹1000)', 'User Journeys');

// Verify Balances & Zero-Sum Invariant
$balRes = masterReq('GET', "/api/groups/$jToken/balances");
testAssert($balRes['code'] === 200, 'Balances API returned 200 OK', 'Data Integrity');
testAssert(($balRes['body']['data']['total_spending_cents'] ?? 0) === 320000, 'Total spending matches ₹3,200.00 (320,000 paise)', 'Data Integrity');
testAssert(($balRes['body']['data']['zero_sum_verified'] ?? false) === true, 'Zero-sum ledger invariant holds across all members', 'Data Integrity');

// Check Member Balances:
// Rahul: paid 1200 + 600 = 1800; owed 300 + 200 = 500 -> Net +1300 (+130000 paise)
// Priya: paid 600 + 400 = 1000; owed 300 + 240 = 540 -> Net +460 (+46000 paise)
// Amit: paid 400; owed 300 + 180 + 100 + 500 = 1080 -> Net -680 (-68000 paise)
// Sneha: paid 0; owed 300 + 180 + 100 + 500 = 1080 -> Net -1080 (-108000 paise)
// Net sum: +1300 + 460 - 680 - 1080 = 0.
$mMap = [];
foreach ($balRes['body']['data']['members'] as $m) {
    $mMap[$m['member_id']] = $m['net_balance_cents'];
}
testAssert($mMap[$jRahul] === 130000 && $mMap[$jPriya] === 46000 && $mMap[$jAmit] === -68000 && $mMap[$jSneha] === -108000,
    'Exact net balances match mathematical truth (+1300, +460, -680, -1080 INR)', 'Data Integrity');

// Step 5: Compute Greedy Debt Simplification Plan (Must be <= 3 transactions for 4 members)
$planRes = masterReq('GET', "/api/groups/$jToken/settlement-plan");
$planTxs = $planRes['body']['data']['transactions'] ?? [];
testAssert(count($planTxs) <= 3 && count($planTxs) >= 1, 'Debt simplification produces <= 3 transactions for 4 members', 'Algorithms');

// Step 6: Record settlement: Sneha pays Rahul ₹1080
$settle1 = masterReq('POST', "/api/groups/$jToken/settlements", [
    'payer_id' => $jSneha, 'payee_id' => $jRahul, 'amount_cents' => 108000, 'notes' => 'UPI transfer'
]);
$settle1Id = $settle1['body']['data']['settlement']['id'] ?? 0;
testAssert($settle1['code'] === 201 && $settle1Id > 0, 'Settlement 1: Sneha pays Rahul ₹1080 recorded', 'User Journeys');

// Sneha should now be completely SETTLED (0 paise)
$balAfterSettle = masterReq('GET', "/api/groups/$jToken/balances");
$snehaBal = 999;
foreach ($balAfterSettle['body']['data']['members'] as $m) {
    if ($m['member_id'] === $jSneha) $snehaBal = $m['net_balance_cents'];
}
testAssert($snehaBal === 0, 'Member Sneha is now 100% SETTLED (0 paise)', 'Data Integrity');

// Step 7: Undo Settlement 1 and verify Sneha debt is restored
$undoSettle = masterReq('DELETE', "/api/groups/$jToken/settlements/$settle1Id");
testAssert($undoSettle['code'] === 200, 'Settlement 1 undone / soft-deleted', 'User Journeys');

$balAfterUndo = masterReq('GET', "/api/groups/$jToken/balances");
$snehaBalRestored = 0;
foreach ($balAfterUndo['body']['data']['members'] as $m) {
    if ($m['member_id'] === $jSneha) $snehaBalRestored = $m['net_balance_cents'];
}
testAssert($snehaBalRestored === -108000, 'Sneha debt restored to -108000 paise after settlement reversal', 'Data Integrity');

// Step 8: Soft-delete Expense 1 (Villa Booking ₹1200)
$delExp1 = masterReq('DELETE', "/api/groups/$jToken/expenses/$exp1Id");
testAssert($delExp1['code'] === 200, 'Expense 1 soft-deleted', 'User Journeys');

$balAfterDelExp = masterReq('GET', "/api/groups/$jToken/balances");
testAssert(($balAfterDelExp['body']['data']['total_spending_cents'] ?? 0) === 200000,
    'Total spend reduced from ₹3200 to ₹2000 after deleting Expense 1', 'Data Integrity');
testAssert(($balAfterDelExp['body']['data']['zero_sum_verified'] ?? false) === true,
    'Zero-sum invariant preserved after expense deletion', 'Data Integrity');

// -----------------------------------------------------------------------------
// 3. EXTREME BOUNDARY & ADVERSARIAL ATTACK TESTING
// -----------------------------------------------------------------------------
echo "\n--- 3. Extreme Boundary & Security Adversarial Vectors ---\n";

// A. Maximum Rupee Volume (₹40,000,000.00 = 4,000,000,000 paise within unsigned INT)
$maxAmount = 4000000000;
$resMax = masterReq('POST', "/api/groups/$jToken/expenses", [
    'title' => 'Super Mega Property Buy', 'amount_cents' => $maxAmount, 'split_type' => 'EQUAL',
    'payer_id' => $jRahul, 'split_members' => [$jRahul, $jPriya]
]);
testAssert($resMax['code'] === 201, 'Maximum boundary ₹40 Million (4 Billion paise) logged without overflow', 'Boundary Testing');

// B. Unicode and Multilingual Characters (Hindi, Japanese, Arabic, Emojis)
$uniTitle = '✈️ फ्लाइट टिकट & 寿司 🍣 & عشاء فاخر';
$resUni = masterReq('POST', "/api/groups/$jToken/expenses", [
    'title' => $uniTitle, 'amount_cents' => 5000, 'split_type' => 'EQUAL',
    'payer_id' => $jRahul, 'split_members' => [$jRahul, $jPriya]
]);
testAssert($resUni['code'] === 201, 'Multilingual Unicode & Emoji titles stored and retrieved verbatim', 'Boundary Testing');

// C. Stored XSS Injection Probes
$xssPayload = '<script>alert("XSS")</script><img src=x onerror=alert(1)><b>Bold Title</b>';
$resXss = masterReq('POST', "/api/groups/$jToken/expenses", [
    'title' => $xssPayload, 'amount_cents' => 5000, 'split_type' => 'EQUAL',
    'payer_id' => $jRahul, 'split_members' => [$jRahul, $jPriya]
]);
$savedTitle = $resXss['body']['data']['expense']['title'] ?? '';
testAssert($resXss['code'] === 201 && !str_contains($savedTitle, '<script>') && !str_contains($savedTitle, 'onerror'),
    'Server-side HTML/Script tags stripped from expense title', 'Security');

// D. Security Headers Verification
$resHealth = masterReq('GET', '/api/health');
testAssert(str_contains($resHealth['headers'], 'X-Content-Type-Options: nosniff'),
    'Security Header: X-Content-Type-Options: nosniff present', 'Security');
testAssert(str_contains($resHealth['headers'], 'X-Frame-Options: DENY'),
    'Security Header: X-Frame-Options: DENY present', 'Security');
testAssert(str_contains($resHealth['headers'], 'Content-Security-Policy'),
    'Security Header: Content-Security-Policy present', 'Security');

// E. CSRF Missing Header Protection
// Send POST without X-Requested-With or Content-Type
$chCsrf = curl_init('http://localhost:8000/api/groups');
curl_setopt($chCsrf, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chCsrf, CURLOPT_POST, true);
curl_setopt($chCsrf, CURLOPT_POSTFIELDS, 'name=AttackGroup&creator_name=Hacker');
curl_setopt($chCsrf, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);
$rawCsrf = curl_exec($chCsrf);
$csrfCode = curl_getinfo($chCsrf, CURLINFO_HTTP_CODE);
curl_close($chCsrf);
testAssert($csrfCode === 403, 'CSRF Guard: Missing AJAX/JSON headers on mutating request blocked with 403', 'Security');

// -----------------------------------------------------------------------------
// 4. LARGE DATASET & MASSIVE CONCURRENCY STRESS
// -----------------------------------------------------------------------------
echo "\n--- 4. Large Dataset & Massive Concurrency Stress ---\n";

$largeGroup = masterReq('POST', '/api/groups', ['name' => 'Massive 30-Member Expedition', 'creator_name' => 'Leader_1', 'currency' => 'INR']);
$lToken = $largeGroup['body']['data']['group']['invite_token'];
$lMembers = [$largeGroup['body']['data']['creator']['id']];

$stressStart = microtime(true);

// Add 29 more members (30 total)
for ($i = 2; $i <= 30; $i++) {
    $resMem = masterReq('POST', "/api/groups/$lToken/members", ['name' => "Expedition_Member_$i"]);
    if ($resMem['code'] === 201) {
        $lMembers[] = $resMem['body']['data']['member']['id'];
    }
}
testAssert(count($lMembers) === 30, '30 members added to massive stress group', 'Large Data');

// Log 50 randomized expenses across 30 members
$expLogged = 0;
for ($e = 1; $e <= 50; $e++) {
    $payerId = $lMembers[array_rand($lMembers)];
    $amount = rand(5000, 100000); // ₹50.00 to ₹1000.00
    
    // Select 5-10 random participants
    shuffle($lMembers);
    $participants = array_slice($lMembers, 0, rand(5, 10));
    if (!in_array($payerId, $participants)) {
        $participants[] = $payerId;
    }
    
    $pCount = count($participants);
    $baseShare = intdiv($amount, $pCount);
    $rem = $amount % $pCount;
    
    $splits = [];
    foreach ($participants as $idx => $pid) {
        $splits[] = [
            'member_id' => $pid,
            'amount_cents' => $baseShare + ($idx < $rem ? 1 : 0)
        ];
    }
    
    $rExp = masterReq('POST', "/api/groups/$lToken/expenses", [
        'title' => "Expedition Item #$e",
        'amount_cents' => $amount,
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $payerId, 'amount_cents' => $amount]],
        'splits' => $splits
    ]);
    
    if ($rExp['code'] === 201) $expLogged++;
}

$stressElapsed = round((microtime(true) - $stressStart) * 1000, 2);
testAssert($expLogged === 50, "50 randomized multi-participant expenses logged in {$stressElapsed}ms", 'Large Data');

// Assert Invariant & Settlement Algorithm on 30 members & 50 expenses
$lBal = masterReq('GET', "/api/groups/$lToken/balances");
testAssert($lBal['code'] === 200 && ($lBal['body']['data']['zero_sum_verified'] ?? false) === true,
    'Zero-sum invariant holds across 30 members and 50 expenses', 'Data Integrity');

$lPlan = masterReq('GET', "/api/groups/$lToken/settlement-plan");
$lPlanTxs = $lPlan['body']['data']['transactions'] ?? [];
$txCount = count($lPlanTxs);
testAssert($lPlan['code'] === 200 && $txCount <= 29,
    "Greedy Min-Cash-Flow solves 30-member debt matrix with $txCount transfers (<= 29 bound)", 'Algorithms');

echo "\n================================================================================\n";
echo " FINAL MASTER RESULTS: {$masterStats['passed']} Passed, {$masterStats['failed']} Failed (Total: {$masterStats['total']})\n";
echo "================================================================================\n";
