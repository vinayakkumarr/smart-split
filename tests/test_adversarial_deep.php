<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Env;
use App\Core\Database;

Env::load(dirname(__DIR__) . '/.env');
$baseUrl = 'http://localhost:8000';

function postJson(string $path, array $data): array {
    global $baseUrl;
    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Requested-With: fetch']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($res, true), 'raw' => $res];
}

echo "--- 1. Testing Duplicate Member Name in Group ---\n";
$grp = postJson('/api/groups', ['name' => 'Dup Member Group', 'creator_name' => 'Rahul']);
$tok = $grp['body']['data']['group']['invite_token'];

$dupMem = postJson("/api/groups/$tok/members", ['name' => 'Rahul']);
echo "Duplicate member addition code: {$dupMem['code']} | Error: " . ($dupMem['body']['error']['message'] ?? 'none') . "\n";

echo "\n--- 2. Testing Case-Insensitive Duplicate Member Name ('rahul' vs 'Rahul') ---\n";
$dupCaseMem = postJson("/api/groups/$tok/members", ['name' => 'rahul']);
echo "Case-insensitive duplicate member code: {$dupCaseMem['code']} | Error: " . ($dupCaseMem['body']['error']['message'] ?? 'none') . "\n";

echo "\n--- 3. Testing Self-Settlement (Payer == Payee) ---\n";
$creatorId = $grp['body']['data']['creator']['id'];
$selfSettle = postJson("/api/groups/$tok/settlements", [
    'payer_id' => $creatorId,
    'payee_id' => $creatorId,
    'amount_cents' => 1000
]);
echo "Self-settlement code: {$selfSettle['code']} | Error: " . ($selfSettle['body']['error']['message'] ?? 'none') . "\n";

echo "\n--- 4. Testing 0 Amount Settlement ---\n";
$mem2 = postJson("/api/groups/$tok/members", ['name' => 'Sneha'])['body']['data']['member']['id'];
$zeroSettle = postJson("/api/groups/$tok/settlements", [
    'payer_id' => $creatorId,
    'payee_id' => $mem2,
    'amount_cents' => 0
]);
echo "Zero amount settlement code: {$zeroSettle['code']} | Error: " . ($zeroSettle['body']['error']['message'] ?? 'none') . "\n";

echo "\n--- 5. Testing Negative Amount Settlement ---\n";
$negSettle = postJson("/api/groups/$tok/settlements", [
    'payer_id' => $creatorId,
    'payee_id' => $mem2,
    'amount_cents' => -500
]);
echo "Negative amount settlement code: {$negSettle['code']} | Error: " . ($negSettle['body']['error']['message'] ?? 'none') . "\n";

echo "\n--- 6. Testing Expense with 0 Participants in EQUAL Split ---\n";
$zeroParticipantExp = postJson("/api/groups/$tok/expenses", [
    'title' => 'No participants',
    'amount_cents' => 1000,
    'split_type' => 'EQUAL',
    'payers' => [['member_id' => $creatorId, 'amount_cents' => 1000]],
    'split_members' => []
]);
echo "Zero participant equal split code: {$zeroParticipantExp['code']} | Error: " . ($zeroParticipantExp['body']['error']['message'] ?? 'none') . "\n";

echo "\n--- 7. Testing Single Payer without Explicit Payers Array (Payer Shortcut) ---\n";
$shortcutExp = postJson("/api/groups/$tok/expenses", [
    'title' => 'Single Payer Shortcut',
    'amount_cents' => 2000,
    'split_type' => 'EQUAL',
    'payer_id' => $creatorId
]);
echo "Shortcut single payer expense code: {$shortcutExp['code']} | Expense ID: " . ($shortcutExp['body']['data']['expense']['id'] ?? 'none') . "\n";
