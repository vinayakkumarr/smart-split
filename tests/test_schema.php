<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Core/Env.php';
require dirname(__DIR__) . '/src/Core/Database.php';

use App\Core\Env;
use App\Core\Database;

Env::load(dirname(__DIR__) . '/.env');

$pdo = Database::getConnection();

// 1. Verify all 8 tables exist
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

$expectedTables = [
    'groups',
    'members',
    'expenses',
    'expense_payers',
    'expense_splits',
    'settlements',
    'activity_logs',
    'categories',
    'recurring_rules',
    'expense_items',
    'expense_item_assignments',
    'expense_templates',
    'receipt_attachments'
];

$missingTables = array_diff($expectedTables, $tables);
if (!empty($missingTables)) {
    echo "FAILED: Missing tables: " . implode(', ', $missingTables) . "\n";
    exit(1);
}

echo "PASSED: All 12 core database tables exist.\n";

// 2. Test Transaction & Foreign Key Constraint Enforcement
$pdo->beginTransaction();

try {
    // Insert Group
    $groupStmt = $pdo->prepare("
        INSERT INTO `groups` (`uuid`, `name`, `currency_code`, `invite_token`)
        VALUES (:uuid, :name, :currency, :token)
    ");
    $groupStmt->execute([
        ':uuid' => '00000000-0000-0000-0000-000000000001',
        ':name' => 'Schema Test Group',
        ':currency' => 'INR',
        ':token' => 'test_token_12345',
    ]);
    $groupId = (int) $pdo->lastInsertId();

    // Insert Member
    $memberStmt = $pdo->prepare("
        INSERT INTO `members` (`group_id`, `name`, `member_token`)
        VALUES (:group_id, :name, :token)
    ");
    $memberStmt->execute([
        ':group_id' => $groupId,
        ':name' => 'Alice',
        ':token' => 'alice_token_12345',
    ]);
    $memberId = (int) $pdo->lastInsertId();

    // Insert Expense (total 1000 cents = $10.00)
    $expenseStmt = $pdo->prepare("
        INSERT INTO `expenses` (`group_id`, `title`, `total_amount_cents`, `split_type`, `expense_date`, `created_by_member_id`)
        VALUES (:group_id, :title, :amount, 'EQUAL', '2026-09-21', :creator)
    ");
    $expenseStmt->execute([
        ':group_id' => $groupId,
        ':title' => 'Test Dinner',
        ':amount' => 1000,
        ':creator' => $memberId,
    ]);
    $expenseId = (int) $pdo->lastInsertId();

    // Test Foreign Key Violation: Inserting split with non-existent member_id (999999)
    $fkFailedAsExpected = false;
    try {
        $invalidSplitStmt = $pdo->prepare("
            INSERT INTO `expense_splits` (`expense_id`, `member_id`, `amount_owed_cents`)
            VALUES (:expense_id, 999999, 500)
        ");
        $invalidSplitStmt->execute([':expense_id' => $expenseId]);
    } catch (PDOException $e) {
        $fkFailedAsExpected = true;
    }

    if (!$fkFailedAsExpected) {
        echo "FAILED: Foreign key violation was not caught!\n";
        $pdo->rollBack();
        exit(1);
    }

    echo "PASSED: Foreign key constraints correctly reject invalid relational inserts.\n";

    // Clean rollback so test data does not pollute database
    $pdo->rollBack();
    echo "PASSED: Transaction rollBack executed cleanly.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR during schema test: " . $e->getMessage() . "\n";
    exit(1);
}
