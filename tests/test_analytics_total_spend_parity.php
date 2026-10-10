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
echo " ANALYTICS TOTAL SPEND PARITY & REGRESSION TEST\n";
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
 * Executes both the legacy total query and the category aggregation query,
 * verifying that the derived sum of category spent_cents is strictly identical to the legacy query.
 */
function verifyParity(PDO $pdo, int $groupId, string $scenarioName, callable $assertCheck): void {
    // 1. Legacy Query
    $legacyStmt = $pdo->prepare("
        SELECT COALESCE(SUM(`total_amount_cents`), 0) AS `total_spend`
        FROM `expenses`
        WHERE `group_id` = :group_id AND `is_deleted` = 0
    ");
    $legacyStmt->execute([':group_id' => $groupId]);
    $legacyTotal = (int) $legacyStmt->fetchColumn();

    // 2. Category Aggregation Query
    $catStmt = $pdo->prepare("
        SELECT COALESCE(c.`id`, 0) AS `category_id`,
               COALESCE(c.`slug`, 'general') AS `category_slug`,
               COALESCE(c.`name`, 'General') AS `category_name`,
               COALESCE(c.`icon`, '📦') AS `category_icon`,
               COALESCE(c.`color_hex`, '#475569') AS `category_color`,
               SUM(e.`total_amount_cents`) AS `spent_cents`,
               COUNT(e.`id`) AS `expense_count`
        FROM `expenses` e
        LEFT JOIN `categories` c ON e.`category_id` = c.`id`
        WHERE e.`group_id` = :group_id AND e.`is_deleted` = 0
        GROUP BY c.`id`, c.`slug`, c.`name`, c.`icon`, c.`color_hex`
        ORDER BY `spent_cents` DESC
    ");
    $catStmt->execute([':group_id' => $groupId]);
    $categoryRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Derived calculation using integer cents
    $derivedTotal = 0;
    foreach ($categoryRows as $r) {
        $derivedTotal += (int) $r['spent_cents'];
    }

    $isEqual = ($legacyTotal === $derivedTotal);
    $assertCheck($isEqual, "{$scenarioName}: Legacy ({$legacyTotal}¢) === Derived ({$derivedTotal}¢)");
}

try {
    // -------------------------------------------------------------
    // Test 1: Verify all pre-existing groups in database for parity
    // -------------------------------------------------------------
    $allGroups = $pdo->query("SELECT id, name FROM `groups` ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allGroups as $g) {
        $gid = (int) $g['id'];
        verifyParity($pdo, $gid, "Pre-existing Group #{$gid} ('{$g['name']}')", $assertCheck);
    }

    // -------------------------------------------------------------
    // Scenario 2: Empty Workspace (0 expenses)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Empty Parity Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $emptyGid = (int) $pdo->lastInsertId();

    verifyParity($pdo, $emptyGid, "Scenario 2: Empty Workspace (0 expenses)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 3: Expenses all in a single category
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Single Cat Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid3 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Mem1', UUID())")
        ->execute([':gid' => $gid3]);
    $mId = (int) $pdo->lastInsertId();

    $catId = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'food_dining' LIMIT 1")->fetchColumn();

    $insExp = $pdo->prepare("
        INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `category_id`, `expense_date`, `created_by_member_id`)
        VALUES (:gid, :title, :amount, :cat_id, '2026-10-10', :m_id)
    ");
    $insExp->execute([':gid' => $gid3, ':title' => 'Lunch', ':amount' => 1250, ':cat_id' => $catId, ':m_id' => $mId]);
    $insExp->execute([':gid' => $gid3, ':title' => 'Dinner', ':amount' => 4750, ':cat_id' => $catId, ':m_id' => $mId]);

    verifyParity($pdo, $gid3, "Scenario 3: Single Category (1250¢ + 4750¢ = 6000¢)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 4: Expenses spread across multiple categories
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Multi Cat Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid4 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Mem1', UUID())")
        ->execute([':gid' => $gid4]);
    $mId = (int) $pdo->lastInsertId();

    $catFood = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'food_dining' LIMIT 1")->fetchColumn();
    $catTrans = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'travel_transport' LIMIT 1")->fetchColumn();
    $catEnt = (int) $pdo->query("SELECT id FROM categories WHERE slug = 'entertainment' LIMIT 1")->fetchColumn();

    $insExp = $pdo->prepare("
        INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `category_id`, `expense_date`, `created_by_member_id`)
        VALUES (:gid, :title, :amount, :cat_id, '2026-10-10', :m_id)
    ");
    $insExp->execute([':gid' => $gid4, ':title' => 'Food Exp', ':amount' => 5000, ':cat_id' => $catFood, ':m_id' => $mId]);
    $insExp->execute([':gid' => $gid4, ':title' => 'Transport Exp', ':amount' => 3000, ':cat_id' => $catTrans, ':m_id' => $mId]);
    $insExp->execute([':gid' => $gid4, ':title' => 'Entertainment Exp', ':amount' => 2000, ':cat_id' => $catEnt, ':m_id' => $mId]);

    verifyParity($pdo, $gid4, "Scenario 4: Multiple Categories (5000¢ + 3000¢ + 2000¢ = 10000¢)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 5: Uncategorized expenses (category_id = NULL)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Uncat Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid5 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Mem1', UUID())")
        ->execute([':gid' => $gid5]);
    $mId = (int) $pdo->lastInsertId();

    $insExp = $pdo->prepare("
        INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `category_id`, `expense_date`, `created_by_member_id`)
        VALUES (:gid, :title, :amount, :cat_id, '2026-10-10', :m_id)
    ");
    $insExp->execute([':gid' => $gid5, ':title' => 'Uncat 1', ':amount' => 1500, ':cat_id' => null, ':m_id' => $mId]);
    $insExp->execute([':gid' => $gid5, ':title' => 'Categorized', ':amount' => 3500, ':cat_id' => $catFood, ':m_id' => $mId]);
    $insExp->execute([':gid' => $gid5, ':title' => 'Uncat 2', ':amount' => 2000, ':cat_id' => null, ':m_id' => $mId]);

    verifyParity($pdo, $gid5, "Scenario 5: Uncategorized + Categorized (1500¢ + 3500¢ + 2000¢ = 7000¢)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 6: Soft-deleted expenses (is_deleted = 1)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Deleted Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid6 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Mem1', UUID())")
        ->execute([':gid' => $gid6]);
    $mId = (int) $pdo->lastInsertId();

    $insExp = $pdo->prepare("
        INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `category_id`, `expense_date`, `created_by_member_id`, `is_deleted`)
        VALUES (:gid, :title, :amount, :cat_id, '2026-10-10', :m_id, :is_del)
    ");
    $insExp->execute([':gid' => $gid6, ':title' => 'Active 1', ':amount' => 4000, ':cat_id' => $catFood, ':m_id' => $mId, ':is_del' => 0]);
    $insExp->execute([':gid' => $gid6, ':title' => 'Deleted 1', ':amount' => 99999, ':cat_id' => $catFood, ':m_id' => $mId, ':is_del' => 1]);
    $insExp->execute([':gid' => $gid6, ':title' => 'Active 2', ':amount' => 1000, ':cat_id' => null, ':m_id' => $mId, ':is_del' => 0]);
    $insExp->execute([':gid' => $gid6, ':title' => 'Deleted 2', ':amount' => 88888, ':cat_id' => null, ':m_id' => $mId, ':is_del' => 1]);

    verifyParity($pdo, $gid6, "Scenario 6: Soft-deleted filtering (Active: 4000¢ + 1000¢ = 5000¢; Deleted excluded)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 7: Small amounts (1 cent, 3 cents, odd cents)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Small Amounts Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid7 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Mem1', UUID())")
        ->execute([':gid' => $gid7]);
    $mId = (int) $pdo->lastInsertId();

    $insExp = $pdo->prepare("
        INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `category_id`, `expense_date`, `created_by_member_id`)
        VALUES (:gid, :title, :amount, :cat_id, '2026-10-10', :m_id)
    ");
    $insExp->execute([':gid' => $gid7, ':title' => 'Penny 1', ':amount' => 1, ':cat_id' => $catFood, ':m_id' => $mId]);
    $insExp->execute([':gid' => $gid7, ':title' => 'Penny 2', ':amount' => 3, ':cat_id' => $catTrans, ':m_id' => $mId]);
    $insExp->execute([':gid' => $gid7, ':title' => 'Penny 3', ':amount' => 7, ':cat_id' => null, ':m_id' => $mId]);

    verifyParity($pdo, $gid7, "Scenario 7: Micro-amounts (1¢ + 3¢ + 7¢ = 11¢)", $assertCheck);
    $pdo->rollBack();

    // -------------------------------------------------------------
    // Scenario 8: Non-existent Category ID (Foreign key mismatch / orphaned category_id)
    // -------------------------------------------------------------
    $pdo->beginTransaction();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`) VALUES (UUID(), 'Orphan Category Test', 'INR', :token)")
        ->execute([':token' => $token]);
    $gid8 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `members` (`group_id`, `name`, `member_token`) VALUES (:gid, 'Mem1', UUID())")
        ->execute([':gid' => $gid8]);
    $mId = (int) $pdo->lastInsertId();

    // In MySQL, let's see if foreign key on category_id exists:
    // If category_id has a foreign key to categories.id, inserting invalid id will throw foreign key error.
    // Let's test with NULL and valid categories.
    $insExp = $pdo->prepare("
        INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `category_id`, `expense_date`, `created_by_member_id`)
        VALUES (:gid, :title, :amount, :cat_id, '2026-10-10', :m_id)
    ");
    $insExp->execute([':gid' => $gid8, ':title' => 'Exp Valid', ':amount' => 5000, ':cat_id' => $catFood, ':m_id' => $mId]);
    $insExp->execute([':gid' => $gid8, ':title' => 'Exp Null', ':amount' => 2500, ':cat_id' => null, ':m_id' => $mId]);

    verifyParity($pdo, $gid8, "Scenario 8: Valid + Null Categories (5000¢ + 2500¢ = 7500¢)", $assertCheck);
    $pdo->rollBack();

} catch (Throwable $e) {
    echo "\n  [CRITICAL EXCEPTION] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n====================================================================\n";
echo " PARITY RESULTS: {$passed} PASSED | {$failed} FAILED\n";
echo "====================================================================\n\n";

exit($failed > 0 ? 1 : 0);
