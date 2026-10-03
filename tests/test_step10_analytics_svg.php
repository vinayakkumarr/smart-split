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
echo " STEP 10: FINANCIAL INTELLIGENCE & VISUAL SPEND ANALYTICS TEST\n";
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

try {
    // 1. Create a Test Group
    $grpRes = postJson("{$baseUrl}/api/groups", [
        'name' => 'Analytics Test Expedition ' . time(),
        'creator_name' => 'Alice Organizer',
        'currency' => 'INR',
    ]);
    $assertCheck($grpRes['status'] === 201, "Created test group for analytics (Status: {$grpRes['status']})");
    $token = $grpRes['body']['data']['group']['invite_token'];
    $creatorId = $grpRes['body']['data']['creator']['id'];

    // 2. Add More Members
    $m2 = postJson("{$baseUrl}/api/groups/{$token}/members", ['name' => 'Bob Builder']);
    $bobId = $m2['body']['data']['member']['id'];
    $m3 = postJson("{$baseUrl}/api/groups/{$token}/members", ['name' => 'Charlie Chef']);
    $charlieId = $m3['body']['data']['member']['id'];
    $m4 = postJson("{$baseUrl}/api/groups/{$token}/members", ['name' => 'Dana Driver']);
    $danaId = $m4['body']['data']['member']['id'];

    $assertCheck($danaId > 0, "Added 4 members to test group");

    // 3. Test Analytics on Empty Group
    $emptyAnalytics = getJson("{$baseUrl}/api/groups/{$token}/analytics/summary");
    $assertCheck($emptyAnalytics['status'] === 200, "Empty group analytics returns 200 OK");
    $emptyData = $emptyAnalytics['body']['data']['analytics'];
    $assertCheck($emptyData['total_spending_cents'] === 0, "Empty group total spend is 0");
    $assertCheck(empty($emptyData['categories']), "Empty group category breakdown is empty");
    $assertCheck(empty($emptyData['daily_trends']), "Empty group daily trends is empty");
    $assertCheck(count($emptyData['member_outlay']) === 4, "Member outlay includes all 4 members with zero balances");

    // 4. Create System & Custom Categories
    $foodCat = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'food_dining' LIMIT 1")->fetchColumn();
    $transCat = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'travel_transport' LIMIT 1")->fetchColumn();

    $customCatRes = postJson("{$baseUrl}/api/groups/{$token}/categories", [
        'name' => 'Scuba Diving',
        'icon' => '🤿',
        'color_hex' => '#0284c7',
    ]);
    $scubaCatId = $customCatRes['body']['data']['category']['id'];
    $assertCheck($scubaCatId > 0, "Created custom category 'Scuba Diving' (ID: {$scubaCatId})");

    // 5. Create Expenses across Multiple Dates and Categories
    // Date 1: 2026-09-10 - Alice pays ₹10,000 for Scuba Diving split equally 4 ways
    $exp1 = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Deep Reef Diving',
        'total_amount_cents' => 1000000,
        'expense_date' => '2026-09-10',
        'category_id' => $scubaCatId,
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $creatorId, 'amount_paid_cents' => 1000000]],
        'participants' => [
            ['member_id' => $creatorId],
            ['member_id' => $bobId],
            ['member_id' => $charlieId],
            ['member_id' => $danaId],
        ],
    ]);
    $assertCheck($exp1['status'] === 201, "Created Expense 1: ₹10,000 Scuba Diving on 2026-09-10");

    // Date 2: 2026-09-11 - Bob pays ₹4,000 for Food & Dining
    $exp2 = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Seafood Dinner',
        'total_amount_cents' => 400000,
        'expense_date' => '2026-09-11',
        'category_id' => $foodCat,
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $bobId, 'amount_paid_cents' => 400000]],
        'participants' => [
            ['member_id' => $creatorId],
            ['member_id' => $bobId],
            ['member_id' => $charlieId],
            ['member_id' => $danaId],
        ],
    ]);
    $assertCheck($exp2['status'] === 201, "Created Expense 2: ₹4,000 Food on 2026-09-11");

    // Date 2: 2026-09-11 - Dana pays ₹2,000 for Transport
    $exp3 = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Island Ferry',
        'total_amount_cents' => 200000,
        'expense_date' => '2026-09-11',
        'category_id' => $transCat,
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $danaId, 'amount_paid_cents' => 200000]],
        'participants' => [
            ['member_id' => $creatorId],
            ['member_id' => $bobId],
            ['member_id' => $charlieId],
            ['member_id' => $danaId],
        ],
    ]);
    $assertCheck($exp3['status'] === 201, "Created Expense 3: ₹2,000 Transport on 2026-09-11");

    // Date 3: 2026-09-12 - Charlie pays ₹6,000 for Food
    $exp4 = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Resort Lunch Buffet',
        'total_amount_cents' => 600000,
        'expense_date' => '2026-09-12',
        'category_id' => $foodCat,
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $charlieId, 'amount_paid_cents' => 600000]],
        'participants' => [
            ['member_id' => $creatorId],
            ['member_id' => $bobId],
            ['member_id' => $charlieId],
            ['member_id' => $danaId],
        ],
    ]);
    $assertCheck($exp4['status'] === 201, "Created Expense 4: ₹6,000 Food on 2026-09-12");

    // 6. Fetch Analytics Summary API
    $analyticsRes = getJson("{$baseUrl}/api/groups/{$token}/analytics/summary");
    $assertCheck($analyticsRes['status'] === 200, "Fetched populated analytics summary (200 OK)");
    $analytics = $analyticsRes['body']['data']['analytics'];

    $assertCheck($analytics['total_spending_cents'] === 2200000, "Total spend matches expected ₹22,000.00 (2200000 cents)");
    $assertCheck($analytics['total_transactions_count'] === 4, "Total transaction count is 4");
    $assertCheck($analytics['average_daily_spend_cents'] === 733333, "Average daily velocity is 733,333 cents (₹7,333.33/day)");

    // 7. Verify Category Breakdown
    $categories = $analytics['categories'];
    $assertCheck(count($categories) === 3, "Category breakdown contains exactly 3 active categories");
    $catNames = array_column($categories, 'name');
    $assertCheck(in_array('Scuba Diving', $catNames) && in_array('Food & Dining', $catNames) && in_array('Travel & Transport', $catNames), "All 3 categories present in breakdown");

    $topCat = $analytics['highest_category'];
    $assertCheck($topCat !== null && ($topCat['name'] === 'Scuba Diving' || $topCat['name'] === 'Food & Dining'), "Highest category correctly identified: {$topCat['name']}");

    // 8. Verify Daily Trends (Burn Velocity)
    $dailyTrends = $analytics['daily_trends'];
    $assertCheck(count($dailyTrends) === 3, "Daily trends contains 3 distinct active dates");
    $assertCheck($dailyTrends[0]['date'] === '2026-09-10' && $dailyTrends[0]['spent_cents'] === 1000000, "Day 1 (2026-09-10) spend is ₹10,000");
    $assertCheck($dailyTrends[1]['date'] === '2026-09-11' && $dailyTrends[1]['spent_cents'] === 600000 && $dailyTrends[1]['count'] === 2, "Day 2 (2026-09-11) spend is ₹6,000 across 2 transactions");
    $assertCheck($dailyTrends[2]['date'] === '2026-09-12' && $dailyTrends[2]['spent_cents'] === 600000, "Day 3 (2026-09-12) spend is ₹6,000");

    // 9. Verify Member Outlay vs Net Consumption
    $memberOutlays = $analytics['member_outlay'];
    $assertCheck(count($memberOutlays) === 4, "Member outlay contains all 4 participants");

    $topFunder = $analytics['top_funder'];
    $assertCheck($topFunder['name'] === 'Alice Organizer' && $topFunder['paid_cents'] === 1000000, "Top funder is Alice with ₹10,000 paid upfront");

    $aliceOutlay = current(array_filter($memberOutlays, fn($m) => $m['name'] === 'Alice Organizer'));
    $bobOutlay = current(array_filter($memberOutlays, fn($m) => $m['name'] === 'Bob Builder'));
    $charlieOutlay = current(array_filter($memberOutlays, fn($m) => $m['name'] === 'Charlie Chef'));
    $danaOutlay = current(array_filter($memberOutlays, fn($m) => $m['name'] === 'Dana Driver'));

    $assertCheck($aliceOutlay['paid_cents'] === 1000000 && $aliceOutlay['owed_cents'] === 550000 && $aliceOutlay['net_balance_cents'] === 450000, "Alice: Paid ₹10k, Owed ₹5.5k, Net +₹4.5k (CREDITOR)");
    $assertCheck($bobOutlay['paid_cents'] === 400000 && $bobOutlay['owed_cents'] === 550000 && $bobOutlay['net_balance_cents'] === -150000, "Bob: Paid ₹4k, Owed ₹5.5k, Net -₹1.5k (DEBTOR)");
    $assertCheck($charlieOutlay['paid_cents'] === 600000 && $charlieOutlay['owed_cents'] === 550000 && $charlieOutlay['net_balance_cents'] === 50000, "Charlie: Paid ₹6k, Owed ₹5.5k, Net +₹500 (CREDITOR)");
    $assertCheck($danaOutlay['paid_cents'] === 200000 && $danaOutlay['owed_cents'] === 550000 && $danaOutlay['net_balance_cents'] === -350000, "Dana: Paid ₹2k, Owed ₹5.5k, Net -₹3.5k (DEBTOR)");

    // 10. Verify Zero-Sum Assertion Across Member Outlays
    $netSum = array_sum(array_column($memberOutlays, 'net_balance_cents'));
    $assertCheck($netSum === 0, "Zero-sum invariant verified across member outlays: sum = {$netSum} paise");

} catch (Throwable $e) {
    echo "\n  [CRITICAL EXCEPTION] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n====================================================================\n";
echo " STEP 10 RESULTS: {$passed} PASSED | {$failed} FAILED\n";
echo "====================================================================\n\n";

exit($failed > 0 ? 1 : 0);
