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
use App\Repositories\ExpenseRepository;
use App\Repositories\GroupRepository;
use App\Services\ExpenseService;
use App\Utils\CsvSanitizer;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

$baseUrl = 'http://127.0.0.1:8000';

function postJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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

echo "\n====================================================================\n";
echo " SEC-04: CSV FORMULA INJECTION REMEDIATION SECURITY TEST SUITE\n";
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
    // --- PART 1: FOCUSED UNIT TESTS ON CsvSanitizer (SEC04-T01 through SEC04-T10) ---

    // SEC04-T01: Equals formula
    $t1 = CsvSanitizer::sanitize('=SUM(A1:A2)');
    $assertCheck($t1 === "'=SUM(A1:A2)", "SEC04-T01: Equals formula '=SUM(A1:A2)' neutralized with single quote prefix ('=SUM(A1:A2))");

    // SEC04-T02: Plus formula
    $t2 = CsvSanitizer::sanitize('+123');
    $assertCheck($t2 === "'+123", "SEC04-T02: Plus formula '+123' neutralized with single quote prefix ('+123)");

    // SEC04-T03: Minus formula
    $t3 = CsvSanitizer::sanitize('-2+3');
    $assertCheck($t3 === "'-2+3", "SEC04-T03: Minus formula '-2+3' neutralized with single quote prefix ('-2+3)");

    // SEC04-T04: At-sign formula
    $t4 = CsvSanitizer::sanitize('@SUM(A1:A2)');
    $assertCheck($t4 === "'@SUM(A1:A2)", "SEC04-T04: At-sign formula '@SUM(A1:A2)' neutralized with single quote prefix ('@SUM(A1:A2))");

    // SEC04-T05: Leading whitespace / control characters with formula
    $t5a = CsvSanitizer::sanitize(' =SUM(A1:A2)');
    $assertCheck($t5a === "' =SUM(A1:A2)", "SEC04-T05a: Leading space with formula ' =SUM(A1:A2)' neutralized (' =SUM(A1:A2))");

    $t5b = CsvSanitizer::sanitize("\t=cmd|' /C calc'!A0");
    $assertCheck($t5b === "'\t=cmd|' /C calc'!A0", "SEC04-T05b: Leading tab with DDE formula neutralized ('\t=cmd...)");

    $t5c = CsvSanitizer::sanitize("\r\n@evil_macro()");
    $assertCheck($t5c === "'\r\n@evil_macro()", "SEC04-T05c: Leading newline with formula neutralized");

    // SEC04-T06: Normal text preservation
    $t6 = CsvSanitizer::sanitize('Dinner with friends');
    $assertCheck($t6 === 'Dinner with friends', "SEC04-T06: Normal text 'Dinner with friends' preserved unchanged");

    // SEC04-T07: CSV special characters (commas, quotes, newlines)
    $t7Input = "Dinner, \"special\" quotes\nand newline";
    $t7 = CsvSanitizer::sanitize($t7Input);
    $assertCheck($t7 === $t7Input, "SEC04-T07: CSV special characters (comma, double-quote, newline) preserved without unintended modification");

    // SEC04-T08: Unicode
    $t8Input = 'Café ☕ / 東京 ₹4,500.00';
    $t8 = CsvSanitizer::sanitize($t8Input);
    $assertCheck($t8 === $t8Input, "SEC04-T08: Unicode string 'Café ☕ / 東京 ₹4,500.00' preserved intact");

    // SEC04-T09: Numeric amounts
    $t9a = CsvSanitizer::sanitize(4500.00);
    $assertCheck($t9a === '4500', "SEC04-T09a: Float numeric 4500.00 returns '4500' without single quote");

    $t9b = CsvSanitizer::sanitize('4500.00');
    $assertCheck($t9b === '4500.00', "SEC04-T09b: Numeric string '4500.00' preserved without single quote");

    // SEC04-T10: Negative amounts
    $t10a = CsvSanitizer::sanitize(-50.00);
    $assertCheck($t10a === '-50', "SEC04-T10a: Numeric negative float -50.00 preserved as '-50'");

    $t10b = CsvSanitizer::sanitize('-50.00');
    $assertCheck($t10b === '-50.00', "SEC04-T10b: Negative monetary string '-50.00' preserved without single quote");

    // --- PART 2: FULL APPLICATION INTEGRATION TEST (SEC04-T11) ---

    // Create a dedicated workspace with adversarial formula strings in members, categories, and expenses
    $groupRepo = new GroupRepository($pdo);
    $expenseRepo = new ExpenseRepository($pdo);
    $expenseService = new ExpenseService($expenseRepo);

    $grpRes = postJson("{$baseUrl}/api/groups", [
        'name' => 'CSV Security Workspace ' . time(),
        'creator_name' => '=HYPERLINK("http://evil.com","ClickMe")',
        'currency' => 'INR',
    ]);
    $assertCheck($grpRes['status'] === 201, "Created test workspace for CSV injection tests (HTTP 201)");
    $token = $grpRes['body']['data']['group']['invite_token'];
    $groupId = (int) $grpRes['body']['data']['group']['id'];
    $creatorId = (int) $grpRes['body']['data']['creator']['id'];

    // Add 2nd member with dangerous formula name
    $m2Res = postJson("{$baseUrl}/api/groups/{$token}/members", [
        'name' => '@ATTACKER_SPLIT',
    ]);
    $m2Id = (int) $m2Res['body']['data']['member']['id'];

    // Add custom category with formula name
    $catRes = postJson("{$baseUrl}/api/groups/{$token}/categories", [
        'name' => '+VIP_CATEGORY',
        'icon' => 'tag',
        'color_hex' => '#FF0000',
    ]);
    $catId = (int) ($catRes['body']['data']['category']['id'] ?? 0);

    // Create expense with formula title, custom category, and formula payers/splits
    $expRes = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => '=SUM(1+1)*cmd|\' /C calc\'!A0',
        'amount' => 1250.50,
        'category_id' => $catId > 0 ? $catId : null,
        'expense_date' => '2026-09-27',
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $creatorId, 'amount_paid_cents' => 125050]],
        'participants' => [
            ['member_id' => $creatorId],
            ['member_id' => $m2Id],
        ],
    ]);
    $assertCheck($expRes['status'] === 201, "Created adversarial expense record (HTTP 201)");

    // Add 2nd normal expense with quotes, commas, newlines, and negative-like string in title
    $exp2Res = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Normal "Dinner", with commas & -2+3 notes',
        'amount' => 500.00,
        'expense_date' => '2026-09-27',
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $m2Id, 'amount_paid_cents' => 50000]],
        'participants' => [
            ['member_id' => $creatorId],
            ['member_id' => $m2Id],
        ],
    ]);
    $assertCheck($exp2Res['status'] === 201, "Created 2nd test expense record (HTTP 201)");

    // SEC04-T11: Fetch live generated CSV over HTTP and parse with PHP CSV parser
    $csvHttpRes = getRaw("{$baseUrl}/api/groups/{$token}/export.csv");
    $assertCheck($csvHttpRes['status'] === 200, "GET /api/groups/{token}/export.csv returns HTTP 200 OK");
    $assertCheck(str_contains($csvHttpRes['content_type'], 'text/csv'), "Content-Type is text/csv; charset=UTF-8");
    $assertCheck(str_contains($csvHttpRes['headers'], 'Content-Disposition: attachment'), "Content-Disposition header specifies attachment download");

    $rawCsv = $csvHttpRes['body'];
    $csvLines = array_filter(explode("\n", trim($rawCsv)), fn($l) => trim($l) !== '');
    $assertCheck(count($csvLines) === 3, "Generated CSV contains exactly 3 rows (1 header + 2 expenses)");

    // Parse rows using str_getcsv
    $rows = [];
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $rawCsv);
    rewind($stream);
    while (($data = fgetcsv($stream)) !== false) {
        $rows[] = $data;
    }
    fclose($stream);

    $headerRow = $rows[0];
    $assertCheck($headerRow === ['ID', 'Date', 'Description', 'Category', 'Split Type', 'Total Amount (INR)', 'Paid By', 'Allocations'], "CSV header row exactly matches expected 8 column schema");

    // Find the adversarial row and normal row from the parsed CSV rows
    $advRow = null;
    $normalRow = null;
    foreach (array_slice($rows, 1) as $r) {
        if (str_contains($r[2], '=SUM')) {
            $advRow = $r;
        } elseif (str_contains($r[2], 'Normal "Dinner"')) {
            $normalRow = $r;
        }
    }

    $assertCheck($advRow !== null, "Found exported adversarial expense in CSV");
    $assertCheck($normalRow !== null, "Found exported normal expense in CSV");

    // Inspect Adversarial Expense Row
    if ($advRow) {
        $advDesc = $advRow[2];
        $advCat = $advRow[3];
        $advAmount = $advRow[5];
        $advPaidBy = $advRow[6];
        $advAllocations = $advRow[7];

        $assertCheck(str_starts_with($advDesc, "'="), "Adversarial Description is neutralized with single quote prefix ('=SUM(1+1)...)");
        $assertCheck(!str_starts_with($advDesc, "=SUM"), "Adversarial Description does NOT start with raw '=' formula indicator");
        $assertCheck(str_starts_with($advCat, "'+"), "Adversarial Category name is neutralized with single quote prefix ('+VIP_CATEGORY)");
        $assertCheck(str_starts_with($advPaidBy, "'="), "Adversarial Payer Name in 'Paid By' is neutralized with single quote prefix");
        $assertCheck(str_contains($advAllocations, "'@ATTACKER_SPLIT"), "Adversarial Debtor Name in 'Allocations' is neutralized with single quote prefix ('@ATTACKER_SPLIT)");
        $assertCheck($advAmount === '1250.50', "Total amount preserved as valid numeric decimal '1250.50'");
    }

    // SEC04-T12: Existing export regression verification on Normal Row
    if ($normalRow) {
        $normDesc = $normalRow[2];
        $normAmount = $normalRow[5];

        $assertCheck(count($normalRow) === 8, "SEC04-T12: Normal row has exactly 8 columns");
        $assertCheck($normAmount === '500.00', "SEC04-T12: Amount formatted as valid decimal '500.00'");
        $assertCheck($normDesc === 'Normal "Dinner", with commas & -2+3 notes', "SEC04-T12: Description with double-quotes and commas parsed cleanly by CSV parser");
    }

} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n--------------------------------------------------------------------\n";
echo " SEC-04 TEST SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "--------------------------------------------------------------------\n\n";

exit($failed > 0 ? 1 : 0);
