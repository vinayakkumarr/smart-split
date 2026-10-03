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

function putJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
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

function deleteJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

echo "\n====================================================================\n";
echo " STEP 11: ACTIVITY TIMELINE & AUDIT HISTORY TEST SUITE\n";
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
    // 1. Create Workspace
    $grpRes = postJson("{$baseUrl}/api/groups", [
        'name' => 'Timeline Expedition ' . time(),
        'creator_name' => 'Kavya Organizer',
        'currency' => 'INR',
    ]);
    $assertCheck($grpRes['status'] === 201, "Created test group for activity timeline (HTTP 201)");
    $token = $grpRes['body']['data']['group']['invite_token'];
    $kavyaId = $grpRes['body']['data']['creator']['id'];

    // 2. Add New Member (Triggers MEMBER_ADDED log)
    $m2Res = postJson("{$baseUrl}/api/groups/{$token}/members", ['name' => 'Dev Designer']);
    $devId = $m2Res['body']['data']['member']['id'];
    $assertCheck($devId > 0, "Added member Dev (HTTP 201)");

    $m3Res = postJson("{$baseUrl}/api/groups/{$token}/members", ['name' => 'Tara Tech']);
    $taraId = $m3Res['body']['data']['member']['id'];
    $assertCheck($taraId > 0, "Added member Tara (HTTP 201)");

    // 3. Create Custom Category (Triggers CATEGORY_CREATED log)
    $catRes = postJson("{$baseUrl}/api/groups/{$token}/categories", [
        'name' => 'Resort Spa',
        'icon' => '💆',
        'color_hex' => '#db2777',
    ]);
    $spaCatId = $catRes['body']['data']['category']['id'];
    $assertCheck($spaCatId > 0, "Created custom category 'Resort Spa' (HTTP 201)");

    // 4. Create Expense (Triggers EXPENSE_ADDED log)
    $expRes1 = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Spa Treatment',
        'total_amount_cents' => 300000,
        'expense_date' => '2026-09-21',
        'category_id' => $spaCatId,
        'split_type' => 'EQUAL',
        'created_by_member_id' => $kavyaId,
        'payers' => [['member_id' => $kavyaId, 'amount_paid_cents' => 300000]],
        'participants' => [
            ['member_id' => $kavyaId],
            ['member_id' => $devId],
            ['member_id' => $taraId],
        ],
    ]);
    $assertCheck($expRes1['status'] === 201, "Created Expense: Spa Treatment ₹3,000.00 (HTTP 201)");
    $exp1Id = $expRes1['body']['data']['expense']['id'];

    // 5. Update Expense (Triggers EXPENSE_UPDATED log)
    $expUpdateRes = putJson("{$baseUrl}/api/groups/{$token}/expenses/{$exp1Id}", [
        'title' => 'Luxury Ayurvedic Spa Treatment',
        'total_amount_cents' => 450000,
        'expense_date' => '2026-09-21',
        'category_id' => $spaCatId,
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $kavyaId, 'amount_paid_cents' => 450000]],
        'participants' => [
            ['member_id' => $kavyaId],
            ['member_id' => $devId],
            ['member_id' => $taraId],
        ],
    ]);
    $assertCheck($expUpdateRes['status'] === 200, "Updated Expense to ₹4,500.00 (HTTP 200)");

    // 6. Record Settlement (Triggers SETTLEMENT_RECORDED log)
    $settleRes = postJson("{$baseUrl}/api/groups/{$token}/settlements", [
        'payer_member_id' => $devId,
        'payee_member_id' => $kavyaId,
        'recorded_by_member_id' => $kavyaId,
        'amount_cents' => 150000,
        'notes' => 'UPI Transfer via GPay',
    ]);
    $assertCheck($settleRes['status'] === 201, "Recorded Settlement Dev -> Kavya ₹1,500.00 (HTTP 201)");
    $settleId = $settleRes['body']['data']['settlement']['id'];

    // 7. Delete / Undo Settlement (Triggers SETTLEMENT_DELETED log)
    $settleDelRes = deleteJson("{$baseUrl}/api/groups/{$token}/settlements/{$settleId}");
    $assertCheck($settleDelRes['status'] === 200, "Undone/Deleted Settlement (HTTP 200)");

    // 8. Create Recurring Schedule (Triggers RECURRING_RULE_CREATED log)
    $recurRes = postJson("{$baseUrl}/api/groups/{$token}/recurring", [
        'title' => 'Monthly Cloud Server',
        'total_amount_cents' => 120000,
        'frequency' => 'MONTHLY',
        'split_type' => 'EQUAL',
        'created_by_member_id' => $kavyaId,
    ]);
    $assertCheck($recurRes['status'] === 201, "Created Recurring Schedule rule (HTTP 201)");

    // 9. Fetch Activity Feed (Unfiltered)
    $feedRes = getJson("{$baseUrl}/api/groups/{$token}/activity-feed");
    if ($feedRes['status'] !== 200) {
        echo "DEBUG feedRes: " . print_r($feedRes, true) . "\n";
    }
    $assertCheck($feedRes['status'] === 200, "GET /api/groups/{token}/activity-feed returns HTTP 200");
    $feedData = $feedRes['body']['data'] ?? [];
    $activities = $feedData['activities'] ?? [];
    $assertCheck($feedData['total_count'] >= 7, "Activity feed records at least 7 distinct group events (Found: {$feedData['total_count']})");

    // 10. Verify Narrative and Visual Enrichment on Activities
    $actions = array_column($activities, 'action');
    $assertCheck(in_array('EXPENSE_ADDED', $actions), "EXPENSE_ADDED action captured in feed");
    $assertCheck(in_array('EXPENSE_UPDATED', $actions), "EXPENSE_UPDATED action captured in feed");
    $assertCheck(in_array('SETTLEMENT_RECORDED', $actions), "SETTLEMENT_RECORDED action captured in feed");
    $assertCheck(in_array('SETTLEMENT_DELETED', $actions), "SETTLEMENT_DELETED action captured in feed");
    $assertCheck(in_array('MEMBER_ADDED', $actions), "MEMBER_ADDED action captured in feed");
    $assertCheck(in_array('CATEGORY_CREATED', $actions), "CATEGORY_CREATED action captured in feed");
    $assertCheck(in_array('RECURRING_RULE_CREATED', $actions), "RECURRING_RULE_CREATED action captured in feed");

    // Check specific narrative strings
    $settleAct = current(array_filter($activities, fn($a) => $a['action'] === 'SETTLEMENT_RECORDED'));
    $assertCheck($settleAct && str_contains($settleAct['narrative'], 'Dev Designer paid ₹1,500.00 to Kavya Organizer'), "Settlement narrative resolves member names and formatted INR amount");
    $assertCheck($settleAct['icon'] === '🤝', "Settlement activity has handshake icon");

    $expenseUpdatedAct = current(array_filter($activities, fn($a) => $a['action'] === 'EXPENSE_UPDATED'));
    $assertCheck($expenseUpdatedAct && str_contains($expenseUpdatedAct['narrative'], 'updated \'Luxury Ayurvedic Spa Treatment\' to ₹4,500.00'), "Expense update narrative contains updated title and amount");
    $assertCheck($expenseUpdatedAct['icon'] === '✏️', "Expense update has pencil icon");

    $catAct = current(array_filter($activities, fn($a) => $a['action'] === 'CATEGORY_CREATED'));
    $assertCheck($catAct && str_contains($catAct['narrative'], 'created custom category 💆 Resort Spa'), "Category creation narrative includes custom emoji and name");

    // 11. Test Entity Type Filtering
    $expenseFeed = getJson("{$baseUrl}/api/groups/{$token}/activity-feed?entity_type=expenses");
    $expenseActs = $expenseFeed['body']['data']['activities'];
    $assertCheck(count($expenseActs) >= 2, "Expense-filtered feed contains only expense actions");
    $allAreExpenses = true;
    foreach ($expenseActs as $ea) {
        if ($ea['entity_type'] !== 'expenses') $allAreExpenses = false;
    }
    $assertCheck($allAreExpenses, "All items in ?entity_type=expenses have entity_type='expenses'");

    $settleFeed = getJson("{$baseUrl}/api/groups/{$token}/activity-feed?entity_type=settlements");
    $settleActs = $settleFeed['body']['data']['activities'];
    $assertCheck(count($settleActs) === 2, "Settlement-filtered feed contains exactly 2 settlement events (recorded + deleted)");

    // 12. Test Pagination (limit=2, offset=0 and offset=2)
    $page1 = getJson("{$baseUrl}/api/groups/{$token}/activity-feed?limit=2&offset=0");
    $page2 = getJson("{$baseUrl}/api/groups/{$token}/activity-feed?limit=2&offset=2");
    $assertCheck(count($page1['body']['data']['activities']) === 2, "Page 1 limit=2 returns exactly 2 items");
    $assertCheck(count($page2['body']['data']['activities']) === 2, "Page 2 limit=2 offset=2 returns exactly 2 items");
    $assertCheck($page1['body']['data']['activities'][0]['id'] !== $page2['body']['data']['activities'][0]['id'], "Pagination offsets return distinct events");

} catch (Throwable $e) {
    echo "\n  [CRITICAL EXCEPTION] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n====================================================================\n";
echo " STEP 11 RESULTS: {$passed} PASSED | {$failed} FAILED\n";
echo "====================================================================\n\n";

exit($failed > 0 ? 1 : 0);
