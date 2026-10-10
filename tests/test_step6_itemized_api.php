<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Env;
use App\Core\Database;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

$baseUrl = 'http://127.0.0.1:8000';

function postJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

function putJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

function getJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

echo "\n====================================================================\n";
echo " STEP 6: ITEMIZED EXPENSE COMPOSER & API END-TO-END VALIDATION\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

$assertCheck = function (bool $condition, string $msg) use (&$passed, &$failed) {
    if ($condition) {
        echo "  [PASS] {$msg}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failed++;
    }
};

// 1. Create Group
$grpRes = postJson("{$baseUrl}/api/groups", [
    'name' => 'Itemized Gourmet Dinner Group',
    'creator_name' => 'Aarav Sharma',
    'currency' => 'INR'
]);
$assertCheck($grpRes['status'] === 201, "Created test group successfully");
$groupToken = $grpRes['body']['data']['group']['invite_token'];
$creatorId = $grpRes['body']['data']['creator']['id'];

// 2. Add 2 More Members
$m2Res = postJson("{$baseUrl}/api/groups/{$groupToken}/members", ['name' => 'Diya Patel']);
$m2Id = $m2Res['body']['data']['member']['id'];

$m3Res = postJson("{$baseUrl}/api/groups/{$groupToken}/members", ['name' => 'Rohan Varma']);
$m3Id = $m3Res['body']['data']['member']['id'];

$assertCheck(isset($m2Id) && isset($m3Id), "Added Diya and Rohan to group");

// 3. Create ITEMIZED Expense
$itemsPayload = [
    [
        'name' => 'Artisan Sourdough Pizza',
        'amount_cents' => 60000,
        'sort_order' => 0,
        'member_ids' => [$creatorId, $m2Id] // Aarav & Diya (30000 each)
    ],
    [
        'name' => 'Wild Mushroom Risotto',
        'amount_cents' => 50000,
        'sort_order' => 1,
        'member_ids' => [$m2Id, $m3Id] // Diya & Rohan (25000 each)
    ],
    [
        'name' => 'Espresso Martini',
        'amount_cents' => 30000,
        'sort_order' => 2,
        'member_ids' => [$creatorId] // Aarav only (30000)
    ]
];
// Base subtotal = 60000 + 50000 + 30000 = 140000 paise
// Aarav base = 30000 + 30000 = 60000
// Diya base = 30000 + 25000 = 55000
// Rohan base = 25000
$taxCents = 14000;      // +14000
$tipCents = 10000;      // +10000
$discountCents = 4000;  // -4000
// Net surcharge = 14000 + 10000 - 4000 = +20000
// Total amount = 140000 + 20000 = 160000 paise

$expCreateRes = postJson("{$baseUrl}/api/groups/{$groupToken}/expenses", [
    'title' => 'Celebration Dinner at The Olive Room',
    'category_id' => 2,
    'total_amount_cents' => 160000,
    'split_type' => 'ITEMIZED',
    'expense_date' => date('Y-m-d'),
    'paid_by_member_id' => $creatorId,
    'notes' => 'Table #14, Birthday special voucher applied',
    'tax_cents' => $taxCents,
    'tip_cents' => $tipCents,
    'discount_cents' => $discountCents,
    'items' => $itemsPayload
]);

$assertCheck($expCreateRes['status'] === 201, "Created ITEMIZED expense successfully (HTTP 201)");
$expenseId = $expCreateRes['body']['data']['expense']['id'];
$expData = $expCreateRes['body']['data']['expense'];

$assertCheck($expData['split_type'] === 'ITEMIZED', "Expense split_type is ITEMIZED");
$assertCheck((int)$expData['total_amount_cents'] === 160000, "Expense total amount is exactly 160,000 paise");
$assertCheck((int)$expData['tax_cents'] === 14000, "Expense tax_cents is 14,000 paise");
$assertCheck((int)$expData['tip_cents'] === 10000, "Expense tip_cents is 10,000 paise");
$assertCheck((int)$expData['discount_cents'] === 4000, "Expense discount_cents is 4,000 paise");
$assertCheck($expData['notes'] === 'Table #14, Birthday special voucher applied', "Notes preserved accurately");

// 4. Verify line items returned in show API
$showRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses/{$expenseId}");
$assertCheck($showRes['status'] === 200, "Fetched single expense details (HTTP 200)");
$fetchedItems = $showRes['body']['data']['expense']['items'] ?? [];
$assertCheck(count($fetchedItems) === 3, "All 3 line items returned in show response");
$assertCheck($fetchedItems[0]['name'] === 'Artisan Sourdough Pizza', "First line item name is accurate");
$assertCheck(count($fetchedItems[0]['assigned_members']) === 2, "First line item has 2 assigned members");

// 5. Verify Split Allocation & Zero-Sum Exactness
$splits = $showRes['body']['data']['expense']['splits'];
$sumOwed = array_sum(array_column($splits, 'amount_owed_cents'));
$assertCheck($sumOwed === 160000, "Sum of participant splits strictly equals 160,000 paise");

// Check proportions:
$aaravSplit = 0; $diyaSplit = 0; $rohanSplit = 0;
foreach ($splits as $s) {
    if ($s['member_id'] === $creatorId) $aaravSplit = $s['amount_owed_cents'];
    if ($s['member_id'] === $m2Id) $diyaSplit = $s['amount_owed_cents'];
    if ($s['member_id'] === $m3Id) $rohanSplit = $s['amount_owed_cents'];
}
$assertCheck($aaravSplit === 68571, "Aarav proportional allocation matches largest remainder calculation (₹685.71)");
$assertCheck($diyaSplit === 62857, "Diya proportional allocation matches largest remainder calculation (₹628.57)");
$assertCheck($rohanSplit === 28572, "Rohan proportional allocation matches largest remainder calculation (₹285.72)");
$assertCheck(($aaravSplit + $diyaSplit + $rohanSplit) === 160000, "Total allocated splits sum precisely to 160,000 paise without rounding leak");

// 6. Test Updating Itemized Expense
$updatedItemsPayload = [
    [
        'name' => 'Artisan Sourdough Pizza (2x)',
        'amount_cents' => 120000,
        'sort_order' => 0,
        'member_ids' => [$creatorId, $m2Id, $m3Id]
    ]
];
$updateRes = putJson("{$baseUrl}/api/groups/{$groupToken}/expenses/{$expenseId}", [
    'title' => 'Celebration Pizza Party (Updated)',
    'category_id' => 2,
    'total_amount_cents' => 120000,
    'split_type' => 'ITEMIZED',
    'expense_date' => date('Y-m-d'),
    'paid_by_member_id' => $creatorId,
    'tax_cents' => 0,
    'tip_cents' => 0,
    'discount_cents' => 0,
    'notes' => 'Updated itemized bill',
    'items' => $updatedItemsPayload
]);
$assertCheck($updateRes['status'] === 200, "Updated ITEMIZED expense successfully (HTTP 200)");
$upItems = $updateRes['body']['data']['expense']['items'] ?? [];
$assertCheck(count($upItems) === 1, "Updated line items count is 1");
$assertCheck((int)$updateRes['body']['data']['expense']['total_amount_cents'] === 120000, "Updated total amount is 120,000 paise");

// 7. Verify Bilateral Balances & Settlements with Itemized Expense
$balRes = getJson("{$baseUrl}/api/groups/{$groupToken}/balances");
$assertCheck($balRes['status'] === 200, "Retrieved balances with itemized expense");
$zeroSumCheck = array_sum(array_column($balRes['body']['data']['members'], 'net_balance_cents'));
$assertCheck($zeroSumCheck === 0, "Group net balances remain zero-sum strictly after itemized calculation");

echo "\n====================================================================\n";
echo " STEP 6 VALIDATION RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n\n";

if ($failed > 0) {
    exit(1);
}
