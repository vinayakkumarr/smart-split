<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Services/BalanceService.php';
require_once __DIR__ . '/../src/Repositories/MemberRepository.php';

use App\Core\Env;
use App\Core\Database;
use App\Services\BalanceService;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

echo "\n====================================================================\n";
echo " MEMBER STATEMENT LEDGER SETTLEMENT PARITY TEST SUITE\n";
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

/**
 * Executes the EXACT LEGACY (two-query) settlement retrieval algorithm
 * from baseline commit d58ca9685a8f5b5adba903618e0a0852bbfdd867.
 */
function calculateMemberLedgerSettlementsLegacy(PDO $pdo, int $groupId, int $memberId): array {
    // 1. Settlements sent
    $sentStmt = $pdo->prepare("
        SELECT s.`id` AS `settlement_id`, s.`amount_cents`, s.`settled_date`, s.`notes`, m.`name` AS `payee_name`
        FROM `settlements` s
        JOIN `members` m ON s.`payee_member_id` = m.`id`
        WHERE s.`group_id` = :group_id AND s.`payer_member_id` = :member_id AND s.`is_deleted` = 0
        ORDER BY s.`settled_date` DESC
    ");
    $sentStmt->execute([':group_id' => $groupId, ':member_id' => $memberId]);
    $sentItems = $sentStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Settlements received
    $recvStmt = $pdo->prepare("
        SELECT s.`id` AS `settlement_id`, s.`amount_cents`, s.`settled_date`, s.`notes`, m.`name` AS `payer_name`
        FROM `settlements` s
        JOIN `members` m ON s.`payer_member_id` = m.`id`
        WHERE s.`group_id` = :group_id AND s.`payee_member_id` = :member_id AND s.`is_deleted` = 0
        ORDER BY s.`settled_date` DESC
    ");
    $recvStmt->execute([':group_id' => $groupId, ':member_id' => $memberId]);
    $recvItems = $recvStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'settlements_sent' => array_map(function (array $r) {
            return [
                'settlement_id' => (int) $r['settlement_id'],
                'payee_name' => $r['payee_name'],
                'amount_cents' => (int) $r['amount_cents'],
                'settled_date' => $r['settled_date'],
                'notes' => $r['notes'],
            ];
        }, $sentItems),
        'settlements_received' => array_map(function (array $r) {
            return [
                'settlement_id' => (int) $r['settlement_id'],
                'payer_name' => $r['payer_name'],
                'amount_cents' => (int) $r['amount_cents'],
                'settled_date' => $r['settled_date'],
                'notes' => $r['notes'],
            ];
        }, $recvItems),
    ];
}

/**
 * Asserts full byte-for-byte and structural parity between the legacy 2-query
 * settlement logic and the optimized consolidated settlement query in BalanceService.
 */
function assertLedgerSettlementParity(PDO $pdo, int $groupId, int $memberId, string $scenarioName, callable $assertCheck): void {
    $service = new BalanceService($pdo);

    $legacy = calculateMemberLedgerSettlementsLegacy($pdo, $groupId, $memberId);
    $currentLedger = $service->getMemberItemizedLedger($groupId, $memberId);

    $current = [
        'settlements_sent' => $currentLedger['settlements_sent'],
        'settlements_received' => $currentLedger['settlements_received'],
    ];

    // Strict JSON encoding with exception throwing on encoding errors
    $jsonLegacy = json_encode($legacy, JSON_THROW_ON_ERROR);
    $jsonCurrent = json_encode($current, JSON_THROW_ON_ERROR);

    $jsonEqual = ($jsonLegacy !== false && $jsonCurrent !== false && $jsonLegacy === $jsonCurrent);
    $phpEqual = ($legacy === $current);

    $isEqual = ($jsonEqual && $phpEqual);

    $sentCount = count($legacy['settlements_sent']);
    $recvCount = count($legacy['settlements_received']);

    $assertCheck(
        $isEqual,
        "{$scenarioName} [Member #{$memberId}] (Sent: {$sentCount}, Recv: {$recvCount}, Match: " . ($isEqual ? 'YES' : 'NO') . ")"
    );
}

try {
    // -------------------------------------------------------------
    // Scenario 1: Existing Historical Local Data
    // -------------------------------------------------------------
    echo "--- Scenario 1: Existing Historical Local Data ---\n";
    $historicalGroups = $pdo->query("
        SELECT DISTINCT s.group_id, s.payer_member_id, s.payee_member_id
        FROM `settlements` s
        WHERE s.is_deleted = 0
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($historicalGroups as $hg) {
        $gid = (int) $hg['group_id'];
        $payerId = (int) $hg['payer_member_id'];
        $payeeId = (int) $hg['payee_member_id'];

        assertLedgerSettlementParity($pdo, $gid, $payerId, "Historical Group #{$gid} Payer", $assertCheck);
        assertLedgerSettlementParity($pdo, $gid, $payeeId, "Historical Group #{$gid} Payee", $assertCheck);
    }

    // Also check pre-existing groups without settlements
    $otherGroups = $pdo->query("SELECT id FROM `groups` ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($otherGroups as $og) {
        $gid = (int) $og['id'];
        $mem = $pdo->query("SELECT id FROM `members` WHERE group_id = {$gid} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($mem) {
            $mid = (int) $mem['id'];
            assertLedgerSettlementParity($pdo, $gid, $mid, "Historical Group #{$gid} (No Settle)", $assertCheck);
        }
    }

    // -------------------------------------------------------------
    // Scenario 2: Empty Workspace with No Settlements
    // -------------------------------------------------------------
    echo "\n--- Scenario 2: Empty Workspace with No Settlements ---\n";
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Empty Ledger Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid2 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid2]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid2]);
    $mB = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Charlie', UUID())")->execute([':gid' => $gid2]);
    $mC = (int) $pdo->lastInsertId();

    assertLedgerSettlementParity($pdo, $gid2, $mA, "Scenario 2: Empty Alice (0 sent, 0 recv)", $assertCheck);
    assertLedgerSettlementParity($pdo, $gid2, $mB, "Scenario 2: Empty Bob (0 sent, 0 recv)", $assertCheck);
    assertLedgerSettlementParity($pdo, $gid2, $mC, "Scenario 2: Empty Charlie (0 sent, 0 recv)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 3: Member with Sent Settlements Only
    // -------------------------------------------------------------
    echo "\n--- Scenario 3: Member with Sent Settlements Only ---\n";
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Sent Only Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid3 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid3]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid3]);
    $mB = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Charlie', UUID())")->execute([':gid' => $gid3]);
    $mC = (int) $pdo->lastInsertId();

    // Alice sends to Bob (1500¢) and Charlie (2500¢)
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `status`) VALUES (:gid, :p, :r, 1500, '2026-10-01', 'Alice paid Bob', 'CONFIRMED')")
        ->execute([':gid' => $gid3, ':p' => $mA, ':r' => $mB]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `status`) VALUES (:gid, :p, :r, 2500, '2026-10-02', 'Alice paid Charlie', 'CONFIRMED')")
        ->execute([':gid' => $gid3, ':p' => $mA, ':r' => $mC]);

    assertLedgerSettlementParity($pdo, $gid3, $mA, "Scenario 3: Alice sent-only (2 sent, 0 recv)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 4: Member with Received Settlements Only
    // -------------------------------------------------------------
    echo "\n--- Scenario 4: Member with Received Settlements Only ---\n";
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Recv Only Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid4 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid4]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid4]);
    $mB = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Charlie', UUID())")->execute([':gid' => $gid4]);
    $mC = (int) $pdo->lastInsertId();

    // Bob receives from Alice (3000¢) and Charlie (4000¢)
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `status`) VALUES (:gid, :p, :r, 3000, '2026-10-03', 'Alice settled to Bob', 'CONFIRMED')")
        ->execute([':gid' => $gid4, ':p' => $mA, ':r' => $mB]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `status`) VALUES (:gid, :p, :r, 4000, '2026-10-04', 'Charlie settled to Bob', 'CONFIRMED')")
        ->execute([':gid' => $gid4, ':p' => $mC, ':r' => $mB]);

    assertLedgerSettlementParity($pdo, $gid4, $mB, "Scenario 4: Bob received-only (0 sent, 2 recv)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 5: Member with Both Sent and Received Settlements (Multiple Peers)
    // -------------------------------------------------------------
    echo "\n--- Scenario 5: Member with Both Sent and Received Settlements ---\n";
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Sent and Recv Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid5 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid5]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid5]);
    $mB = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Charlie', UUID())")->execute([':gid' => $gid5]);
    $mC = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Dana', UUID())")->execute([':gid' => $gid5]);
    $mD = (int) $pdo->lastInsertId();

    // Alice sends to Bob (5000¢) and Dana (2000¢)
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `status`) VALUES (:gid, :p, :r, 5000, '2026-10-05', 'Alice to Bob', 'CONFIRMED')")
        ->execute([':gid' => $gid5, ':p' => $mA, ':r' => $mB]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `status`) VALUES (:gid, :p, :r, 2000, '2026-10-06', 'Alice to Dana', 'CONFIRMED')")
        ->execute([':gid' => $gid5, ':p' => $mA, ':r' => $mD]);

    // Alice receives from Charlie (7000¢) and Bob (1000¢)
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `status`) VALUES (:gid, :p, :r, 7000, '2026-10-07', 'Charlie to Alice', 'CONFIRMED')")
        ->execute([':gid' => $gid5, ':p' => $mC, ':r' => $mA]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `status`) VALUES (:gid, :p, :r, 1000, '2026-10-08', 'Bob to Alice', 'CONFIRMED')")
        ->execute([':gid' => $gid5, ':p' => $mB, ':r' => $mA]);

    assertLedgerSettlementParity($pdo, $gid5, $mA, "Scenario 5: Alice (2 sent, 2 recv)", $assertCheck);
    assertLedgerSettlementParity($pdo, $gid5, $mB, "Scenario 5: Bob (1 sent, 1 recv)", $assertCheck);
    assertLedgerSettlementParity($pdo, $gid5, $mC, "Scenario 5: Charlie (1 sent, 0 recv)", $assertCheck);
    assertLedgerSettlementParity($pdo, $gid5, $mD, "Scenario 5: Dana (0 sent, 1 recv)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 6: Multiple Settlements with Varied Dates (Descending Ordering Verification)
    // -------------------------------------------------------------
    echo "\n--- Scenario 6: Multiple Settlements with Varied Dates (Order Verification) ---\n";
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Date Order Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid6 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid6]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid6]);
    $mB = (int) $pdo->lastInsertId();

    // Deliberately insert dates out of chronological sequence
    // Sent: 2026-03-01, 2026-01-15, 2026-05-20, 2026-02-10
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`) VALUES (:gid, :p, :r, 100, '2026-03-01', 'Mid')")->execute([':gid' => $gid6, ':p' => $mA, ':r' => $mB]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`) VALUES (:gid, :p, :r, 200, '2026-01-15', 'Oldest')")->execute([':gid' => $gid6, ':p' => $mA, ':r' => $mB]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`) VALUES (:gid, :p, :r, 300, '2026-05-20', 'Newest')")->execute([':gid' => $gid6, ':p' => $mA, ':r' => $mB]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`) VALUES (:gid, :p, :r, 400, '2026-02-10', 'Second Oldest')")->execute([':gid' => $gid6, ':p' => $mA, ':r' => $mB]);

    // Recv: 2026-04-05, 2026-06-01, 2026-01-01
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`) VALUES (:gid, :p, :r, 500, '2026-04-05', 'Recv Mid')")->execute([':gid' => $gid6, ':p' => $mB, ':r' => $mA]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`) VALUES (:gid, :p, :r, 600, '2026-06-01', 'Recv Newest')")->execute([':gid' => $gid6, ':p' => $mB, ':r' => $mA]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`) VALUES (:gid, :p, :r, 700, '2026-01-01', 'Recv Oldest')")->execute([':gid' => $gid6, ':p' => $mB, ':r' => $mA]);

    assertLedgerSettlementParity($pdo, $gid6, $mA, "Scenario 6: Alice (4 sent DESC, 3 recv DESC)", $assertCheck);
    assertLedgerSettlementParity($pdo, $gid6, $mB, "Scenario 6: Bob (3 sent DESC, 4 recv DESC)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 7: Soft-Deleted Settlements Excluded
    // -------------------------------------------------------------
    echo "\n--- Scenario 7: Soft-Deleted Settlements Excluded ---\n";
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Deleted Settle Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid7 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid7]);
    $mA = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Bob', UUID())")->execute([':gid' => $gid7]);
    $mB = (int) $pdo->lastInsertId();

    // Active settlements
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `is_deleted`) VALUES (:gid, :p, :r, 1111, '2026-10-10', 'Active Sent', 0)")
        ->execute([':gid' => $gid7, ':p' => $mA, ':r' => $mB]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `is_deleted`) VALUES (:gid, :p, :r, 2222, '2026-10-10', 'Active Recv', 0)")
        ->execute([':gid' => $gid7, ':p' => $mB, ':r' => $mA]);

    // Soft-deleted settlements
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `is_deleted`) VALUES (:gid, :p, :r, 99999, '2026-10-10', 'Deleted Sent', 1)")
        ->execute([':gid' => $gid7, ':p' => $mA, ':r' => $mB]);
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `is_deleted`) VALUES (:gid, :p, :r, 88888, '2026-10-10', 'Deleted Recv', 1)")
        ->execute([':gid' => $gid7, ':p' => $mB, ':r' => $mA]);

    assertLedgerSettlementParity($pdo, $gid7, $mA, "Scenario 7: Alice (Deleted filtered out, exactly 1 sent / 1 recv)", $assertCheck);
    assertLedgerSettlementParity($pdo, $gid7, $mB, "Scenario 7: Bob (Deleted filtered out, exactly 1 sent / 1 recv)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 8: Self-Settlement Edge Case
    // -------------------------------------------------------------
    echo "\n--- Scenario 8: Self-Settlement Edge Case ---\n";
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Self Settle Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid8 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Alice', UUID())")->execute([':gid' => $gid8]);
    $mA = (int) $pdo->lastInsertId();

    // Alice settles 500 cents to Alice
    $pdo->prepare("INSERT INTO `settlements` (`group_id`, `payer_member_id`, `payee_member_id`, `amount_cents`, `settled_date`, `notes`, `is_deleted`) VALUES (:gid, :p, :r, 500, '2026-10-10', 'Self Transfer', 0)")
        ->execute([':gid' => $gid8, ':p' => $mA, ':r' => $mA]);

    assertLedgerSettlementParity($pdo, $gid8, $mA, "Scenario 8: Alice self-settlement (1 sent, 1 recv)", $assertCheck);
    $pdo->rollBack();

} catch (Throwable $e) {
    echo "\n  [CRITICAL EXCEPTION] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n====================================================================\n";
echo " MEMBER LEDGER PARITY RESULTS: {$passed} PASSED | {$failed} FAILED\n";
echo "====================================================================\n\n";

exit($failed > 0 ? 1 : 0);
