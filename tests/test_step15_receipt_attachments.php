<?php

declare(strict_types=1);

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Env;
use App\Core\Database;
use App\Repositories\ReceiptRepository;
use App\Services\ReceiptService;

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

function getRaw(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr((string) $res, 0, $headerSize);
    $body = substr((string) $res, $headerSize);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [
        'status' => $code,
        'headers' => $headerStr,
        'content_type' => $contentType,
        'body' => $body,
    ];
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
echo " STEP 15: SEC-03 RECEIPT ATTACHMENTS & SECURE STREAMING TEST\n";
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
    // 1. Service Unit Checks
    $receiptService = new ReceiptService();
    $assertCheck(count(ReceiptService::ALLOWED_MIME_TYPES) === 4, "ReceiptService supports 4 allowed document/image MIME types (JPEG, PNG, WebP, PDF)");
    $assertCheck(ReceiptService::MAX_FILE_SIZE_BYTES === 5242880, "Max file size strictly enforced at 5 MB");

    // 2. Create Workspace and Members
    $grpRes = postJson("{$baseUrl}/api/groups", [
        'name' => 'Receipt Management Tour ' . time(),
        'creator_name' => 'Rohan Accountant',
        'currency' => 'INR',
    ]);
    $assertCheck($grpRes['status'] === 201, "Created test group for receipt uploads (HTTP 201)");
    $token = $grpRes['body']['data']['group']['invite_token'];
    $groupId = (int) $grpRes['body']['data']['group']['id'];
    $rohanId = (int) $grpRes['body']['data']['creator']['id'];

    $m2Res = postJson("{$baseUrl}/api/groups/{$token}/members", ['name' => 'Meera']);
    $meeraId = (int) $m2Res['body']['data']['member']['id'];

    // 3. Create Expense
    $expRes = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Four Seasons Fine Dining',
        'amount' => 4500.00,
        'expense_date' => '2026-09-21',
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $rohanId, 'amount_paid_cents' => 450000]],
        'participants' => [
            ['member_id' => $rohanId],
            ['member_id' => $meeraId],
        ],
    ]);
    $assertCheck($expRes['status'] === 201, "Created Expense: ₹4,500.00 Dinner (HTTP 201)");
    $expenseId = (int) $expRes['body']['data']['expense']['id'];

    // 4. Attach 1x PNG Receipt via Base64 API
    // 1x1 transparent PNG Base64
    $pngBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    $rawPngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $upload1Res = postJson("{$baseUrl}/api/groups/{$token}/expenses/{$expenseId}/receipts", [
        'file_name' => 'restaurant_bill_table4.png',
        'data_base64' => $pngBase64,
        'actor_member_id' => $rohanId,
    ]);
    $assertCheck($upload1Res['status'] === 201, "Attached PNG receipt image to expense (HTTP 201)");
    $receipt1 = $upload1Res['body']['data']['receipt'];
    $receipt1Id = (int) $receipt1['id'];
    $assertCheck($receipt1Id > 0, "Generated receipt ID > 0");
    $assertCheck($receipt1['file_name'] === 'restaurant_bill_table4.png', "Persisted sanitized original file name");
    $assertCheck($receipt1['mime_type'] === 'image/png', "Detected and validated image/png MIME type");
    $assertCheck($receipt1['is_image'] === true, "Marked is_image = true");

    // Verify URL points to secure download endpoint
    $expectedUrlPrefix = "/api/groups/{$token}/expenses/{$expenseId}/receipts/{$receipt1Id}/download";
    $assertCheck($receipt1['url'] === $expectedUrlPrefix, "Generated secure authorized URL '{$expectedUrlPrefix}'");

    // Verify physical file is stored in private storage (outside public webroot)
    $storagePath = dirname(__DIR__) . '/storage/receipts/' . basename($receipt1['file_path']);
    $assertCheck(file_exists($storagePath), "Receipt stored in private storage ({$storagePath})");

    $publicStaticPath = dirname(__DIR__) . '/public/' . ltrim($receipt1['file_path'], '/');
    $assertCheck(!file_exists($publicStaticPath), "Receipt is NOT stored directly under public webroot ({$publicStaticPath})");

    // 5. Verify Direct Web Access to legacy public path returns 404
    $directWebRes = getRaw("{$baseUrl}/uploads/receipts/" . basename($receipt1['file_path']));
    $assertCheck($directWebRes['status'] === 404 || str_contains($directWebRes['body'], 'NOT_FOUND'), "Direct static web access to /uploads/receipts/... is blocked (HTTP 404)");

    // 6. Verify Authorized Secure Download of PNG Attachment
    $downloadRes = getRaw("{$baseUrl}{$receipt1['url']}");
    $assertCheck($downloadRes['status'] === 200, "Authorized GET to receipt endpoint returns HTTP 200");
    $assertCheck(str_contains($downloadRes['content_type'], 'image/png'), "Response Content-Type is image/png");
    $assertCheck(str_contains($downloadRes['headers'], 'X-Content-Type-Options: nosniff'), "Response contains 'X-Content-Type-Options: nosniff'");
    $assertCheck(str_contains($downloadRes['headers'], 'Cache-Control: private'), "Response contains 'Cache-Control: private'");
    $assertCheck($downloadRes['body'] === $rawPngBytes, "Downloaded binary content exactly matches original PNG bytes");

    // 7. Attach 2nd Receipt (PDF Document)
    // Minimal PDF header Base64
    $pdfBase64 = 'data:application/pdf;base64,JVBERi0xLjQKMSAwIG9iago8PAovVHlwZSAvQ2F0YWxvZwovUGFnZXMgMiAwIFIKPj4KZW5kb2JqCjIgMCBvYmoKPDwKL1R5cGUgL1BhZ2VzCi9LaWRzIFszIDAgUl0KL0NvdW50IDEKPj4KZW5kb2JqCjMgMCBvYmoKPDwKL1R5cGUgL1BhZ2UKL1BhcmVudCAyIDAgUgovTWVkaWFCb3ggWzAgMCAzMDAgMTQ0XQo+PgplbmRvYmoKeHJlZgowIDQKMDAwMDAwMDAwMCA2NTUzNSBmIAowMDAwMDAwMDA5IDAwMDAwIG4gCjAwMDAwMDAwNTggMDAwMDAgbiAKMDAwMDAwMDExNSAwMDAwMCBuIAp0cmFpbGVyCjw8Ci9TaXplIDQKL1Jvb3QgMSAwIFIKPj4Kc3RhcnR4cmVmCjE5MAolJUVPRg==';

    $upload2Res = postJson("{$baseUrl}/api/groups/{$token}/expenses/{$expenseId}/receipts", [
        'file_name' => 'official_tax_invoice.pdf',
        'data_base64' => $pdfBase64,
        'actor_member_id' => $rohanId,
    ]);
    $assertCheck($upload2Res['status'] === 201, "Attached PDF receipt document to expense (HTTP 201)");
    $receipt2 = $upload2Res['body']['data']['receipt'];
    $receipt2Id = (int) $receipt2['id'];
    $assertCheck($receipt2['mime_type'] === 'application/pdf', "Detected and validated application/pdf MIME type");
    $assertCheck($receipt2['is_image'] === false, "Marked is_image = false for PDF");

    // 8. Verify Authorized Download of PDF Document
    $pdfDownloadRes = getRaw("{$baseUrl}{$receipt2['url']}");
    $assertCheck($pdfDownloadRes['status'] === 200, "Authorized GET to PDF receipt endpoint returns HTTP 200");
    $assertCheck(str_contains($pdfDownloadRes['content_type'], 'application/pdf'), "Response Content-Type is application/pdf");

    // 9. Cross-Group IDOR Security Verification
    // Create a 2nd isolated group (Attacker/Other Group)
    $otherGrpRes = postJson("{$baseUrl}/api/groups", [
        'name' => 'Unrelated Team ' . time(),
        'creator_name' => 'Eve Attacker',
        'currency' => 'INR',
    ]);
    $otherToken = $otherGrpRes['body']['data']['group']['invite_token'];

    // Try accessing Rohan's receipt using Eve's token
    $idorRes = getRaw("{$baseUrl}/api/groups/{$otherToken}/expenses/{$expenseId}/receipts/{$receipt1Id}/download");
    $assertCheck($idorRes['status'] === 404, "Cross-group IDOR access using different workspace token returns HTTP 404");

    // Try accessing with completely invalid token
    $badTokenRes = getRaw("{$baseUrl}/api/groups/invalidtoken1234567890/expenses/{$expenseId}/receipts/{$receipt1Id}/download");
    $assertCheck($badTokenRes['status'] === 404, "Access using nonexistent group token returns HTTP 404");

    // 10. List Receipts for Expense
    $listRes = getJson("{$baseUrl}/api/groups/{$token}/expenses/{$expenseId}/receipts");
    $assertCheck($listRes['status'] === 200, "Fetched receipts for expense (HTTP 200)");
    $receiptsList = $listRes['body']['data']['receipts'];
    $assertCheck(count($receiptsList) === 2, "Expense has exactly 2 attached receipts");

    // 11. Verify Expense in Ledger List Hydrates Receipts with Secure URLs
    $expensesRes = getJson("{$baseUrl}/api/groups/{$token}/expenses");
    $assertCheck($expensesRes['status'] === 200, "Fetched expenses ledger (HTTP 200)");
    $foundExp = null;
    foreach ($expensesRes['body']['data']['expenses'] as $e) {
        if ((int)$e['id'] === $expenseId) {
            $foundExp = $e;
            break;
        }
    }
    $assertCheck($foundExp !== null, "Found expense in ledger list");
    $assertCheck((int)$foundExp['receipt_count'] === 2, "Expense ledger row has receipt_count = 2");
    $assertCheck(count($foundExp['receipts']) === 2, "Expense ledger row contains hydrated receipts array");
    $assertCheck(str_starts_with($foundExp['receipts'][0]['url'], "/api/groups/{$token}/"), "Hydrated expense receipt URL uses authorized route");

    // 12. Verify Activity Timeline Records RECEIPT_ATTACHED
    $activityRes = getJson("{$baseUrl}/api/groups/{$token}/activity-feed");
    $assertCheck($activityRes['status'] === 200, "Fetched activity feed (HTTP 200)");
    $activities = $activityRes['body']['data']['activities'] ?? [];
    $hasReceiptActivity = false;
    foreach ($activities as $act) {
        if ($act['action'] === 'RECEIPT_ATTACHED') {
            $hasReceiptActivity = true;
            break;
        }
    }
    $assertCheck($hasReceiptActivity, "Activity feed includes RECEIPT_ATTACHED audit event");

    // 13. Delete 1 Receipt
    $delRes = deleteJson("{$baseUrl}/api/groups/{$token}/expenses/{$expenseId}/receipts/{$receipt1Id}");
    $assertCheck($delRes['status'] === 200, "Deleted receipt 1 (HTTP 200)");
    $assertCheck(!file_exists($storagePath), "Deleted receipt physical file unlinked from private storage");

    // Verify remaining list has 1 receipt
    $listRes2 = getJson("{$baseUrl}/api/groups/{$token}/expenses/{$expenseId}/receipts");
    $assertCheck(count($listRes2['body']['data']['receipts']) === 1, "Remaining receipts count for expense is exactly 1");

    // 14. Adversarial / Error Tests
    // Upload unsupported file type (e.g. text/plain script)
    $badFileRes = postJson("{$baseUrl}/api/groups/{$token}/expenses/{$expenseId}/receipts", [
        'file_name' => 'malicious_script.sh',
        'data_base64' => base64_encode('#!/bin/bash echo hello'),
    ]);
    $assertCheck($badFileRes['status'] === 422, "Unsupported file format rejected with HTTP 422");

    // Upload with empty payload
    $emptyRes = postJson("{$baseUrl}/api/groups/{$token}/expenses/{$expenseId}/receipts", []);
    $assertCheck($emptyRes['status'] === 422, "Empty upload request rejected with HTTP 422");

    // Delete already deleted receipt
    $delAgainRes = deleteJson("{$baseUrl}/api/groups/{$token}/expenses/{$expenseId}/receipts/{$receipt1Id}");
    $assertCheck($delAgainRes['status'] === 404, "Deleting non-existent receipt returns HTTP 404");

    // 15. Create Expense With Embedded Receipt in Payload
    $expWithReceiptRes = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Uber Airport Ride',
        'amount' => 850.00,
        'expense_date' => '2026-09-22',
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $meeraId, 'amount_paid_cents' => 85000]],
        'participants' => [
            ['member_id' => $rohanId],
            ['member_id' => $meeraId],
        ],
        'receipt_base64' => $pngBase64,
        'receipt_file_name' => 'uber_e_receipt.png',
    ]);
    $assertCheck($expWithReceiptRes['status'] === 201, "Created Expense with embedded receipt attachment (HTTP 201)");
    $exp2 = $expWithReceiptRes['body']['data']['expense'];
    $assertCheck((int)$exp2['receipt_count'] === 1, "Newly created expense automatically has receipt_count = 1");

} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n--------------------------------------------------------------------\n";
echo " STEP 15 SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "--------------------------------------------------------------------\n\n";

exit($failed > 0 ? 1 : 0);
