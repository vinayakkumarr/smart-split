<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Env;
use App\Core\Database;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

$baseUrl = 'http://localhost:8000';

function postJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

function getJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

echo "\n====================================================================\n";
echo " STEP 8: MULTI-DIMENSIONAL SEARCH & FILTERING REST API VALIDATION\n";
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

// 1. Create a Test Group
$groupRes = postJson("{$baseUrl}/api/groups", [
    'name' => 'Search & Filter Test Group ' . uniqid(),
    'currency' => 'INR',
    'creator_name' => 'Dev',
]);

$assertCheck($groupRes['status'] === 201, "Created test group successfully");
$groupToken = $groupRes['body']['data']['group']['invite_token'];
$devId = $groupRes['body']['data']['creator']['id'];

// 2. Add members
$m1 = postJson("{$baseUrl}/api/groups/{$groupToken}/members", ['name' => 'Kavya']);
$kavyaId = $m1['body']['data']['member']['id'];

$m2 = postJson("{$baseUrl}/api/groups/{$groupToken}/members", ['name' => 'Siddharth']);
$sidId = $m2['body']['data']['member']['id'];

$assertCheck($kavyaId > 0 && $sidId > 0, "Added Kavya and Siddharth to group");

// 3. Populate 5 Diverse Expenses with different dates, categories, split types, and amounts
$e1 = postJson("{$baseUrl}/api/groups/{$groupToken}/expenses", [
    'title' => 'Weekly Supermarket Haul',
    'total_amount_cents' => 200000,
    'split_type' => 'EQUAL',
    'category_id' => 6, // Groceries
    'expense_date' => '2026-08-15',
    'paid_by_member_id' => $devId,
    'split_members' => [$devId, $kavyaId, $sidId],
    'notes' => 'Organic vegetables and dairy'
]);

$e2 = postJson("{$baseUrl}/api/groups/{$groupToken}/expenses", [
    'title' => 'Italian Pasta Night',
    'total_amount_cents' => 450000,
    'split_type' => 'EXACT',
    'category_id' => 2, // Food & Dining
    'expense_date' => '2026-09-05',
    'paid_by_member_id' => $kavyaId,
    'splits' => [
        ['member_id' => $devId, 'amount_cents' => 150000],
        ['member_id' => $kavyaId, 'amount_cents' => 150000],
        ['member_id' => $sidId, 'amount_cents' => 150000],
    ],
    'notes' => 'Truffle pasta and tiramisu'
]);

$e3 = postJson("{$baseUrl}/api/groups/{$groupToken}/expenses", [
    'title' => 'Goa Flight Tickets',
    'total_amount_cents' => 1800000,
    'split_type' => 'EQUAL',
    'category_id' => 3, // Travel & Transport
    'expense_date' => '2026-09-10',
    'paid_by_member_id' => $sidId,
    'split_members' => [$devId, $sidId],
    'notes' => 'Indigo round trip'
]);

$e4 = postJson("{$baseUrl}/api/groups/{$groupToken}/expenses", [
    'title' => 'Airtel Fiber Broadband',
    'total_amount_cents' => 120000,
    'split_type' => 'EQUAL',
    'category_id' => 5, // Utilities & Bills
    'expense_date' => '2026-09-15',
    'paid_by_member_id' => $devId,
    'split_members' => [$devId, $kavyaId, $sidId],
    'notes' => 'Monthly 300Mbps plan'
]);

$e5 = postJson("{$baseUrl}/api/groups/{$groupToken}/expenses", [
    'title' => 'Artisan Cafe Brunch',
    'split_type' => 'ITEMIZED',
    'category_id' => 2, // Food & Dining
    'expense_date' => '2026-09-18',
    'paid_by_member_id' => $kavyaId,
    'tax_cents' => 10000,
    'tip_cents' => 10000,
    'discount_cents' => 0,
    'items' => [
        ['name' => 'Avocado Toast', 'amount_cents' => 80000, 'member_ids' => [$devId, $kavyaId]],
        ['name' => 'Matcha Latte', 'amount_cents' => 60000, 'member_ids' => [$kavyaId, $sidId]],
    ],
    'notes' => 'Special breakfast blend'
]);

echo "\n--- 1. Testing Unfiltered Master Query ---\n";
$allRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses");
$assertCheck($allRes['status'] === 200, "Unfiltered GET /expenses returns 200 OK");
$assertCheck(count($allRes['body']['data']['expenses']) === 5, "Total 5 expenses returned unfiltered");

echo "\n--- 2. Testing Text Search Filter ---\n";
// Search by 'pasta'
$searchRes1 = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?search=pasta");
$assertCheck(count($searchRes1['body']['data']['expenses']) === 1, "Search 'pasta' returns exactly 1 match (Italian Pasta)");
$assertCheck($searchRes1['body']['data']['expenses'][0]['title'] === 'Italian Pasta Night', "Search matched correct title");

// Search by note content 'Indigo'
$searchRes2 = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?search=Indigo");
$assertCheck(count($searchRes2['body']['data']['expenses']) === 1, "Search note 'Indigo' returns exactly 1 match (Flight)");

echo "\n--- 3. Testing Category Filter ---\n";
// Category 2 = Food & Dining (Expect 2 expenses: Italian Pasta and Artisan Cafe Brunch)
$catRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?category_id=2");
$assertCheck(count($catRes['body']['data']['expenses']) === 2, "Category 'Food & Dining' returns exactly 2 expenses");

// Category 6 = Groceries (Expect 1 expense)
$grocRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?category_id=6");
$assertCheck(count($grocRes['body']['data']['expenses']) === 1, "Category 'Groceries' returns exactly 1 expense");

echo "\n--- 4. Testing Date Range Filter ---\n";
// August 2026 (Expect 1: Supermarket on 2026-08-15)
$augRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?from_date=2026-08-01&to_date=2026-08-31");
$assertCheck(count($augRes['body']['data']['expenses']) === 1, "Date range August 2026 returns 1 expense");

// September 2026 (Expect 4: 09-05, 09-10, 09-15, 09-18)
$sepRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?from_date=2026-09-01&to_date=2026-09-30");
$assertCheck(count($sepRes['body']['data']['expenses']) === 4, "Date range September 2026 returns 4 expenses");

echo "\n--- 5. Testing Split Type Filter ---\n";
$itemizedRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?split_type=ITEMIZED");
$assertCheck(count($itemizedRes['body']['data']['expenses']) === 1, "Split type 'ITEMIZED' returns exactly 1 expense");
$assertCheck($itemizedRes['body']['data']['expenses'][0]['title'] === 'Artisan Cafe Brunch', "Itemized expense matches title");
$assertCheck(count($itemizedRes['body']['data']['expenses'][0]['items'] ?? []) === 2, "Itemized expense has 2 line items attached");

echo "\n--- 6. Testing Member, Payer & Debtor Filters ---\n";
// Payer = Siddharth (sidId paid for Goa Flight Tickets)
$payerRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?payer_id={$sidId}");
$assertCheck(count($payerRes['body']['data']['expenses']) === 1, "Payer Siddharth returns 1 expense (Flight Tickets)");

// Debtor = Kavya (Kavya is a debtor in 4 expenses: Supermarket, Pasta, Broadband, Brunch)
$debtorRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?debtor_id={$kavyaId}");
$assertCheck(count($debtorRes['body']['data']['expenses']) === 4, "Debtor Kavya returns 4 expenses");

// Involving Member = Siddharth (paid Flight, participant in Supermarket, Pasta, Broadband, Brunch)
$memberRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?member_id={$sidId}");
$assertCheck(count($memberRes['body']['data']['expenses']) === 5, "Participant Siddharth returns all 5 expenses");

echo "\n--- 7. Testing Amount Bounds Filter ---\n";
// Min Amount >= ₹4,000 (400,000 paise: Expect Pasta ₹4,500 and Flights ₹18,000 = 2)
$amountRes1 = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?min_amount_cents=400000");
$assertCheck(count($amountRes1['body']['data']['expenses']) === 2, "Min amount >= ₹4,000 returns 2 expenses");

// Max Amount <= ₹1,500 (150,000 paise: Expect Broadband ₹1,200 = 1)
$amountRes2 = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses?max_amount_cents=150000");
$assertCheck(count($amountRes2['body']['data']['expenses']) === 1, "Max amount <= ₹1,500 returns 1 expense");

echo "\n====================================================================\n";
echo " STEP 8 VALIDATION RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n\n";

if ($failed > 0) {
    exit(1);
}
