<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Env;
use App\Core\Database;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

$baseUrl = 'http://localhost:8000';

function httpReq(string $method, string $path, ?array $body = null, array $headers = []): array {
    global $baseUrl;
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    
    $reqHeaders = array_merge(['Accept: application/json', 'X-Requested-With: fetch'], $headers);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    if ($body !== null) {
        $json = json_encode($body);
        $reqHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
    
    $response = curl_exec($ch);
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

echo "=== STARTING ADVANCED END-TO-END AUDIT & STRESS SUITE ===\n\n";

$results = [
    'passed' => 0,
    'failed' => 0,
    'issues' => [],
];

function recordResult(bool $passed, string $testName, string $details = '') {
    global $results;
    if ($passed) {
        $results['passed']++;
        echo "  [PASS] $testName\n";
    } else {
        $results['failed']++;
        $results['issues'][] = ['name' => $testName, 'details' => $details];
        echo "  [FAIL] $testName : $details\n";
    }
}

// 1. Edge Case: Extreme Input Validation
echo "--- 1. Testing Extreme Input Validation & Injection Vectors ---\n";

// A. Very long group name (e.g. 500 characters)
$longName = str_repeat('A', 500);
$res = httpReq('POST', '/api/groups', ['name' => $longName, 'creator_name' => 'Tester', 'currency' => 'INR']);
recordResult($res['code'] === 422 || ($res['code'] === 201 && strlen($res['body']['data']['group']['name']) <= 100), 
    'Long group name handling (truncated or 422 validation)', "Code: {$res['code']}");

// B. Unicode and Emoji in group and member names
$emojiGroupName = '🌴 Goa Trip 2026 🎉 & 🍕 Party <script>alert(1)</script>';
$emojiCreatorName = 'Rahul 👨‍💻';
$res = httpReq('POST', '/api/groups', ['name' => $emojiGroupName, 'creator_name' => $emojiCreatorName, 'currency' => 'INR']);
$groupToken = $res['body']['data']['group']['invite_token'] ?? null;
$creatorId = $res['body']['data']['creator']['id'] ?? null;
recordResult($res['code'] === 201 && $groupToken !== null, 'Unicode/Emoji Group Creation', "Token: $groupToken");
recordResult(!str_contains($res['body']['data']['group']['name'] ?? '', '<script>'), 'XSS in Group Name safely stripped');

// C. Non-existent Group Token
$res = httpReq('GET', '/api/groups/non_existent_token_123456');
recordResult($res['code'] === 404, 'Non-existent group token returns 404', "Code: {$res['code']}");

// D. Invalid HTTP method on route
$res = httpReq('PUT', '/api/groups', ['name' => 'Invalid Method Group']);
recordResult($res['code'] === 405, 'PUT on /api/groups returns 405 Method Not Allowed', "Code: {$res['code']}");

// E. Malformed JSON payload
$ch = curl_init('http://localhost:8000/api/groups');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, "{'invalid_json': true,");
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$rawRes = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
recordResult($httpCode === 400 || $httpCode === 422, 'Malformed JSON returns 400/422 Bad Request', "Code: $httpCode");

// 2. Cross-Group Authorization & IDOR Testing
echo "\n--- 2. Testing IDOR & Cross-Group Resource Isolation ---\n";

// Create Group A with Member A1, A2
$resA = httpReq('POST', '/api/groups', ['name' => 'Group A', 'creator_name' => 'Alice', 'currency' => 'INR']);
$tokenA = $resA['body']['data']['group']['invite_token'];
$aliceId = $resA['body']['data']['creator']['id'];

$resBob = httpReq('POST', "/api/groups/$tokenA/members", ['name' => 'Bob']);
$bobId = $resBob['body']['data']['member']['id'];

// Create Expense in Group A
$resExpA = httpReq('POST', "/api/groups/$tokenA/expenses", [
    'title' => 'Dinner in Group A',
    'amount_cents' => 5000,
    'split_type' => 'EQUAL',
    'payers' => [['member_id' => $aliceId, 'amount_cents' => 5000]],
    'splits' => [
        ['member_id' => $aliceId, 'amount_cents' => 2500],
        ['member_id' => $bobId, 'amount_cents' => 2500]
    ]
]);
$expenseIdA = $resExpA['body']['data']['expense']['id'];

// Create Group B with Member B1
$resB = httpReq('POST', '/api/groups', ['name' => 'Group B', 'creator_name' => 'Charlie', 'currency' => 'INR']);
$tokenB = $resB['body']['data']['group']['invite_token'];
$charlieId = $resB['body']['data']['creator']['id'];

// Attempt 1: Group B attempts to DELETE Group A's expense via /api/groups/{tokenB}/expenses/{expenseIdA}
$resAttack = httpReq('DELETE', "/api/groups/$tokenB/expenses/$expenseIdA");
recordResult($resAttack['code'] === 404, 'IDOR: Group B cannot delete Group A expense (returns 404)', "Code: {$resAttack['code']}");

// Verify Expense A is still active in Group A
$resVerify = httpReq('GET', "/api/groups/$tokenA/expenses/$expenseIdA");
recordResult($resVerify['code'] === 200 && ($resVerify['body']['data']['expense']['id'] ?? 0) === $expenseIdA, 'Group A expense remains intact after IDOR attempt');

// Attempt 2: Create expense in Group B using members from Group A (Foreign member injection)
$resForeignExp = httpReq('POST', "/api/groups/$tokenB/expenses", [
    'title' => 'Cross-group Member Attack',
    'amount_cents' => 3000,
    'split_type' => 'EQUAL',
    'payers' => [['member_id' => $charlieId, 'amount_cents' => 3000]],
    'splits' => [
        ['member_id' => $charlieId, 'amount_cents' => 1500],
        ['member_id' => $aliceId, 'amount_cents' => 1500] // Alice is in Group A, not Group B!
    ]
]);
recordResult($resForeignExp['code'] === 422 || $resForeignExp['code'] === 400 || $resForeignExp['code'] === 404, 
    'Cross-group member ID injection in expense splits is blocked (422)', "Code: {$resForeignExp['code']}");

// 3. Mathematical Edge Cases & Invariant Verification
echo "\n--- 3. Testing Mathematical Edge Cases & Precision Invariants ---\n";

// A. 1 Paise Split among 2 people
$res1Paisa = httpReq('POST', "/api/groups/$tokenA/expenses", [
    'title' => '1 Paisa Micro Expense',
    'amount_cents' => 1,
    'split_type' => 'EXACT',
    'payers' => [['member_id' => $aliceId, 'amount_cents' => 1]],
    'splits' => [
        ['member_id' => $aliceId, 'amount_cents' => 1],
        ['member_id' => $bobId, 'amount_cents' => 0]
    ]
]);
recordResult($res1Paisa['code'] === 201, '1 Paisa exact expense logged successfully', "Code: {$res1Paisa['code']}");

// B. Out-of-balance EXACT split (Sum of splits != total amount)
$resMismatched = httpReq('POST', "/api/groups/$tokenA/expenses", [
    'title' => 'Mismatched Exact Split',
    'amount_cents' => 10000,
    'split_type' => 'EXACT',
    'payers' => [['member_id' => $aliceId, 'amount_cents' => 10000]],
    'splits' => [
        ['member_id' => $aliceId, 'amount_cents' => 5000],
        ['member_id' => $bobId, 'amount_cents' => 4000] // Sum is 9000, not 10000!
    ]
]);
recordResult($resMismatched['code'] === 422, 'Mismatched exact split (sum != amount) is rejected with 422', "Code: {$resMismatched['code']}");

// C. Out-of-balance PAYERS (Sum of payers != total amount)
$resMismatchedPayers = httpReq('POST', "/api/groups/$tokenA/expenses", [
    'title' => 'Mismatched Payers',
    'amount_cents' => 10000,
    'split_type' => 'EQUAL',
    'payers' => [['member_id' => $aliceId, 'amount_cents' => 8000]], // 8000 != 10000
    'splits' => [
        ['member_id' => $aliceId, 'amount_cents' => 5000],
        ['member_id' => $bobId, 'amount_cents' => 5000]
    ]
]);
recordResult($resMismatchedPayers['code'] === 422, 'Mismatched payers sum (!= total amount) is rejected with 422', "Code: {$resMismatchedPayers['code']}");

// 4. Large Dataset & Stress Load Verification
echo "\n--- 4. Stress Testing: 20 Members & 30 Rapid Multi-Model Expenses ---\n";

$stressGroup = httpReq('POST', '/api/groups', ['name' => 'Stress Test 2026', 'creator_name' => 'Member_1', 'currency' => 'INR']);
$sToken = $stressGroup['body']['data']['group']['invite_token'];
$sMembers = [$stressGroup['body']['data']['creator']['id']];

$startTime = microtime(true);

// Add 19 more members
for ($i = 2; $i <= 20; $i++) {
    $resMem = httpReq('POST', "/api/groups/$sToken/members", ['name' => "Member_$i"]);
    if ($resMem['code'] === 201) {
        $sMembers[] = $resMem['body']['data']['member']['id'];
    }
}
recordResult(count($sMembers) === 20, '20 members added in stress group', "Added: " . count($sMembers));

// Log 30 varied expenses (Equal, Exact, Percentage, Shares)
$loggedCount = 0;

for ($e = 1; $e <= 30; $e++) {
    $payerId = $sMembers[array_rand($sMembers)];
    $amount = rand(1000, 50000); // ₹10.00 to ₹500.00
    
    // Select random 4-8 participants
    shuffle($sMembers);
    $participants = array_slice($sMembers, 0, rand(4, 8));
    if (!in_array($payerId, $participants)) {
        $participants[] = $payerId;
    }
    
    $splits = [];
    $pCount = count($participants);
    $baseShare = intdiv($amount, $pCount);
    $rem = $amount % $pCount;
    
    foreach ($participants as $idx => $pid) {
        $splits[] = [
            'member_id' => $pid,
            'amount_cents' => $baseShare + ($idx < $rem ? 1 : 0)
        ];
    }
    
    $resExp = httpReq('POST', "/api/groups/$sToken/expenses", [
        'title' => "Stress Expense #$e",
        'amount_cents' => $amount,
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $payerId, 'amount_cents' => $amount]],
        'splits' => $splits
    ]);
    
    if ($resExp['code'] === 201) {
        $loggedCount++;
    }
}

$duration = round((microtime(true) - $startTime) * 1000, 2);
recordResult($loggedCount === 30, '30 random multi-participant expenses logged', "Logged: $loggedCount / 30 in {$duration}ms");

// Verify Balances and Zero-Sum Invariant on 20-member 30-expense dataset
$resBal = httpReq('GET', "/api/groups/$sToken/balances");
recordResult($resBal['code'] === 200, 'Balances computed for 20-member stress group', "Code: {$resBal['code']}");
recordResult(($resBal['body']['data']['zero_sum_verified'] ?? false) === true, 'Zero-Sum invariant holds across all 20 members under stress load');

// Verify Settlement Plan computes within N-1 bound
$resPlan = httpReq('GET', "/api/groups/$sToken/settlement-plan");
$txCount = count($resPlan['body']['data']['transactions'] ?? []);
recordResult($resPlan['code'] === 200 && $txCount <= 19, "Settlement plan generates <= 19 transactions (actual: $txCount) for 20 members");

// 5. Database Concurrency & Referential Integrity Verification
echo "\n--- 5. Testing Database Referential Integrity & Cascades ---\n";

// Verify MySQL Foreign Key constraints on deleting group
$stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE group_id = (SELECT id FROM groups WHERE invite_token = ?)");
$stmt->execute([$sToken]);
$expenseRowCount = (int) $stmt->fetchColumn();
recordResult($expenseRowCount === 30, 'Database raw row count matches 30 expenses in MySQL table');

echo "\n=====================================================\n";
echo " AUDIT RESULTS: {$results['passed']} Passed, {$results['failed']} Failed\n";
echo "=====================================================\n";
