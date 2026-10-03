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
echo " STEP 9: CUSTOM CATEGORIES & METADATA TAXONOMY VALIDATION\n";
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

// 1. Create Workspace A
$groupARes = postJson("{$baseUrl}/api/groups", [
    'name' => 'Custom Category Group A ' . uniqid(),
    'currency' => 'INR',
    'creator_name' => 'Aditi',
]);
$assertCheck($groupARes['status'] === 201, "Created Workspace A successfully");
$tokenA = $groupARes['body']['data']['group']['invite_token'];
$aditiId = $groupARes['body']['data']['creator']['id'];

// 2. Create Workspace B (to test isolation)
$groupBRes = postJson("{$baseUrl}/api/groups", [
    'name' => 'Custom Category Group B ' . uniqid(),
    'currency' => 'INR',
    'creator_name' => 'Kabir',
]);
$assertCheck($groupBRes['status'] === 201, "Created Workspace B successfully");
$tokenB = $groupBRes['body']['data']['group']['invite_token'];

// 3. List Categories for Workspace A
echo "\n--- Testing Initial Category Roster ---\n";
$catListARes = getJson("{$baseUrl}/api/groups/{$tokenA}/categories");
$assertCheck($catListARes['status'] === 200, "Fetched categories for Workspace A (HTTP 200)");
$catsA = $catListARes['body']['data']['categories'] ?? [];
$assertCheck(count($catsA) >= 7, "Workspace A contains all 7 default system categories");

// 4. Create Custom Category in Workspace A
echo "\n--- Testing Custom Category Creation ---\n";
$createCat1 = postJson("{$baseUrl}/api/groups/{$tokenA}/categories", [
    'name' => 'Pet Care',
    'icon' => '🐶',
    'color_hex' => '#ea580c',
]);
$assertCheck($createCat1['status'] === 201, "Created custom category 'Pet Care' (HTTP 201)");
$petCareId = $createCat1['body']['data']['category']['id'];
$assertCheck($petCareId > 0, "Generated custom category ID > 0");
$assertCheck($createCat1['body']['data']['category']['name'] === 'Pet Care', "Category name matches 'Pet Care'");
$assertCheck($createCat1['body']['data']['category']['icon'] === '🐶', "Category icon matches 🐶");
$assertCheck($createCat1['body']['data']['category']['is_system'] === false, "Custom category is_system is false");

$createCat2 = postJson("{$baseUrl}/api/groups/{$tokenA}/categories", [
    'name' => 'Gym & Fitness',
    'icon' => '🏋️',
    'color_hex' => '#16a34a',
]);
$assertCheck($createCat2['status'] === 201, "Created custom category 'Gym & Fitness' (HTTP 201)");
$gymId = $createCat2['body']['data']['category']['id'];

// 5. Verify Workspace Isolation
echo "\n--- Testing Workspace Isolation ---\n";
$upCatsA = getJson("{$baseUrl}/api/groups/{$tokenA}/categories");
$assertCheck(count($upCatsA['body']['data']['categories']) === count($catsA) + 2, "Workspace A has 2 additional custom categories");

$catsB = getJson("{$baseUrl}/api/groups/{$tokenB}/categories");
$assertCheck(count($catsB['body']['data']['categories']) === count($catsA), "Workspace B does not see Workspace A's custom categories (Strict Isolation)");

// 6. Create Expense with Custom Category
echo "\n--- Testing Expense Logging with Custom Category ---\n";
$mRes = postJson("{$baseUrl}/api/groups/{$tokenA}/members", ['name' => 'Riya']);
$riyaId = $mRes['body']['data']['member']['id'];

$expRes = postJson("{$baseUrl}/api/groups/{$tokenA}/expenses", [
    'title' => 'Golden Retriever Vet Checkup',
    'total_amount_cents' => 350000,
    'split_type' => 'EQUAL',
    'category_id' => $petCareId,
    'expense_date' => '2026-09-21',
    'paid_by_member_id' => $aditiId,
    'split_members' => [$aditiId, $riyaId],
    'notes' => 'Vaccinations and grooming'
]);
$assertCheck($expRes['status'] === 201, "Logged expense with custom category (HTTP 201)");
$expData = $expRes['body']['data']['expense'];
$assertCheck($expData['category']['name'] === 'Pet Care', "Expense response contains custom category name");
$assertCheck($expData['category']['icon'] === '🐶', "Expense response contains custom category icon");

// 7. Test Protection of System Categories
echo "\n--- Testing System Category Protection & Deletion ---\n";
$delSysRes = deleteJson("{$baseUrl}/api/groups/{$tokenA}/categories/1");
$assertCheck($delSysRes['status'] === 403 || $delSysRes['status'] === 422 || $delSysRes['status'] === 400, "Deleting system category is blocked (HTTP {$delSysRes['status']})");

// 8. Delete Custom Category (Gym & Fitness)
$delCustomRes = deleteJson("{$baseUrl}/api/groups/{$tokenA}/categories/{$gymId}");
$assertCheck($delCustomRes['status'] === 200, "Deleted custom category 'Gym & Fitness' (HTTP 200)");

$finalCatsA = getJson("{$baseUrl}/api/groups/{$tokenA}/categories");
$assertCheck(count($finalCatsA['body']['data']['categories']) === count($catsA) + 1, "Workspace A category count updated to 8 after deletion");

// 9. Balances Zero-Sum Validation
$balRes = getJson("{$baseUrl}/api/groups/{$tokenA}/balances");
$assertCheck($balRes['status'] === 200, "Balances fetched successfully");
$zeroSum = array_sum(array_column($balRes['body']['data']['members'], 'net_balance_cents'));
$assertCheck($zeroSum === 0, "Zero-sum ledger invariant holds with custom category transaction");

echo "\n====================================================================\n";
echo " STEP 9 VALIDATION RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n\n";

if ($failed > 0) {
    exit(1);
}
