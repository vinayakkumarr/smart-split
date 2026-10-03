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
echo " STEP 12: DYNAMIC UPI SETTLEMENT ROUTER & DEBT FLOW TEST\n";
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
        'name' => 'UPI Settlement Tour ' . time(),
        'creator_name' => 'Aarav Leader',
        'currency' => 'INR',
    ]);
    $assertCheck($grpRes['status'] === 201, "Created test group for UPI settlements (HTTP 201)");
    $token = $grpRes['body']['data']['group']['invite_token'];
    $aaravId = $grpRes['body']['data']['creator']['id'];

    // 2. Add Member B
    $m2Res = postJson("{$baseUrl}/api/groups/{$token}/members", ['name' => 'Bhavna']);
    $bhavnaId = $m2Res['body']['data']['member']['id'];
    $assertCheck($bhavnaId > 0, "Added member Bhavna (HTTP 201)");

    // 3. Create Shared Expense: Aarav pays ₹2,000 split equally 2 ways (Bhavna owes ₹1,000)
    $expRes = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Express Highway Toll & Fuel',
        'total_amount_cents' => 200000,
        'expense_date' => '2026-09-21',
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $aaravId, 'amount_paid_cents' => 200000]],
        'participants' => [
            ['member_id' => $aaravId],
            ['member_id' => $bhavnaId],
        ],
    ]);
    $assertCheck($expRes['status'] === 201, "Created Expense: ₹2,000.00 Fuel (HTTP 201)");

    // 4. Verify Settlement Plan (1 transfer: Bhavna -> Aarav ₹1,000.00)
    $planRes1 = getJson("{$baseUrl}/api/groups/{$token}/settlement-plan");
    $assertCheck($planRes1['status'] === 200, "Fetched settlement plan (HTTP 200)");
    $txs1 = $planRes1['body']['data']['transactions'];
    $assertCheck(count($txs1) === 1, "Settlement plan requires exactly 1 transfer");
    $assertCheck($txs1[0]['from_member_id'] === $bhavnaId && $txs1[0]['to_member_id'] === $aaravId && $txs1[0]['amount_cents'] === 100000, "Transfer 1: Bhavna owes Aarav 100,000 paise (₹1,000.00)");

    // 5. Execute 50% Partial Settlement: Aarav records Bhavna paid ₹500.00 with UPI note
    $partialSettleRes = postJson("{$baseUrl}/api/groups/{$token}/settlements", [
        'payer_member_id' => $bhavnaId,
        'payee_member_id' => $aaravId,
        'recorded_by_member_id' => $aaravId,
        'amount_cents' => 50000,
        'notes' => 'GPay Ref #98721 - 50% partial payment',
    ]);
    $assertCheck($partialSettleRes['status'] === 201, "Recorded 50% Partial Settlement ₹500.00 (HTTP 201)");
    $partialSettleId = $partialSettleRes['body']['data']['settlement']['id'];

    // 6. Verify Remaining Settlement Plan (Bhavna owes Aarav ₹500.00 remaining)
    $planRes2 = getJson("{$baseUrl}/api/groups/{$token}/settlement-plan");
    $txs2 = $planRes2['body']['data']['transactions'];
    $assertCheck(count($txs2) === 1, "Remaining plan still requires 1 transfer");
    $assertCheck($txs2[0]['amount_cents'] === 50000, "Remaining debt is exactly 50,000 paise (₹500.00)");

    // 7. Verify Net Balances (Aarav: +₹500, Bhavna: -₹500)
    $balRes1 = getJson("{$baseUrl}/api/groups/{$token}/balances");
    $members1 = $balRes1['body']['data']['members'];
    $aaravBal = current(array_filter($members1, fn($m) => $m['name'] === 'Aarav Leader'));
    $bhavnaBal = current(array_filter($members1, fn($m) => $m['name'] === 'Bhavna'));
    $assertCheck($aaravBal['net_balance_cents'] === 50000 && $bhavnaBal['net_balance_cents'] === -50000, "Balances reflect 50% partial settlement (Aarav +₹500, Bhavna -₹500)");

    // 8. Execute Second Settlement for remaining ₹500.00
    $finalSettleRes = postJson("{$baseUrl}/api/groups/{$token}/settlements", [
        'payer_member_id' => $bhavnaId,
        'payee_member_id' => $aaravId,
        'recorded_by_member_id' => $aaravId,
        'amount_cents' => 50000,
        'notes' => 'PhonePe Ref #44122 - Remaining balance',
    ]);
    $assertCheck($finalSettleRes['status'] === 201, "Recorded final settlement ₹500.00 (HTTP 201)");
    $finalSettleId = $finalSettleRes['body']['data']['settlement']['id'];

    // 9. Verify 100% Settled Plan (0 transfers required)
    $planRes3 = getJson("{$baseUrl}/api/groups/{$token}/settlement-plan");
    $txs3 = $planRes3['body']['data']['transactions'];
    $assertCheck(count($txs3) === 0, "Settlement plan indicates 0 transfers required (100% SETTLED)");

    // 10. Verify Settlements List
    $settlementsListRes = getJson("{$baseUrl}/api/groups/{$token}/settlements");
    $settlementsList = $settlementsListRes['body']['data']['settlements'];
    $assertCheck(count($settlementsList) === 2, "Payment audit history contains 2 recorded settlements");

    // 11. Undo/Delete Final Settlement and verify balance restoration
    $delRes = deleteJson("{$baseUrl}/api/groups/{$token}/settlements/{$finalSettleId}");
    $assertCheck($delRes['status'] === 200, "Undone final settlement (HTTP 200)");

    $planRes4 = getJson("{$baseUrl}/api/groups/{$token}/settlement-plan");
    $txs4 = $planRes4['body']['data']['transactions'];
    $assertCheck(count($txs4) === 1 && $txs4[0]['amount_cents'] === 50000, "Undoing settlement instantly restored ₹500.00 debt in settlement plan");

} catch (Throwable $e) {
    echo "\n  [CRITICAL EXCEPTION] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n====================================================================\n";
echo " STEP 12 RESULTS: {$passed} PASSED | {$failed} FAILED\n";
echo "====================================================================\n\n";

exit($failed > 0 ? 1 : 0);
