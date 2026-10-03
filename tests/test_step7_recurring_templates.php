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
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
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

function getJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

echo "\n====================================================================\n";
echo " STEP 7: RECURRING EXPENSES SCHEDULER & TEMPLATES ENGINE VALIDATION\n";
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

// 1. Create Workspace for Flatmates
$grpRes = postJson("{$baseUrl}/api/groups", [
    'name' => 'Bandra 3BHK Flatmates',
    'creator_name' => 'Aditya Sen',
    'currency' => 'INR'
]);
$assertCheck($grpRes['status'] === 201, "Created test group successfully");
$groupToken = $grpRes['body']['data']['group']['invite_token'];
$adityaId = $grpRes['body']['data']['creator']['id'];

// 2. Add 2 Flatmates
$m2Res = postJson("{$baseUrl}/api/groups/{$groupToken}/members", ['name' => 'Pranav Joshi']);
$pranavId = $m2Res['body']['data']['member']['id'];

$m3Res = postJson("{$baseUrl}/api/groups/{$groupToken}/members", ['name' => 'Neha Mehra']);
$nehaId = $m3Res['body']['data']['member']['id'];

$assertCheck(isset($pranavId) && isset($nehaId), "Added Pranav and Neha to group");

// 3. Test Expense Templates API
echo "\n--- Testing Expense Templates Engine ---\n";
$tplPayload = [
    'title' => 'Weekly Housekeeping & Deep Cleaning',
    'amount_cents' => 150000, // ₹1,500.00
    'split_type' => 'EQUAL',
    'category_id' => 5, // Utilities
    'paid_by_member_id' => $adityaId,
    'split_members' => [$adityaId, $pranavId, $nehaId],
    'notes' => 'Weekly cleaning crew on Saturdays'
];

$tplCreateRes = postJson("{$baseUrl}/api/groups/{$groupToken}/templates", $tplPayload);
$assertCheck($tplCreateRes['status'] === 201, "Created template preset successfully (HTTP 201)");
$templateId = $tplCreateRes['body']['data']['template_id'];
$assertCheck($templateId > 0, "Template ID generated (>0)");

$tplListRes = getJson("{$baseUrl}/api/groups/{$groupToken}/templates");
$assertCheck($tplListRes['status'] === 200, "Listed templates for workspace (HTTP 200)");
$templates = $tplListRes['body']['data']['templates'] ?? [];
$assertCheck(count($templates) === 1, "Template list has exactly 1 saved preset");
$assertCheck($templates[0]['title'] === 'Weekly Housekeeping & Deep Cleaning', "Template title matches");
$assertCheck($templates[0]['total_amount_cents'] === 150000, "Template default amount matches ₹1,500.00");

// 4. Test Recurring Schedules API
echo "\n--- Testing Recurring Schedules Engine ---\n";
// Create Monthly Flat Rent recurring rule: ₹60,000.00 starting 2026-07-01
$recurringPayload = [
    'title' => 'Apartment Monthly Rent',
    'amount_cents' => 6000000, // ₹60,000.00
    'split_type' => 'EQUAL',
    'category_id' => 4, // Housing & Rent
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-07-01',
    'end_date' => '2026-12-31',
    'paid_by_member_id' => $adityaId,
    'split_members' => [$adityaId, $pranavId, $nehaId],
    'notes' => 'Direct transfer to landlord'
];

$ruleCreateRes = postJson("{$baseUrl}/api/groups/{$groupToken}/recurring", $recurringPayload);
$assertCheck($ruleCreateRes['status'] === 201, "Created recurring rule schedule successfully (HTTP 201)");
$ruleId = $ruleCreateRes['body']['data']['rule_id'];
$assertCheck($ruleId > 0, "Recurring Rule ID generated (>0)");

$rulesListRes = getJson("{$baseUrl}/api/groups/{$groupToken}/recurring");
$assertCheck($rulesListRes['status'] === 200, "Listed recurring rules for workspace (HTTP 200)");
$rules = $rulesListRes['body']['data']['rules'] ?? [];
$assertCheck(count($rules) === 1, "Rule list has exactly 1 scheduled recurring rule");
$assertCheck($rules[0]['frequency'] === 'MONTHLY', "Rule frequency is MONTHLY");
$assertCheck($rules[0]['next_run_date'] === '2026-07-01', "Rule next_run_date is 2026-07-01");

// 5. Test Evaluation of Due Recurring Schedules
echo "\n--- Testing Recurring Evaluation & Materialization ---\n";
// Run evaluation as of 2026-09-21
// Expect 3 occurrences: 2026-07-01, 2026-08-01, 2026-09-01 (total ₹180,000.00)
$evalRes = postJson("{$baseUrl}/api/groups/{$groupToken}/recurring/evaluate", [
    'current_date' => '2026-09-21'
]);
$assertCheck($evalRes['status'] === 200, "Evaluated recurring rules successfully (HTTP 200)");
$evalData = $evalRes['body']['data']['evaluation'];
$assertCheck($evalData['evaluated_count'] === 1, "Evaluated 1 due recurring rule");
$assertCheck($evalData['created_expenses_count'] === 3, "Materialized 3 due occurrences (July, Aug, Sept)");

// Check expenses list now has 3 transactions
$expListRes = getJson("{$baseUrl}/api/groups/{$groupToken}/expenses");
$expenses = $expListRes['body']['data']['expenses'] ?? [];
$assertCheck(count($expenses) === 3, "Total expenses in ledger is now 3");

$dates = array_column($expenses, 'expense_date');
$assertCheck(in_array('2026-07-01', $dates, true), "Contains 2026-07-01 materialized expense");
$assertCheck(in_array('2026-08-01', $dates, true), "Contains 2026-08-01 materialized expense");
$assertCheck(in_array('2026-09-01', $dates, true), "Contains 2026-09-01 materialized expense");

// Check rule's next_run_date has advanced to 2026-10-01
$updatedRulesRes = getJson("{$baseUrl}/api/groups/{$groupToken}/recurring");
$upRule = $updatedRulesRes['body']['data']['rules'][0];
$assertCheck($upRule['next_run_date'] === '2026-10-01', "Next run date advanced to 2026-10-01");

// Check group balances zero-sum
$balRes = getJson("{$baseUrl}/api/groups/{$groupToken}/balances");
$assertCheck($balRes['status'] === 200, "Fetched balances after recurring materialization");
$totalSpend = $balRes['body']['data']['total_spending_cents'];
$assertCheck($totalSpend === 18000000, "Total group spend is ₹180,000.00 (18,000,000 paise)");
$zeroSum = array_sum(array_column($balRes['body']['data']['members'], 'net_balance_cents'));
$assertCheck($zeroSum === 0, "Strict zero-sum ledger invariant maintained after recurring evaluation");

// 6. Test Deleting Template and Recurring Rule
echo "\n--- Testing Cleanup & Deletion ---\n";
$delTplRes = deleteJson("{$baseUrl}/api/groups/{$groupToken}/templates/{$templateId}");
$assertCheck($delTplRes['status'] === 200, "Deleted template preset (HTTP 200)");

$delRuleRes = deleteJson("{$baseUrl}/api/groups/{$groupToken}/recurring/{$ruleId}");
$assertCheck($delRuleRes['status'] === 200, "Deleted recurring rule schedule (HTTP 200)");

$finalTpls = getJson("{$baseUrl}/api/groups/{$groupToken}/templates");
$assertCheck(count($finalTpls['body']['data']['templates']) === 0, "Templates list empty after deletion");

$finalRules = getJson("{$baseUrl}/api/groups/{$groupToken}/recurring");
$assertCheck(count($finalRules['body']['data']['rules']) === 0, "Recurring rules list empty after deletion");

echo "\n====================================================================\n";
echo " STEP 7 VALIDATION RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n\n";

if ($failed > 0) {
    exit(1);
}
