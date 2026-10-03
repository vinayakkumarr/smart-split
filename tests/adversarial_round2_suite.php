<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Env;
use App\Core\Database;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

$baseUrl = 'http://localhost:8000';

function advHttpReq(string $method, string $path, $body = null, array $headers = []): array {
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

echo "====================================================================\n";
echo " ROUND 2: AGGRESSIVE ADVERSARIAL TESTING & PRODUCTION READINESS AUDIT\n";
echo "====================================================================\n\n";

$stats = [
    'total' => 0,
    'passed' => 0,
    'failed' => 0,
    'findings' => [],
];

function assertAdv(bool $condition, string $testName, string $details = '', string $severity = 'Medium', string $category = 'Functional') {
    global $stats;
    $stats['total']++;
    if ($condition) {
        $stats['passed']++;
        echo "  [PASS] $testName\n";
    } else {
        $stats['failed']++;
        $stats['findings'][] = [
            'test' => $testName,
            'details' => $details,
            'severity' => $severity,
            'category' => $category
        ];
        echo "  [FAIL] $testName ($severity): $details\n";
    }
}

// -------------------------------------------------------------
// SECTION 1: REGRESSION VALIDATION ON ALL PREVIOUS FIXES
// -------------------------------------------------------------
echo "--- 1. Regression Testing on Fixed Issues ---\n";

// Create clean baseline group for regression
$regGroup = advHttpReq('POST', '/api/groups', ['name' => 'Regression Group', 'creator_name' => 'Alice', 'currency' => 'INR']);
$rToken = $regGroup['body']['data']['group']['invite_token'] ?? '';
$rAlice = $regGroup['body']['data']['creator']['id'] ?? 0;

$regBob = advHttpReq('POST', "/api/groups/$rToken/members", ['name' => 'Bob']);
$rBob = $regBob['body']['data']['member']['id'] ?? 0;

// Re-Test SS-ISSUE-01: SplitCalculator HTTP 422 on calculation errors
// A. Percentage sum > 100%
$resPctHigh = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => 'Invalid Pct Split',
    'amount_cents' => 10000,
    'split_type' => 'PERCENTAGE',
    'payers' => [['member_id' => $rAlice, 'amount_cents' => 10000]],
    'splits' => [
        ['member_id' => $rAlice, 'amount_cents' => 60, 'value' => 60],
        ['member_id' => $rBob, 'amount_cents' => 50, 'value' => 50] // 60 + 50 = 110%
    ]
]);
assertAdv($resPctHigh['code'] === 422 && ($resPctHigh['body']['error']['code'] ?? '') === 'UNPROCESSABLE_ENTITY',
    'SS-ISSUE-01: Percentage sum != 100% returns HTTP 422 with UNPROCESSABLE_ENTITY', "Code: {$resPctHigh['code']}");

// B. Shares sum with 0 share units
$resZeroShare = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => 'Zero Shares Split',
    'amount_cents' => 10000,
    'split_type' => 'SHARES',
    'payers' => [['member_id' => $rAlice, 'amount_cents' => 10000]],
    'splits' => [
        ['member_id' => $rAlice, 'value' => 0],
        ['member_id' => $rBob, 'value' => 0]
    ]
]);
assertAdv($resZeroShare['code'] === 422, 'SS-ISSUE-01: Zero share units returns HTTP 422', "Code: {$resZeroShare['code']}");

// C. Exact split sum mismatch
$resExactMismatch = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => 'Exact Mismatch Split',
    'amount_cents' => 10000,
    'split_type' => 'EXACT',
    'payers' => [['member_id' => $rAlice, 'amount_cents' => 10000]],
    'splits' => [
        ['member_id' => $rAlice, 'amount_cents' => 4000],
        ['member_id' => $rBob, 'amount_cents' => 4000]
    ]
]);
assertAdv($resExactMismatch['code'] === 422, 'SS-ISSUE-01: Exact split mismatch returns HTTP 422', "Code: {$resExactMismatch['code']}");

// -------------------------------------------------------------
// SECTION 2: ADVERSARIAL INPUT INJECTIONS & BOUNDARY PROBES
// -------------------------------------------------------------
echo "\n--- 2. Adversarial Input Injections & Boundaries ---\n";

// A. Huge integer cents (Integer Overflow Probe: ₹100,000,000,000.00 = 10,000,000,000,000 cents > 32-bit INT)
$hugeAmount = 5000000000; // 50 Crore paise
$resHuge = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => 'Huge Rupee Expense',
    'amount_cents' => $hugeAmount,
    'split_type' => 'EQUAL',
    'payers' => [['member_id' => $rAlice, 'amount_cents' => $hugeAmount]],
    'splits' => [
        ['member_id' => $rAlice, 'amount_cents' => 2500000000],
        ['member_id' => $rBob, 'amount_cents' => 2500000000]
    ]
]);
// MySQL INT UNSIGNED maximum is 4,294,967,295. Total amount cents of 5,000,000,000 exceeds INT UNSIGNED!
// Let's see how the application handles amounts exceeding INT UNSIGNED maximum
assertAdv($resHuge['code'] === 201 || $resHuge['code'] === 422, 'Huge amount boundary handled gracefully without unhandled crash', "Code: {$resHuge['code']}");

// B. Floating point / Decimal injection in integer cents field
$resFloat = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => 'Float Cent Injection',
    'amount_cents' => 100.75, // float instead of integer cents
    'split_type' => 'EQUAL',
    'payers' => [['member_id' => $rAlice, 'amount_cents' => 100.75]]
]);
assertAdv($resFloat['code'] === 201 || $resFloat['code'] === 422, 'Float in amount_cents handled safely', "Code: {$resFloat['code']}");

// C. String array / Null byte injection in title
$resNullByte = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => "Dinner\x00Exploit",
    'amount_cents' => 1000,
    'split_type' => 'EQUAL',
    'payers' => [['member_id' => $rAlice, 'amount_cents' => 1000]]
]);
assertAdv($resNullByte['code'] === 201 || $resNullByte['code'] === 422, 'Null-byte in string input sanitized without fatal error', "Code: {$resNullByte['code']}");

// D. SQL Injection vector in group invite token parameter
$resSqliToken = advHttpReq('GET', "/api/groups/" . urlencode("' OR '1'='1"));
assertAdv($resSqliToken['code'] === 404, 'SQL Injection in group token parameter safely rejected with 404', "Code: {$resSqliToken['code']}");

// E. Empty / Null Payload to POST routes
$resEmptyPost = advHttpReq('POST', "/api/groups/$rToken/expenses", []);
assertAdv($resEmptyPost['code'] === 422, 'Empty payload to /expenses rejected with 422', "Code: {$resEmptyPost['code']}");

// F. Missing title in expense payload
$resMissingTitle = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => '   ', // only whitespace
    'amount_cents' => 5000,
    'split_type' => 'EQUAL',
    'payers' => [['member_id' => $rAlice, 'amount_cents' => 5000]]
]);
assertAdv($resMissingTitle['code'] === 422, 'Whitespace-only title rejected with 422 validation error', "Code: {$resMissingTitle['code']}");

// -------------------------------------------------------------
// SECTION 3: MULTI-PAYER & DUPLICATE PARTICIPANT INTEGRITY
// -------------------------------------------------------------
echo "\n--- 3. Multi-Payer & Participant Collision Probes ---\n";

// A. Duplicate member ID in Payers array (Alice listed twice: 3000 + 2000 = 5000)
$resDupPayer = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => 'Duplicate Payer Test',
    'amount_cents' => 5000,
    'split_type' => 'EQUAL',
    'payers' => [
        ['member_id' => $rAlice, 'amount_cents' => 3000],
        ['member_id' => $rAlice, 'amount_cents' => 2000] // Duplicate Alice
    ],
    'splits' => [
        ['member_id' => $rAlice, 'amount_cents' => 2500],
        ['member_id' => $rBob, 'amount_cents' => 2500]
    ]
]);
// In database, uq_expense_payer (expense_id, member_id) has a UNIQUE constraint!
// Does the service aggregate duplicate payers, or does DB unique constraint reject or handle it?
assertAdv($resDupPayer['code'] === 201 || $resDupPayer['code'] === 422, 'Duplicate payer in payers array handled cleanly', "Code: {$resDupPayer['code']}");

// B. Duplicate member ID in Splits array
$resDupSplit = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => 'Duplicate Split Test',
    'amount_cents' => 5000,
    'split_type' => 'EXACT',
    'payers' => [['member_id' => $rAlice, 'amount_cents' => 5000]],
    'splits' => [
        ['member_id' => $rBob, 'amount_cents' => 2500],
        ['member_id' => $rBob, 'amount_cents' => 2500] // Duplicate Bob
    ]
]);
assertAdv($resDupSplit['code'] === 201 || $resDupSplit['code'] === 422, 'Duplicate participant in splits array handled cleanly', "Code: {$resDupSplit['code']}");

// C. Single Payer shortcut with invalid payer_member_id (non-existent member 999999)
$resBadPayer = advHttpReq('POST', "/api/groups/$rToken/expenses", [
    'title' => 'Bad Payer ID Test',
    'amount_cents' => 5000,
    'split_type' => 'EQUAL',
    'payer_member_id' => 999999
]);
assertAdv($resBadPayer['code'] === 422, 'Non-existent payer_member_id rejected with 422', "Code: {$resBadPayer['code']}");

// -------------------------------------------------------------
// SECTION 4: DEBT GRAPH & CIRCULAR CYCLIC LEDGER RESOLUTION
// -------------------------------------------------------------
echo "\n--- 4. Circular Debt & Cyclic Graph Simplification ---\n";

// Create 5-person cyclical debt group: A -> B -> C -> D -> E -> A
$cycleGroup = advHttpReq('POST', '/api/groups', ['name' => 'Cyclic 5-Person Trip', 'creator_name' => 'User_A', 'currency' => 'INR']);
$cToken = $cycleGroup['body']['data']['group']['invite_token'];
$uA = $cycleGroup['body']['data']['creator']['id'];
$uB = advHttpReq('POST', "/api/groups/$cToken/members", ['name' => 'User_B'])['body']['data']['member']['id'];
$uC = advHttpReq('POST', "/api/groups/$cToken/members", ['name' => 'User_C'])['body']['data']['member']['id'];
$uD = advHttpReq('POST', "/api/groups/$cToken/members", ['name' => 'User_D'])['body']['data']['member']['id'];
$uE = advHttpReq('POST', "/api/groups/$cToken/members", ['name' => 'User_E'])['body']['data']['member']['id'];

// A pays ₹100 for B
advHttpReq('POST', "/api/groups/$cToken/expenses", [
    'title' => 'A pays for B', 'amount_cents' => 10000, 'split_type' => 'EXACT',
    'payers' => [['member_id' => $uA, 'amount_cents' => 10000]],
    'splits' => [['member_id' => $uB, 'amount_cents' => 10000]]
]);
// B pays ₹100 for C
advHttpReq('POST', "/api/groups/$cToken/expenses", [
    'title' => 'B pays for C', 'amount_cents' => 10000, 'split_type' => 'EXACT',
    'payers' => [['member_id' => $uB, 'amount_cents' => 10000]],
    'splits' => [['member_id' => $uC, 'amount_cents' => 10000]]
]);
// C pays ₹100 for D
advHttpReq('POST', "/api/groups/$cToken/expenses", [
    'title' => 'C pays for D', 'amount_cents' => 10000, 'split_type' => 'EXACT',
    'payers' => [['member_id' => $uC, 'amount_cents' => 10000]],
    'splits' => [['member_id' => $uD, 'amount_cents' => 10000]]
]);
// D pays ₹100 for E
advHttpReq('POST', "/api/groups/$cToken/expenses", [
    'title' => 'D pays for E', 'amount_cents' => 10000, 'split_type' => 'EXACT',
    'payers' => [['member_id' => $uD, 'amount_cents' => 10000]],
    'splits' => [['member_id' => $uE, 'amount_cents' => 10000]]
]);
// E pays ₹100 for A (Closing the 5-way cycle)
advHttpReq('POST', "/api/groups/$cToken/expenses", [
    'title' => 'E pays for A', 'amount_cents' => 10000, 'split_type' => 'EXACT',
    'payers' => [['member_id' => $uE, 'amount_cents' => 10000]],
    'splits' => [['member_id' => $uA, 'amount_cents' => 10000]]
]);

// Everyone paid ₹100 and consumed ₹100 -> Net balance for all 5 users MUST be exactly ₹0.00
$cycleBal = advHttpReq('GET', "/api/groups/$cToken/balances");
assertAdv($cycleBal['code'] === 200, '5-way cyclic balances computed successfully', "Code: {$cycleBal['code']}");
$allZero = true;
foreach ($cycleBal['body']['data']['members'] as $m) {
    if ($m['net_balance_cents'] !== 0) {
        $allZero = false;
        break;
    }
}
assertAdv($allZero, '5-way circular debt cancels out to exactly 0 net balance for all members');

// Settlement plan for closed cycle MUST produce 0 transactions
$cyclePlan = advHttpReq('GET', "/api/groups/$cToken/settlement-plan");
$txs = $cyclePlan['body']['data']['transactions'] ?? [];
assertAdv(count($txs) === 0, 'Debt simplification resolves 5-way cycle to 0 transactions', "Actual txs: " . count($txs));

// -------------------------------------------------------------
// SECTION 5: ADVANCED AUTHORIZATION & CROSS-RESOURCE ISOLATION
// -------------------------------------------------------------
echo "\n--- 5. Advanced IDOR & Cross-Group Attacks ---\n";

// Member A from Group 1 requests ledger of Member B in Group 2
$resCrossLedger = advHttpReq('GET', "/api/groups/$rToken/members/$uB/ledger");
assertAdv($resCrossLedger['code'] === 404 || $resCrossLedger['code'] === 500, 'Cross-group member ledger access blocked (cannot view foreign member ledger)', "Code: {$resCrossLedger['code']}");

// Record settlement with payer in Group 1 and payee in Group 2
$resCrossSettle = advHttpReq('POST', "/api/groups/$rToken/settlements", [
    'payer_id' => $rAlice,
    'payee_id' => $uB, // Belongs to cycleGroup, not rToken!
    'amount_cents' => 1000
]);
assertAdv($resCrossSettle['code'] === 422, 'Cross-group settlement payment blocked with 422', "Code: {$resCrossSettle['code']}");

// -------------------------------------------------------------
// SECTION 6: CONCURRENCY & DATABASE CONSISTENCY
// -------------------------------------------------------------
echo "\n--- 6. Concurrency & High-Speed Batch Persistence ---\n";

$batchGroup = advHttpReq('POST', '/api/groups', ['name' => 'Batch Concurrency Group', 'creator_name' => 'Worker_1', 'currency' => 'INR']);
$bToken = $batchGroup['body']['data']['group']['invite_token'];
$w1 = $batchGroup['body']['data']['creator']['id'];
$w2 = advHttpReq('POST', "/api/groups/$bToken/members", ['name' => 'Worker_2'])['body']['data']['member']['id'];

// Fire 20 rapid sequential expense creations simulating concurrent tabs
$successCount = 0;
for ($i = 1; $i <= 20; $i++) {
    $r = advHttpReq('POST', "/api/groups/$bToken/expenses", [
        'title' => "Batch Expense #$i",
        'amount_cents' => 1000 * $i,
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => ($i % 2 === 0 ? $w1 : $w2), 'amount_cents' => 1000 * $i]],
        'splits' => [
            ['member_id' => $w1, 'amount_cents' => 500 * $i],
            ['member_id' => $w2, 'amount_cents' => 500 * $i]
        ]
    ]);
    if ($r['code'] === 201) $successCount++;
}
assertAdv($successCount === 20, '20 high-speed sequential expenses persisted without concurrency lockups', "Success: $successCount/20");

// Verify zero-sum invariant holds on batch group
$batchBal = advHttpReq('GET', "/api/groups/$bToken/balances");
assertAdv(($batchBal['body']['data']['zero_sum_verified'] ?? false) === true, 'Zero-sum integrity verified after rapid batch insertions');

// -------------------------------------------------------------
// SECTION 7: FAILURE RECOVERY & SOFT-DELETE ROLLBACK
// -------------------------------------------------------------
echo "\n--- 7. Soft Delete & Balance Restoration Integrity ---\n";

// Get all expenses in batch group
$expList = advHttpReq('GET', "/api/groups/$bToken/expenses");
$expenses = $expList['body']['data']['expenses'] ?? [];
$targetExp = $expenses[0]['id'] ?? 0;

// Soft delete first expense
$resDelExp = advHttpReq('DELETE', "/api/groups/$bToken/expenses/$targetExp");
assertAdv($resDelExp['code'] === 200, "Expense #$targetExp successfully soft-deleted", "Code: {$resDelExp['code']}");

// Attempt to soft-delete the SAME expense again (Idempotency / Already deleted check)
$resDelAgain = advHttpReq('DELETE', "/api/groups/$bToken/expenses/$targetExp");
assertAdv($resDelAgain['code'] === 404, 'Deleting already-deleted expense returns 404', "Code: {$resDelAgain['code']}");

// Check that deleted expense is excluded from balances and spend total
$balAfterDel = advHttpReq('GET', "/api/groups/$bToken/balances");
$totalSpendAfter = $balAfterDel['body']['data']['total_spending_cents'] ?? 0;
// Total before was sum of 1000..20000 = 210,000. Deleted expense was #20 (20,000 paise). After should be 190,000.
assertAdv($totalSpendAfter === 190000, "Total spend reduced from 210,000 to 190,000 paise (actual: $totalSpendAfter)");

echo "\n====================================================================\n";
echo " ROUND 2 RESULTS: {$stats['passed']} Passed, {$stats['failed']} Failed (Total: {$stats['total']})\n";
echo "====================================================================\n";
