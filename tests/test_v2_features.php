<?php

declare(strict_types=1);

/**
 * Verification Test Suite for Splitwise Gap Analysis & V2 Product Enhancements:
 * 1. Category Taxonomy (GET /api/categories, Seeded categories)
 * 2. In-Place Expense Updating (PUT /api/groups/{token}/expenses/{id})
 * 3. 1-Click CSV Export (GET /api/groups/{token}/export.csv)
 * 4. Advanced Category Analytics & Spend Breakdown (GET /api/groups/{token}/analytics/summary)
 */

$baseUrl = 'http://localhost:8000';

function apiReq(string $method, string $path, array $data = []): array {
    global $baseUrl;
    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Requested-With: fetch',
        'Accept: application/json, text/csv'
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $isJson = str_contains((string) $contentType, 'application/json');
    return [
        'code' => $code,
        'contentType' => $contentType,
        'body' => $isJson ? json_decode((string) $res, true) : $res,
        'raw' => $res
    ];
}

$testsPassed = 0;
$totalTests = 0;

function v2Assert(bool $condition, string $testName, string $details = ''): void {
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] {$testName}\n";
    } else {
        echo "  [FAIL] {$testName} : {$details}\n";
    }
}

echo "====================================================================\n";
echo " V2 ENHANCEMENTS & SPLITWISE COMPETITOR PARITY VALIDATION\n";
echo "====================================================================\n\n";

// 1. Categories API
echo "--- 1. Testing Category Taxonomy API ---\n";
$catRes = apiReq('GET', '/api/categories');
v2Assert($catRes['code'] === 200, 'GET /api/categories returns HTTP 200 OK');
$categories = $catRes['body']['data']['categories'] ?? [];
v2Assert(count($categories) >= 7, 'At least 7 default expense categories seeded', 'Count: ' . count($categories));
$slugs = array_column($categories, 'slug');
v2Assert(in_array('food_dining', $slugs, true) && in_array('travel_transport', $slugs, true), 'Core financial categories present (food_dining, travel_transport)');

// 2. Group Creation & Expense Logging with Categories
echo "\n--- 2. Testing Expense Logging with Category Association ---\n";
$groupRes = apiReq('POST', '/api/groups', ['name' => 'Goa Vacation 2026', 'creator_name' => 'Kunal', 'currency' => 'INR']);
$token = $groupRes['body']['data']['group']['invite_token'];
$kunalId = $groupRes['body']['data']['creator']['id'];

$rohitRes = apiReq('POST', "/api/groups/$token/members", ['name' => 'Rohit']);
$rohitId = $rohitRes['body']['data']['member']['id'];

$ananyaRes = apiReq('POST', "/api/groups/$token/members", ['name' => 'Ananya']);
$ananyaId = $ananyaRes['body']['data']['member']['id'];

$foodCat = null;
foreach ($categories as $c) {
    if ($c['slug'] === 'food_dining') $foodCat = (int) $c['id'];
}

$expRes = apiReq('POST', "/api/groups/$token/expenses", [
    'title' => 'Beach Shack Dinner',
    'amount_cents' => 300000, // ₹3,000.00
    'category_id' => $foodCat,
    'split_type' => 'EQUAL',
    'payer_id' => $kunalId,
    'split_members' => [$kunalId, $rohitId, $ananyaId]
]);
v2Assert($expRes['code'] === 201, 'Expense logged with category_id returns 201 Created');
$expenseId = $expRes['body']['data']['expense']['id'] ?? 0;
v2Assert(($expRes['body']['data']['expense']['category']['slug'] ?? '') === 'food_dining', 'Created expense retains category relationship');

// 3. In-Place Expense Updating (PUT)
echo "\n--- 3. Testing In-Place Expense Updating (PUT) ---\n";
$travelCat = null;
foreach ($categories as $c) {
    if ($c['slug'] === 'travel_transport') $travelCat = (int) $c['id'];
}

$updateRes = apiReq('PUT', "/api/groups/$token/expenses/$expenseId", [
    'title' => 'Updated: Beach Shack Dinner & Scuba',
    'amount_cents' => 450000, // ₹4,500.00 (increased)
    'category_id' => $travelCat,
    'split_type' => 'EXACT',
    'payers' => [
        ['member_id' => $kunalId, 'amount_cents' => 300000],
        ['member_id' => $rohitId, 'amount_cents' => 150000]
    ],
    'splits' => [
        ['member_id' => $kunalId, 'amount_cents' => 150000],
        ['member_id' => $rohitId, 'amount_cents' => 150000],
        ['member_id' => $ananyaId, 'amount_cents' => 150000]
    ]
]);
v2Assert($updateRes['code'] === 200, 'PUT /api/groups/{token}/expenses/{id} returns HTTP 200 OK');
v2Assert(($updateRes['body']['data']['expense']['title'] ?? '') === 'Updated: Beach Shack Dinner & Scuba', 'Expense title updated in-place');
v2Assert(($updateRes['body']['data']['expense']['total_amount_cents'] ?? 0) === 450000, 'Expense amount updated to 450,000 paise');
v2Assert(($updateRes['body']['data']['expense']['category']['slug'] ?? '') === 'travel_transport', 'Expense category updated to travel_transport');

// Verify Balances reflect updated amounts
$balRes = apiReq('GET', "/api/groups/$token/balances");
v2Assert(($balRes['body']['data']['total_spending_cents'] ?? 0) === 450000, 'Group total spending reflects updated expense amount');
v2Assert(($balRes['body']['data']['zero_sum_verified'] ?? false) === true, 'Zero-sum ledger holds precisely after in-place expense edit');

// 4. Advanced Analytics & Spend Breakdown API
echo "\n--- 4. Testing Analytics Summary API ---\n";
$analyticsRes = apiReq('GET', "/api/groups/$token/analytics/summary");
v2Assert($analyticsRes['code'] === 200, 'GET /api/groups/{token}/analytics/summary returns HTTP 200 OK');
$catBreakdown = $analyticsRes['body']['data']['analytics']['category_breakdown'] ?? [];
v2Assert(count($catBreakdown) >= 1, 'Category breakdown contains active spend categories');
$firstCat = $catBreakdown[0] ?? [];
v2Assert(($firstCat['slug'] ?? '') === 'travel_transport' && ($firstCat['total_cents'] ?? 0) === 450000, 'Category analytics aggregates total category spend (₹4,500.00)');

// 5. 1-Click CSV Export
echo "\n--- 5. Testing RFC 4180 CSV Export Endpoint ---\n";
$csvRes = apiReq('GET', "/api/groups/$token/export.csv");
v2Assert($csvRes['code'] === 200, 'GET /api/groups/{token}/export.csv returns HTTP 200 OK');
v2Assert(str_contains((string) $csvRes['contentType'], 'text/csv'), 'Content-Type header is text/csv');
v2Assert(str_contains((string) $csvRes['raw'], 'ID,Date,Description,Category') && str_contains((string) $csvRes['raw'], 'Split Type'), 'CSV header row formatted according to RFC 4180');
v2Assert(str_contains((string) $csvRes['raw'], 'Beach Shack Dinner & Scuba'), 'CSV contains exported expense record with itemized allocations');

echo "\n====================================================================\n";
echo " FINAL V2 VALIDATION RESULTS: {$testsPassed} / {$totalTests} Passed\n";
echo "====================================================================\n";
