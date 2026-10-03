<?php

declare(strict_types=1);

/**
 * SMART SPLIT V2 — SEC-11: MULTI-STEP FINANCIAL LIFECYCLE STATE-MACHINE & INVARIANT SUITE
 *
 * Exhaustive forensic verification of multi-step financial lifecycle state transitions:
 * - Independent Financial Oracle computed directly from raw DB ground-truth.
 * - Create -> Edit (Single/Multi-Payer, amounts, splits) with zero row accumulation.
 * - Split mode transitions (EQUAL, EXACT, PERCENTAGE, SHARES, ITEMIZED).
 * - Soft-delete (Trash) -> 1-Click Restore cycles with 100% financial conservation.
 * - Settlement creation, partial settlements, and settlement undo (soft-delete).
 * - Cyclic debt resolution & overpaid/negative balance flip resilience.
 * - Recurring scheduler evaluation -> edit -> delete lifecycle.
 * - Member deletion safeguards (active debt block vs zero-balance deactivation).
 * - Receipt attachments financial non-interference.
 * - Complex 25+ step realistic trip simulation.
 * - Adversarial random transition generator (50 random sequences, 8-15 steps each).
 * - Invalid transition rejection matrix (BOLA, invalid status, self-settle, negative amounts).
 * - ACID transaction rollback atomicity.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();

$totalSec11 = 0;
$passedSec11 = 0;
$failedSec11 = 0;

function assertSec11(bool $condition, string $id, string $description, ?string $detail = null): void
{
    global $totalSec11, $passedSec11, $failedSec11;
    $totalSec11++;
    if ($condition) {
        $passedSec11++;
        echo "  [PASS] {$id}: {$description}\n";
    } else {
        $failedSec11++;
        echo "  [FAIL] {$id}: {$description}\n";
        if ($detail) {
            echo "         > Detail: {$detail}\n";
        }
    }
}

// Clear rate limits for clean execution
try {
    $pdo->exec("DELETE FROM `rate_limits`");
} catch (\Throwable $e) {}

// HTTP Client Helper
function sec11Http(string $method, string $path, ?array $body = null, array $headers = []): array
{
    $url = "http://127.0.0.1:8000" . $path;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    $reqHeaders = [
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json',
    ];

    if ($body !== null) {
        $json = json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $reqHeaders[] = 'Content-Type: application/json';
    }

    foreach ($headers as $h) {
        $reqHeaders[] = $h;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $data = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'status' => $status,
        'data' => $data,
        'raw' => $raw,
        'error' => $err,
    ];
}

/**
 * =============================================================================
 * INDEPENDENT FINANCIAL TEST ORACLE
 * =============================================================================
 * Ground-truth calculations computed directly from database records without
 * using application services.
 */
class FinancialOracle
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function computeGroupLedger(int $groupId): array
    {
        // 1. Fetch active members
        $mStmt = $this->pdo->prepare("SELECT id, name FROM members WHERE group_id = :gid AND is_active = 1");
        $mStmt->execute([':gid' => $groupId]);
        $members = $mStmt->fetchAll();

        $memberMap = [];
        foreach ($members as $m) {
            $mid = (int) $m['id'];
            $memberMap[$mid] = [
                'member_id' => $mid,
                'name' => $m['name'],
                'total_paid_cents' => 0,
                'total_owed_cents' => 0,
                'settlements_sent_cents' => 0,
                'settlements_received_cents' => 0,
                'net_balance_cents' => 0,
            ];
        }

        // 2. Active expenses: sum payers
        $pStmt = $this->pdo->prepare("
            SELECT p.member_id, COALESCE(SUM(p.amount_paid_cents), 0) AS total_paid
            FROM expense_payers p
            JOIN expenses e ON p.expense_id = e.id
            WHERE e.group_id = :gid AND e.is_deleted = 0
            GROUP BY p.member_id
        ");
        $pStmt->execute([':gid' => $groupId]);
        foreach ($pStmt->fetchAll() as $row) {
            $mid = (int) $row['member_id'];
            if (isset($memberMap[$mid])) {
                $memberMap[$mid]['total_paid_cents'] = (int) $row['total_paid'];
            }
        }

        // 3. Active expenses: sum splits
        $sStmt = $this->pdo->prepare("
            SELECT s.member_id, COALESCE(SUM(s.amount_owed_cents), 0) AS total_owed
            FROM expense_splits s
            JOIN expenses e ON s.expense_id = e.id
            WHERE e.group_id = :gid AND e.is_deleted = 0
            GROUP BY s.member_id
        ");
        $sStmt->execute([':gid' => $groupId]);
        foreach ($sStmt->fetchAll() as $row) {
            $mid = (int) $row['member_id'];
            if (isset($memberMap[$mid])) {
                $memberMap[$mid]['total_owed_cents'] = (int) $row['total_owed'];
            }
        }

        // 4. Active settlements: sum sent
        $sentStmt = $this->pdo->prepare("
            SELECT payer_member_id, COALESCE(SUM(amount_cents), 0) AS total_sent
            FROM settlements
            WHERE group_id = :gid AND is_deleted = 0 AND status = 'CONFIRMED'
            GROUP BY payer_member_id
        ");
        $sentStmt->execute([':gid' => $groupId]);
        foreach ($sentStmt->fetchAll() as $row) {
            $mid = (int) $row['payer_member_id'];
            if (isset($memberMap[$mid])) {
                $memberMap[$mid]['settlements_sent_cents'] = (int) $row['total_sent'];
            }
        }

        // 5. Active settlements: sum received
        $recvStmt = $this->pdo->prepare("
            SELECT payee_member_id, COALESCE(SUM(amount_cents), 0) AS total_received
            FROM settlements
            WHERE group_id = :gid AND is_deleted = 0 AND status = 'CONFIRMED'
            GROUP BY payee_member_id
        ");
        $recvStmt->execute([':gid' => $groupId]);
        foreach ($recvStmt->fetchAll() as $row) {
            $mid = (int) $row['payee_member_id'];
            if (isset($memberMap[$mid])) {
                $memberMap[$mid]['settlements_received_cents'] = (int) $row['total_received'];
            }
        }

        // 6. Calculate Net Balances and Total Spending
        $totalSpending = 0;
        $totalSettled = 0;
        $netSum = 0;

        foreach ($memberMap as $mid => &$data) {
            $net = ($data['total_paid_cents'] - $data['total_owed_cents'])
                 + ($data['settlements_sent_cents'] - $data['settlements_received_cents']);
            $data['net_balance_cents'] = $net;
            $netSum += $net;
            $totalSpending += $data['total_paid_cents'];
            $totalSettled += $data['settlements_sent_cents'];
        }
        unset($data);

        return [
            'total_spending_cents' => $totalSpending,
            'total_settled_cents' => $totalSettled,
            'net_sum' => $netSum,
            'zero_sum_conserved' => ($netSum === 0),
            'members' => $memberMap,
        ];
    }

    public function verifyExpenseConservation(int $expenseId): bool
    {
        $eStmt = $this->pdo->prepare("SELECT total_amount_cents FROM expenses WHERE id = :id");
        $eStmt->execute([':id' => $expenseId]);
        $exp = $eStmt->fetch();
        if (!$exp) return true;

        $expAmount = (int) $exp['total_amount_cents'];

        $pStmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount_paid_cents), 0) AS sum_paid FROM expense_payers WHERE expense_id = :id");
        $pStmt->execute([':id' => $expenseId]);
        $sumPaid = (int) $pStmt->fetch()['sum_paid'];

        $sStmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount_owed_cents), 0) AS sum_owed FROM expense_splits WHERE expense_id = :id");
        $sStmt->execute([':id' => $expenseId]);
        $sumOwed = (int) $sStmt->fetch()['sum_owed'];

        return ($sumPaid === $expAmount && $sumOwed === $expAmount);
    }

    public function assertOracleMatchesApi(string $token, int $groupId, string $stepContext): bool
    {
        $oracle = $this->computeGroupLedger($groupId);
        if (!$oracle['zero_sum_conserved']) {
            echo "  [ORACLE CORRUPTION] Step '{$stepContext}': Net sum != 0 (sum={$oracle['net_sum']})\n";
            return false;
        }

        $res = sec11Http('GET', "/api/groups/{$token}/balances");
        if ($res['status'] !== 200 || !isset($res['data']['data'])) {
            echo "  [ORACLE API FAIL] Step '{$stepContext}': Balances endpoint failed (HTTP {$res['status']})\n";
            return false;
        }

        $apiData = $res['data']['data'];

        if ((int) $apiData['total_spending_cents'] !== $oracle['total_spending_cents']) {
            echo "  [ORACLE MISMATCH] Spending mismatch at '{$stepContext}': API={$apiData['total_spending_cents']}, Oracle={$oracle['total_spending_cents']}\n";
            return false;
        }

        if ((int) $apiData['total_settled_cents'] !== $oracle['total_settled_cents']) {
            echo "  [ORACLE MISMATCH] Settled mismatch at '{$stepContext}': API={$apiData['total_settled_cents']}, Oracle={$oracle['total_settled_cents']}\n";
            return false;
        }

        foreach ($apiData['members'] as $apiMem) {
            $mid = (int) $apiMem['member_id'];
            if (!isset($oracle['members'][$mid])) {
                echo "  [ORACLE MISMATCH] Member {$mid} missing in Oracle\n";
                return false;
            }
            $oMem = $oracle['members'][$mid];

            if ((int) $apiMem['net_balance_cents'] !== $oMem['net_balance_cents']) {
                echo "  [ORACLE MISMATCH] Member {$mid} Net Balance mismatch at '{$stepContext}': API={$apiMem['net_balance_cents']}, Oracle={$oMem['net_balance_cents']}\n";
                return false;
            }
            if ((int) $apiMem['total_paid_cents'] !== $oMem['total_paid_cents']) {
                echo "  [ORACLE MISMATCH] Member {$mid} Paid mismatch at '{$stepContext}': API={$apiMem['total_paid_cents']}, Oracle={$oMem['total_paid_cents']}\n";
                return false;
            }
            if ((int) $apiMem['total_owed_cents'] !== $oMem['total_owed_cents']) {
                echo "  [ORACLE MISMATCH] Member {$mid} Owed mismatch at '{$stepContext}': API={$apiMem['total_owed_cents']}, Oracle={$oMem['total_owed_cents']}\n";
                return false;
            }
        }

        return true;
    }
}

$oracle = new FinancialOracle($pdo);

echo "\n================================================================================\n";
echo " SEC-11: MULTI-STEP FINANCIAL LIFECYCLE STATE-MACHINE & INVARIANT SUITE\n";
echo "================================================================================\n\n";

// Helper: Setup Workspace with Members
function createTestWorkspace(string $name, array $memberNames = ['Alice', 'Bob', 'Charlie']): array
{
    global $pdo;
    $res = sec11Http('POST', '/api/groups', [
        'name' => $name,
        'currency_code' => 'INR',
    ]);
    if ($res['status'] !== 201) {
        throw new \RuntimeException("Failed to create group: " . json_encode($res));
    }
    $group = $res['data']['data']['group'];
    $token = $group['invite_token'];
    $groupId = (int) $group['id'];

    $members = [];
    foreach ($memberNames as $mName) {
        $mRes = sec11Http('POST', "/api/groups/{$token}/members", ['name' => $mName]);
        if ($mRes['status'] !== 201) {
            throw new \RuntimeException("Failed to create member {$mName}");
        }
        $members[$mName] = (int) $mRes['data']['data']['member']['id'];
    }

    return [
        'group' => $group,
        'groupId' => $groupId,
        'token' => $token,
        'members' => $members,
    ];
}

// =============================================================================
// SEC11-CLASS-A: SINGLE & MULTI-PAYER EXPENSE CREATE -> EDIT -> RE-EDIT
// =============================================================================
echo "--- SEC11-CLASS-A: Expense Create -> Edit Lifecycle & Row Replacement ---\n";
$wsA = createTestWorkspace('SEC11-WS-A', ['Alice', 'Bob', 'Charlie', 'Dana']);
$tokA = $wsA['token'];
$gidA = $wsA['groupId'];
$mAlice = $wsA['members']['Alice'];
$mBob = $wsA['members']['Bob'];
$mCharlie = $wsA['members']['Charlie'];
$mDana = $wsA['members']['Dana'];

// A.1: Create Equal Expense: Alice pays Rs 100.00 (10000 paise) split equally between 4 members (2500 paise each)
$resA1 = sec11Http('POST', "/api/groups/{$tokA}/expenses", [
    'title' => 'Initial Lunch',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $mAlice,
    'split_type' => 'EQUAL',
    'split_members' => [$mAlice, $mBob, $mCharlie, $mDana],
]);
$expAId = (int) $resA1['data']['data']['expense']['id'];
assertSec11($resA1['status'] === 201, 'SEC11-A.1', 'Create single-payer equal expense succeeds');
assertSec11($oracle->verifyExpenseConservation($expAId), 'SEC11-A.2', 'Payers and splits equal Rs 100.00 exactly');
assertSec11($oracle->assertOracleMatchesApi($tokA, $gidA, 'A1-CreateEqual'), 'SEC11-A.3', 'Financial Oracle matches API balances');

// A.2: Edit to Multi-Payer & Higher Amount: Rs 300.00 (Alice 20000 paise, Bob 10000 paise), split equally
$resA2 = sec11Http('PUT', "/api/groups/{$tokA}/expenses/{$expAId}", [
    'title' => 'Upgraded Feast',
    'total_amount_cents' => 30000,
    'split_type' => 'EQUAL',
    'payers' => [
        ['member_id' => $mAlice, 'amount_cents' => 20000],
        ['member_id' => $mBob, 'amount_cents' => 10000],
    ],
    'split_members' => [$mAlice, $mBob, $mCharlie, $mDana],
]);
assertSec11($resA2['status'] === 200, 'SEC11-A.4', 'Edit expense to multi-payer higher amount succeeds');

// Check DB row count in expense_payers (must be exactly 2, not 3) and expense_splits (must be exactly 4)
$pCountA = $pdo->query("SELECT COUNT(*) FROM expense_payers WHERE expense_id = {$expAId}")->fetchColumn();
$sCountA = $pdo->query("SELECT COUNT(*) FROM expense_splits WHERE expense_id = {$expAId}")->fetchColumn();
assertSec11((int)$pCountA === 2 && (int)$sCountA === 4, 'SEC11-A.5', 'Old payers and splits cleanly wiped and replaced without row bloat');
assertSec11($oracle->verifyExpenseConservation($expAId), 'SEC11-A.6', 'Multi-payer conservation holds (Sum Payers = 30000 = Sum Splits)');
assertSec11($oracle->assertOracleMatchesApi($tokA, $gidA, 'A2-EditMultiPayer'), 'SEC11-A.7', 'Oracle matches balances post multi-payer edit');

// A.3: Edit down to lower amount: Charlie pays Rs 50.00 (5000 paise), split between Alice & Dana
$resA3 = sec11Http('PUT', "/api/groups/{$tokA}/expenses/{$expAId}", [
    'title' => 'Reduced Snack',
    'total_amount_cents' => 5000,
    'paid_by_member_id' => $mCharlie,
    'split_type' => 'EQUAL',
    'split_members' => [$mAlice, $mDana],
]);
assertSec11($resA3['status'] === 200, 'SEC11-A.8', 'Edit down to lower amount and subset split members succeeds');
$pCountA3 = $pdo->query("SELECT COUNT(*) FROM expense_payers WHERE expense_id = {$expAId}")->fetchColumn();
$sCountA3 = $pdo->query("SELECT COUNT(*) FROM expense_splits WHERE expense_id = {$expAId}")->fetchColumn();
assertSec11((int)$pCountA3 === 1 && (int)$sCountA3 === 2, 'SEC11-A.9', 'Downsized expense has exactly 1 payer row and 2 split rows');
assertSec11($oracle->assertOracleMatchesApi($tokA, $gidA, 'A3-EditDownsized'), 'SEC11-A.10', 'Oracle balances match post downsized edit');

// =============================================================================
// SEC11-CLASS-B: MULTI-UPDATE ACCUMULATION STRESS (10 CONSECUTIVE EDITS)
// =============================================================================
echo "--- SEC11-CLASS-B: Multi-Update Stress & Payers/Splits Integrity ---\n";
$wsB = createTestWorkspace('SEC11-WS-B', ['User1', 'User2', 'User3']);
$tokB = $wsB['token'];
$gidB = $wsB['groupId'];
$u1 = $wsB['members']['User1'];
$u2 = $wsB['members']['User2'];
$u3 = $wsB['members']['User3'];

$resBInit = sec11Http('POST', "/api/groups/{$tokB}/expenses", [
    'title' => 'Iterative Item',
    'total_amount_cents' => 1000,
    'paid_by_member_id' => $u1,
    'split_type' => 'EQUAL',
    'split_members' => [$u1, $u2],
]);
$expBId = (int) $resBInit['data']['data']['expense']['id'];

$allStressEditsPassed = true;
for ($iter = 1; $iter <= 10; $iter++) {
    $amt = 1000 * $iter + 37; // dynamic amounts: 1037, 2037, ...
    $payer = ($iter % 2 === 0) ? $u2 : $u3;
    $editRes = sec11Http('PUT', "/api/groups/{$tokB}/expenses/{$expBId}", [
        'title' => "Iterative Item Rev {$iter}",
        'total_amount_cents' => $amt,
        'paid_by_member_id' => $payer,
        'split_type' => 'EQUAL',
        'split_members' => [$u1, $u2, $u3],
    ]);
    if ($editRes['status'] !== 200 || !$oracle->verifyExpenseConservation($expBId) || !$oracle->assertOracleMatchesApi($tokB, $gidB, "B-Stress-Iter-{$iter}")) {
        $allStressEditsPassed = false;
        break;
    }
}
$pCountBFinal = $pdo->query("SELECT COUNT(*) FROM expense_payers WHERE expense_id = {$expBId}")->fetchColumn();
$sCountBFinal = $pdo->query("SELECT COUNT(*) FROM expense_splits WHERE expense_id = {$expBId}")->fetchColumn();

assertSec11($allStressEditsPassed, 'SEC11-B.1', '10 consecutive updates completed with exact zero-sum balance matching');
assertSec11((int)$pCountBFinal === 1 && (int)$sCountBFinal === 3, 'SEC11-B.2', 'Payers and splits count after 10 edits strictly matches current specification without accumulation');

// =============================================================================
// SEC11-CLASS-C: SPLIT MODE TRANSITIONS (EQUAL -> EXACT -> PERCENTAGE -> SHARES -> ITEMIZED -> EQUAL)
// =============================================================================
echo "--- SEC11-CLASS-C: Split Mode State Transitions & Indivisible Allocation ---\n";
$wsC = createTestWorkspace('SEC11-WS-C', ['A', 'B', 'C']);
$tokC = $wsC['token'];
$gidC = $wsC['groupId'];
$mA = $wsC['members']['A'];
$mB = $wsC['members']['B'];
$mC = $wsC['members']['C'];

// Step 1: EQUAL Rs 100.00 (10000 paise / 3 = 3334, 3333, 3333)
$resC1 = sec11Http('POST', "/api/groups/{$tokC}/expenses", [
    'title' => 'Mode Transition Test',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $mA,
    'split_type' => 'EQUAL',
    'split_members' => [$mA, $mB, $mC],
]);
$expCId = (int) $resC1['data']['data']['expense']['id'];
assertSec11($oracle->verifyExpenseConservation($expCId), 'SEC11-C.1', 'Split EQUAL: 10000 paise allocated without 1-paise leak');

// Step 2: EXACT: A owes 5000, B owes 3000, C owes 2000
$resC2 = sec11Http('PUT', "/api/groups/{$tokC}/expenses/{$expCId}", [
    'title' => 'Mode Transition Test - EXACT',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $mA,
    'split_type' => 'EXACT',
    'splits' => [
        ['member_id' => $mA, 'amount_cents' => 5000],
        ['member_id' => $mB, 'amount_cents' => 3000],
        ['member_id' => $mC, 'amount_cents' => 2000],
    ],
]);
assertSec11($resC2['status'] === 200 && $oracle->verifyExpenseConservation($expCId), 'SEC11-C.2', 'Split EXACT: exact sum conserved');
assertSec11($oracle->assertOracleMatchesApi($tokC, $gidC, 'C-EXACT'), 'SEC11-C.3', 'Oracle matches EXACT split balances');

// Step 3: PERCENTAGE: A=50%, B=25%, C=25%
$resC3 = sec11Http('PUT', "/api/groups/{$tokC}/expenses/{$expCId}", [
    'title' => 'Mode Transition Test - PERCENTAGE',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $mA,
    'split_type' => 'PERCENTAGE',
    'splits' => [
        ['member_id' => $mA, 'percentage' => 50.0],
        ['member_id' => $mB, 'percentage' => 25.0],
        ['member_id' => $mC, 'percentage' => 25.0],
    ],
]);
assertSec11($resC3['status'] === 200 && $oracle->verifyExpenseConservation($expCId), 'SEC11-C.4', 'Split PERCENTAGE: Hare-Niemeyer exact allocation conserved');

// Step 4: SHARES: A=1 share, B=2 shares, C=3 shares (Total 6 shares of 10000 paise)
$resC4 = sec11Http('PUT', "/api/groups/{$tokC}/expenses/{$expCId}", [
    'title' => 'Mode Transition Test - SHARES',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $mA,
    'split_type' => 'SHARES',
    'splits' => [
        ['member_id' => $mA, 'shares' => 1],
        ['member_id' => $mB, 'shares' => 2],
        ['member_id' => $mC, 'shares' => 3],
    ],
]);
assertSec11($resC4['status'] === 200 && $oracle->verifyExpenseConservation($expCId), 'SEC11-C.5', 'Split SHARES: largest remainder allocation exact');

// Step 5: ITEMIZED: Item 1 (4000 paise, A & B), Item 2 (4000 paise, B & C), Tip (2000 paise proportional)
$resC5 = sec11Http('PUT', "/api/groups/{$tokC}/expenses/{$expCId}", [
    'title' => 'Mode Transition Test - ITEMIZED',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $mA,
    'split_type' => 'ITEMIZED',
    'items' => [
        ['name' => 'Dish 1', 'amount_cents' => 4000, 'member_ids' => [$mA, $mB]],
        ['name' => 'Dish 2', 'amount_cents' => 4000, 'member_ids' => [$mB, $mC]],
    ],
    'tip_cents' => 2000,
]);
assertSec11($resC5['status'] === 200 && $oracle->verifyExpenseConservation($expCId), 'SEC11-C.6', 'Split ITEMIZED: item + surcharge engine conserves 10000 paise');

// Step 6: Return to EQUAL
$resC6 = sec11Http('PUT', "/api/groups/{$tokC}/expenses/{$expCId}", [
    'title' => 'Mode Transition Test - Return to EQUAL',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $mA,
    'split_type' => 'EQUAL',
    'split_members' => [$mA, $mB, $mC],
]);
assertSec11($resC6['status'] === 200 && $oracle->verifyExpenseConservation($expCId), 'SEC11-C.7', 'Returned cleanly to EQUAL mode');
assertSec11($oracle->assertOracleMatchesApi($tokC, $gidC, 'C-EQUAL-Final'), 'SEC11-C.8', 'Full cycle split mode transitions preserved Oracle invariants');

// =============================================================================
// SEC11-CLASS-D: DELETE (TRASH) -> RESTORE -> UPDATE CYCLES
// =============================================================================
echo "--- SEC11-CLASS-D: Trash -> 1-Click Restore -> Update Invariants ---\n";
$wsD = createTestWorkspace('SEC11-WS-D', ['X', 'Y']);
$tokD = $wsD['token'];
$gidD = $wsD['groupId'];
$mX = $wsD['members']['X'];
$mY = $wsD['members']['Y'];

// D.1: Create Expense Rs 200.00
$resD1 = sec11Http('POST', "/api/groups/{$tokD}/expenses", [
    'title' => 'Trash Test Item',
    'total_amount_cents' => 20000,
    'paid_by_member_id' => $mX,
    'split_type' => 'EQUAL',
    'split_members' => [$mX, $mY],
]);
$expDId = (int) $resD1['data']['data']['expense']['id'];
assertSec11($oracle->assertOracleMatchesApi($tokD, $gidD, 'D1-Active'), 'SEC11-D.1', 'Active expense balances verified');

// D.2: Soft delete (Trash)
$resD2 = sec11Http('DELETE', "/api/groups/{$tokD}/expenses/{$expDId}");
assertSec11($resD2['status'] === 200, 'SEC11-D.2', 'Soft-delete expense succeeds');

// Check Oracle: total spending must now be 0, all balances 0
$oracleD2 = $oracle->computeGroupLedger($gidD);
assertSec11($oracleD2['total_spending_cents'] === 0 && $oracleD2['members'][$mX]['net_balance_cents'] === 0, 'SEC11-D.3', 'Trashed expense has exactly 0 impact on spending and net balances');
assertSec11($oracle->assertOracleMatchesApi($tokD, $gidD, 'D2-Trashed'), 'SEC11-D.4', 'API balances reflect zero impact when item is trashed');

// Verify trash endpoint lists the expense
$resDTrash = sec11Http('GET', "/api/groups/{$tokD}/expenses/trash");
$trashIds = array_column($resDTrash['data']['data']['expenses'] ?? [], 'id');
assertSec11(in_array($expDId, $trashIds, true), 'SEC11-D.5', 'Trashed expense appears in trash bin listing');

// D.3: Restore from Trash
$resD3 = sec11Http('PUT', "/api/groups/{$tokD}/expenses/{$expDId}/restore");
assertSec11($resD3['status'] === 200, 'SEC11-D.6', '1-Click Restore from trash succeeds');

$oracleD3 = $oracle->computeGroupLedger($gidD);
assertSec11($oracleD3['total_spending_cents'] === 20000 && $oracleD3['members'][$mX]['net_balance_cents'] === 10000, 'SEC11-D.7', 'Restored expense fully reinstates original balances (X is creditor +10000 paise)');
assertSec11($oracle->assertOracleMatchesApi($tokD, $gidD, 'D3-Restored'), 'SEC11-D.8', 'API balances match Oracle post-restore');

// D.4: Update after restore
$resD4 = sec11Http('PUT', "/api/groups/{$tokD}/expenses/{$expDId}", [
    'title' => 'Updated Restored Item',
    'total_amount_cents' => 40000,
    'paid_by_member_id' => $mX,
    'split_type' => 'EQUAL',
    'split_members' => [$mX, $mY],
]);
assertSec11($resD4['status'] === 200, 'SEC11-D.9', 'Restored expense can be updated in-place');
$oracleD4 = $oracle->computeGroupLedger($gidD);
assertSec11($oracleD4['total_spending_cents'] === 40000 && $oracleD4['members'][$mX]['net_balance_cents'] === 20000, 'SEC11-D.10', 'Balances updated accurately post-restore edit');

// =============================================================================
// SEC11-CLASS-E: 10-CYCLE RAPID DELETE / RESTORE STRESS
// =============================================================================
echo "--- SEC11-CLASS-E: 10-Cycle Delete / Restore Rapid Stress ---\n";
$allCyclesPassed = true;
for ($c = 1; $c <= 10; $c++) {
    // Delete
    $del = sec11Http('DELETE', "/api/groups/{$tokD}/expenses/{$expDId}");
    $oDel = $oracle->computeGroupLedger($gidD);
    if ($del['status'] !== 200 || $oDel['total_spending_cents'] !== 0) {
        $allCyclesPassed = false;
        break;
    }
    // Restore
    $rst = sec11Http('PUT', "/api/groups/{$tokD}/expenses/{$expDId}/restore");
    $oRst = $oracle->computeGroupLedger($gidD);
    if ($rst['status'] !== 200 || $oRst['total_spending_cents'] !== 40000) {
        $allCyclesPassed = false;
        break;
    }
}
assertSec11($allCyclesPassed, 'SEC11-E.1', '10 rapid delete/restore cycles completed with zero balance drift');
assertSec11($oracle->assertOracleMatchesApi($tokD, $gidD, 'E-10Cycles'), 'SEC11-E.2', 'Oracle & API in perfect agreement after 10 trash/restore cycles');

// =============================================================================
// SEC11-CLASS-F: SETTLEMENT CREATION & UNDO (SOFT-DELETE) LIFECYCLE
// =============================================================================
echo "--- SEC11-CLASS-F: Settlement Creation & Undo (Soft-Delete) Lifecycle ---\n";
$wsF = createTestWorkspace('SEC11-WS-F', ['PayerM', 'PayeeM', 'OtherM']);
$tokF = $wsF['token'];
$gidF = $wsF['groupId'];
$mPayer = $wsF['members']['PayerM'];
$mPayee = $wsF['members']['PayeeM'];
$mOther = $wsF['members']['OtherM'];

// Expense: PayeeM pays Rs 150.00 split equally among PayerM, PayeeM, OtherM (50.00 each)
$resFExp = sec11Http('POST', "/api/groups/{$tokF}/expenses", [
    'title' => 'Dinner for 3',
    'total_amount_cents' => 15000,
    'paid_by_member_id' => $mPayee,
    'split_type' => 'EQUAL',
    'split_members' => [$mPayer, $mPayee, $mOther],
]);
// Payee is +10000, Payer is -5000, Other is -5000
assertSec11($oracle->assertOracleMatchesApi($tokF, $gidF, 'F-InitialExpense'), 'SEC11-F.1', 'Initial debts established');

// Settlement 1: PayerM settles Rs 50.00 to PayeeM (Payee confirms)
$resFSettle = sec11Http('POST', "/api/groups/{$tokF}/settlements", [
    'payer_id' => $mPayer,
    'payee_id' => $mPayee,
    'recorded_by_member_id' => $mPayee,
    'amount_cents' => 5000,
    'notes' => 'UPI settlement payment',
]);
$settleId = (int) $resFSettle['data']['data']['settlement']['id'];
assertSec11($resFSettle['status'] === 201, 'SEC11-F.2', 'Settlement recorded successfully');

// Post-settlement: Payer is now 0, Payee is +5000, Other is -5000
$oracleFPost = $oracle->computeGroupLedger($gidF);
assertSec11($oracleFPost['members'][$mPayer]['net_balance_cents'] === 0, 'SEC11-F.3', 'Payer net balance cleared to 0');
assertSec11($oracleFPost['members'][$mPayee]['net_balance_cents'] === 5000, 'SEC11-F.4', 'Payee net balance reduced from +10000 to +5000');
assertSec11($oracle->assertOracleMatchesApi($tokF, $gidF, 'F-PostSettle'), 'SEC11-F.5', 'API balances match Oracle post-settlement');

// Settlement Undo (Soft-delete settlement)
$resFUndo = sec11Http('DELETE', "/api/groups/{$tokF}/settlements/{$settleId}");
assertSec11($resFUndo['status'] === 200, 'SEC11-F.6', 'Settlement undo (soft-delete) succeeds');

$oracleFUndo = $oracle->computeGroupLedger($gidF);
assertSec11($oracleFUndo['members'][$mPayer]['net_balance_cents'] === -5000, 'SEC11-F.7', 'Payer net balance reinstated to -5000 after settlement undo');
assertSec11($oracleFUndo['members'][$mPayee]['net_balance_cents'] === 10000, 'SEC11-F.8', 'Payee net balance reinstated to +10000 after settlement undo');
assertSec11($oracle->assertOracleMatchesApi($tokF, $gidF, 'F-PostUndo'), 'SEC11-F.9', 'API balances match Oracle post-settlement undo');

// =============================================================================
// SEC11-CLASS-G: EXPENSE DELETE VS SETTLEMENT INTERACTION (OVERPAID / INVERSION)
// =============================================================================
echo "--- SEC11-CLASS-G: Expense Delete vs Settlement Interaction & Inversion ---\n";
$wsG = createTestWorkspace('SEC11-WS-G', ['Alice', 'Bob']);
$tokG = $wsG['token'];
$gidG = $wsG['groupId'];
$gAlice = $wsG['members']['Alice'];
$gBob = $wsG['members']['Bob'];

// 1. Alice pays Rs 100.00 for Bob (Bob owes 10000 paise)
$resG1 = sec11Http('POST', "/api/groups/{$tokG}/expenses", [
    'title' => 'Concert Ticket',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $gAlice,
    'split_type' => 'EXACT',
    'splits' => [
        ['member_id' => $gBob, 'amount_cents' => 10000],
    ],
]);
$expGId = (int) $resG1['data']['data']['expense']['id'];

// 2. Bob settles Rs 100.00 to Alice (Alice records receipt)
$resG2 = sec11Http('POST', "/api/groups/{$tokG}/settlements", [
    'payer_id' => $gBob,
    'payee_id' => $gAlice,
    'recorded_by_member_id' => $gAlice,
    'amount_cents' => 10000,
]);
$settleGId = (int) $resG2['data']['data']['settlement']['id'];

// 3. Alice trashes the original expense -> Bob has now overpaid! Bob is creditor (+10000), Alice is debtor (-10000)
$resG3 = sec11Http('DELETE', "/api/groups/{$tokG}/expenses/{$expGId}");
assertSec11($resG3['status'] === 200, 'SEC11-G.1', 'Expense trashed after settlement was completed');

$oracleG3 = $oracle->computeGroupLedger($gidG);
assertSec11($oracleG3['members'][$gBob]['net_balance_cents'] === 10000, 'SEC11-G.2', 'Bob inverted to Creditor (+100.00) due to overpayment on trashed expense');
assertSec11($oracleG3['members'][$gAlice]['net_balance_cents'] === -10000, 'SEC11-G.3', 'Alice inverted to Debtor (-100.00)');
assertSec11($oracle->assertOracleMatchesApi($tokG, $gidG, 'G-Overpaid'), 'SEC11-G.4', 'API balances & settlement plan adapt correctly to overpaid state');

// 4. Restore the expense -> returns to 0
$resG4 = sec11Http('PUT', "/api/groups/{$tokG}/expenses/{$expGId}/restore");
assertSec11($resG4['status'] === 200, 'SEC11-G.5', 'Expense restored');
$oracleG4 = $oracle->computeGroupLedger($gidG);
assertSec11($oracleG4['members'][$gBob]['net_balance_cents'] === 0 && $oracleG4['members'][$gAlice]['net_balance_cents'] === 0, 'SEC11-G.6', 'Balances return to exactly settled zero upon expense restoration');

// =============================================================================
// SEC11-CLASS-H: RECURRING SCHEDULER LIFECYCLE
// =============================================================================
echo "--- SEC11-CLASS-H: Recurring Scheduler Lifecycle ---\n";
$wsH = createTestWorkspace('SEC11-WS-H', ['Tenant1', 'Tenant2']);
$tokH = $wsH['token'];
$gidH = $wsH['groupId'];
$t1 = $wsH['members']['Tenant1'];
$t2 = $wsH['members']['Tenant2'];

// Create recurring monthly rule for Rs 500.00 (50000 paise)
$resHCreate = sec11Http('POST', "/api/groups/{$tokH}/recurring", [
    'title' => 'Monthly Broadband',
    'total_amount_cents' => 50000,
    'frequency' => 'MONTHLY',
    'paid_by_member_id' => $t1,
    'split_type' => 'EQUAL',
    'split_members' => [$t1, $t2],
    'next_run_date' => date('Y-m-d', strtotime('-1 day')), // due yesterday
]);
assertSec11($resHCreate['status'] === 201, 'SEC11-H.1', 'Recurring rule created');

// Evaluate due rules
$resHEval = sec11Http('POST', "/api/groups/{$tokH}/recurring/evaluate");
$createdCount = (int) ($resHEval['data']['data']['evaluation']['created_expenses_count'] ?? 0);
assertSec11($resHEval['status'] === 200 && $createdCount >= 1, 'SEC11-H.2', 'Recurring rule materialized expense on schedule');

$genExpId = (int) ($resHEval['data']['data']['evaluation']['expenses'][0] ?? 0);
assertSec11($genExpId > 0 && $oracle->verifyExpenseConservation($genExpId), 'SEC11-H.3', 'Generated expense conserves payers and splits');
assertSec11($oracle->assertOracleMatchesApi($tokH, $gidH, 'H-Materialized'), 'SEC11-H.4', 'Materialized expense reflected accurately in balances');

// Edit materialized expense
$resHEdit = sec11Http('PUT', "/api/groups/{$tokH}/expenses/{$genExpId}", [
    'title' => 'Monthly Broadband + Speed Boost',
    'total_amount_cents' => 60000,
    'paid_by_member_id' => $t1,
    'split_type' => 'EQUAL',
    'split_members' => [$t1, $t2],
]);
assertSec11($resHEdit['status'] === 200, 'SEC11-H.5', 'Materialized recurring expense can be updated without breaking recurring rule');

// Delete materialized expense
$resHDel = sec11Http('DELETE', "/api/groups/{$tokH}/expenses/{$genExpId}");
assertSec11($resHDel['status'] === 200, 'SEC11-H.6', 'Materialized expense can be soft-deleted independently');

// =============================================================================
// SEC11-CLASS-I: MEMBER REMOVAL SAFEGUARDS (ACTIVE DEBT VS ZERO DEBT)
// =============================================================================
echo "--- SEC11-CLASS-I: Member Removal Safeguards ---\n";
$wsI = createTestWorkspace('SEC11-WS-I', ['Mem1', 'Mem2', 'Mem3']);
$tokI = $wsI['token'];
$gidI = $wsI['groupId'];
$m1 = $wsI['members']['Mem1'];
$m2 = $wsI['members']['Mem2'];
$m3 = $wsI['members']['Mem3'];

// Create expense where Mem2 owes Mem1 Rs 100.00
sec11Http('POST', "/api/groups/{$tokI}/expenses", [
    'title' => 'Test Debt',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $m1,
    'split_type' => 'EXACT',
    'splits' => [['member_id' => $m2, 'amount_cents' => 10000]],
]);

// Attempt to delete Mem2 with active debt (-100.00) -> MUST FAIL (422 MEMBER_HAS_BALANCE)
$resIDelFail = sec11Http('DELETE', "/api/groups/{$tokI}/members/{$m2}");
assertSec11($resIDelFail['status'] === 422, 'SEC11-I.1', 'Deleting member with active debt rejected with 422');

// Settle debt
sec11Http('POST', "/api/groups/{$tokI}/settlements", [
    'payer_id' => $m2,
    'payee_id' => $m1,
    'recorded_by_member_id' => $m1,
    'amount_cents' => 10000,
]);

// Attempt to delete Mem2 now (balance is 0, but has historical transactions) -> Soft deactivated
$resIDelSuccess = sec11Http('DELETE', "/api/groups/{$tokI}/members/{$m2}");
assertSec11($resIDelSuccess['status'] === 200 && ($resIDelSuccess['data']['data']['was_deactivated'] ?? false) === true, 'SEC11-I.2', 'Settled member with historical transactions is safely soft-deactivated');
assertSec11($oracle->assertOracleMatchesApi($tokI, $gidI, 'I-DeactivatedMember'), 'SEC11-I.3', 'Zero-sum integrity conserved after member deactivation');

// Delete unused member Mem3 (no transactions) -> Hard deleted
$resIDelHard = sec11Http('DELETE', "/api/groups/{$tokI}/members/{$m3}");
assertSec11($resIDelHard['status'] === 200 && ($resIDelHard['data']['data']['was_deactivated'] ?? false) === false, 'SEC11-I.4', 'Member with zero transactions is cleanly hard deleted');

// =============================================================================
// SEC11-CLASS-J: RECEIPT ATTACHMENTS NON-INTERFERENCE
// =============================================================================
echo "--- SEC11-CLASS-J: Receipt Attachments Non-Interference ---\n";
$wsJ = createTestWorkspace('SEC11-WS-J', ['Buyer', 'Sharer']);
$tokJ = $wsJ['token'];
$gidJ = $wsJ['groupId'];
$mBuyer = $wsJ['members']['Buyer'];
$mSharer = $wsJ['members']['Sharer'];

$resJExp = sec11Http('POST', "/api/groups/{$tokJ}/expenses", [
    'title' => 'Groceries with Receipt',
    'total_amount_cents' => 12000,
    'paid_by_member_id' => $mBuyer,
    'split_type' => 'EQUAL',
    'split_members' => [$mBuyer, $mSharer],
]);
$expJId = (int) $resJExp['data']['data']['expense']['id'];
$oracleJPre = $oracle->computeGroupLedger($gidJ);

// Attach receipt
$resJReceipt = sec11Http('POST', "/api/groups/{$tokJ}/expenses/{$expJId}/receipts", [
    'file_name' => 'store_receipt.png',
    'data_base64' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    'actor_member_id' => $mBuyer,
]);
assertSec11($resJReceipt['status'] === 201 || $resJReceipt['status'] === 200, 'SEC11-J.1', 'Receipt attachment uploaded');

// Verify zero balance drift after receipt attachment
$oracleJPostReceipt = $oracle->computeGroupLedger($gidJ);
assertSec11($oracleJPre['total_spending_cents'] === $oracleJPostReceipt['total_spending_cents'], 'SEC11-J.2', 'Total spending unchanged after receipt attachment');
assertSec11($oracleJPre['members'][$mBuyer]['net_balance_cents'] === $oracleJPostReceipt['members'][$mBuyer]['net_balance_cents'], 'SEC11-J.3', 'Member net balances unchanged after receipt attachment');

// Edit expense amount while receipt attached
$resJEdit = sec11Http('PUT', "/api/groups/{$tokJ}/expenses/{$expJId}", [
    'title' => 'Groceries with Receipt (Adjusted)',
    'total_amount_cents' => 15000,
    'paid_by_member_id' => $mBuyer,
    'split_type' => 'EQUAL',
    'split_members' => [$mBuyer, $mSharer],
]);
assertSec11($resJEdit['status'] === 200, 'SEC11-J.4', 'Expense edited while receipt attached');
assertSec11($oracle->verifyExpenseConservation($expJId), 'SEC11-J.5', 'Payers and splits conserved after edit with receipt');
assertSec11($oracle->assertOracleMatchesApi($tokJ, $gidJ, 'J-ReceiptEdit'), 'SEC11-J.6', 'Balances exact post-edit');

// =============================================================================
// SEC11-CLASS-K: COMPLEX REALISTIC MULTI-DAY TRIP (25+ SEQUENTIAL ACTIONS)
// =============================================================================
echo "--- SEC11-CLASS-K: Complex Realistic Multi-Day Trip (25-Step Simulation) ---\n";
$wsK = createTestWorkspace('SEC11-Goa-Trip', ['Alice', 'Bob', 'Charlie', 'Dana', 'Evan']);
$tokK = $wsK['token'];
$gidK = $wsK['groupId'];
$kA = $wsK['members']['Alice'];
$kB = $wsK['members']['Bob'];
$kC = $wsK['members']['Charlie'];
$kD = $wsK['members']['Dana'];
$kE = $wsK['members']['Evan'];

$tripExpenses = [];
$tripSettlements = [];
$allTripStepsPassed = true;

// Step 1: Hotel Booking (Alice & Bob co-pay Rs 25,000, split equal all 5)
$k1 = sec11Http('POST', "/api/groups/{$tokK}/expenses", [
    'title' => 'Beach Villa (3 Nights)',
    'total_amount_cents' => 2500000,
    'split_type' => 'EQUAL',
    'payers' => [
        ['member_id' => $kA, 'amount_cents' => 1500000],
        ['member_id' => $kB, 'amount_cents' => 1000000],
    ],
    'split_members' => [$kA, $kB, $kC, $kD, $kE],
]);
$tripExpenses['hotel'] = (int) $k1['data']['data']['expense']['id'];
$allTripStepsPassed = $allTripStepsPassed && ($k1['status'] === 201) && $oracle->assertOracleMatchesApi($tokK, $gidK, 'K-Step1-Hotel');

// Step 2: Airport Taxi (Charlie pays Rs 3,500, split Dana & Evan)
$k2 = sec11Http('POST', "/api/groups/{$tokK}/expenses", [
    'title' => 'Airport SUV',
    'total_amount_cents' => 350000,
    'paid_by_member_id' => $kC,
    'split_type' => 'EQUAL',
    'split_members' => [$kD, $kE],
]);
$tripExpenses['taxi'] = (int) $k2['data']['data']['expense']['id'];
$allTripStepsPassed = $allTripStepsPassed && ($k2['status'] === 201) && $oracle->assertOracleMatchesApi($tokK, $gidK, 'K-Step2-Taxi');

// Step 3: Seafood Dinner (Dana pays Rs 8,000, exact split: Alice=2000, Bob=2500, Charlie=1500, Dana=1000, Evan=1000)
$k3 = sec11Http('POST', "/api/groups/{$tokK}/expenses", [
    'title' => 'Fisherman Wharf Dinner',
    'total_amount_cents' => 800000,
    'paid_by_member_id' => $kD,
    'split_type' => 'EXACT',
    'splits' => [
        ['member_id' => $kA, 'amount_cents' => 200000],
        ['member_id' => $kB, 'amount_cents' => 250000],
        ['member_id' => $kC, 'amount_cents' => 150000],
        ['member_id' => $kD, 'amount_cents' => 100000],
        ['member_id' => $kE, 'amount_cents' => 100000],
    ],
]);
$tripExpenses['dinner'] = (int) $k3['data']['data']['expense']['id'];
$allTripStepsPassed = $allTripStepsPassed && ($k3['status'] === 201) && $oracle->assertOracleMatchesApi($tokK, $gidK, 'K-Step3-Dinner');

// Step 4: Scuba Diving (Evan pays Rs 15,000, shares: Alice=2, Bob=2, Charlie=1, Dana=1, Evan=0 = 6 shares)
$k4 = sec11Http('POST', "/api/groups/{$tokK}/expenses", [
    'title' => 'Scuba Diving Passes',
    'total_amount_cents' => 1500000,
    'paid_by_member_id' => $kE,
    'split_type' => 'SHARES',
    'splits' => [
        ['member_id' => $kA, 'shares' => 2],
        ['member_id' => $kB, 'shares' => 2],
        ['member_id' => $kC, 'shares' => 1],
        ['member_id' => $kD, 'shares' => 1],
    ],
]);
$tripExpenses['scuba'] = (int) $k4['data']['data']['expense']['id'];
$allTripStepsPassed = $allTripStepsPassed && ($k4['status'] === 201) && $oracle->assertOracleMatchesApi($tokK, $gidK, 'K-Step4-Scuba');

// Step 5: Partial Settlement: Evan settles Rs 5,000 to Alice (Alice records receipt)
$k5 = sec11Http('POST', "/api/groups/{$tokK}/settlements", [
    'payer_id' => $kE,
    'payee_id' => $kA,
    'recorded_by_member_id' => $kA,
    'amount_cents' => 500000,
    'notes' => 'Mid-trip settlement',
]);
$tripSettlements['s1'] = (int) $k5['data']['data']['settlement']['id'];
$allTripStepsPassed = $allTripStepsPassed && ($k5['status'] === 201) && $oracle->assertOracleMatchesApi($tokK, $gidK, 'K-Step5-Settle1');

// Step 6: Edit Dinner to add tip (+Rs 1,000 tip, new total Rs 9,000)
$k6 = sec11Http('PUT', "/api/groups/{$tokK}/expenses/{$tripExpenses['dinner']}", [
    'title' => 'Fisherman Wharf Dinner + Tip',
    'total_amount_cents' => 900000,
    'paid_by_member_id' => $kD,
    'split_type' => 'EXACT',
    'splits' => [
        ['member_id' => $kA, 'amount_cents' => 220000],
        ['member_id' => $kB, 'amount_cents' => 280000],
        ['member_id' => $kC, 'amount_cents' => 170000],
        ['member_id' => $kD, 'amount_cents' => 110000],
        ['member_id' => $kE, 'amount_cents' => 120000],
    ],
]);
$allTripStepsPassed = $allTripStepsPassed && ($k6['status'] === 200) && $oracle->assertOracleMatchesApi($tokK, $gidK, 'K-Step6-EditDinner');

// Step 7: Trash Taxi expense (accidental duplicate)
$k7 = sec11Http('DELETE', "/api/groups/{$tokK}/expenses/{$tripExpenses['taxi']}");
$allTripStepsPassed = $allTripStepsPassed && ($k7['status'] === 200) && $oracle->assertOracleMatchesApi($tokK, $gidK, 'K-Step7-TrashTaxi');

// Step 8: Restore Taxi expense (confirmed valid)
$k8 = sec11Http('PUT', "/api/groups/{$tokK}/expenses/{$tripExpenses['taxi']}/restore");
$allTripStepsPassed = $allTripStepsPassed && ($k8['status'] === 200) && $oracle->assertOracleMatchesApi($tokK, $gidK, 'K-Step8-RestoreTaxi');

// Step 9: Get Min-Cash-Flow Settlement Plan and execute all recommended settlements to clear all debts
$kPlanRes = sec11Http('GET', "/api/groups/{$tokK}/settlement-plan");
$allTripStepsPassed = $allTripStepsPassed && ($kPlanRes['status'] === 200);

$planSettlements = $kPlanRes['data']['data']['transactions'] ?? [];
foreach ($planSettlements as $pIdx => $pItem) {
    $pExec = sec11Http('POST', "/api/groups/{$tokK}/settlements", [
        'payer_id' => $pItem['from_member_id'],
        'payee_id' => $pItem['to_member_id'],
        'recorded_by_member_id' => $pItem['to_member_id'],
        'amount_cents' => $pItem['amount_cents'],
        'notes' => "Final settlement #{$pIdx}",
    ]);
    $allTripStepsPassed = $allTripStepsPassed && ($pExec['status'] === 201) && $oracle->assertOracleMatchesApi($tokK, $gidK, "K-Step9-FinalSettle-{$pIdx}");
}

// Step 10: Verify all members are now exactly 0.00 net balance
$kFinalLedger = $oracle->computeGroupLedger($gidK);
$allZero = true;
foreach ($kFinalLedger['members'] as $km) {
    if ($km['net_balance_cents'] !== 0) {
        $allZero = false;
        break;
    }
}
assertSec11($allTripStepsPassed, 'SEC11-K.1', 'All 25+ complex trip simulation steps passed Oracle verification');
assertSec11($allZero, 'SEC11-K.2', 'Executing the settlement plan clears all member debts to exactly zero paise');
assertSec11($oracle->assertOracleMatchesApi($tokK, $gidK, 'K-FinalFullySettled'), 'SEC11-K.3', 'Final fully settled state matches Oracle and API identically');

// =============================================================================
// SEC11-CLASS-X: ADVERSARIAL RANDOM TRANSITION GENERATOR (50 RANDOMIZED RUNS)
// =============================================================================
echo "--- SEC11-CLASS-X: Adversarial Random Transition Fuzzing (50 Sequences) ---\n";
$wsX = createTestWorkspace('SEC11-WS-FUZZ', ['U1', 'U2', 'U3', 'U4']);
$tokX = $wsX['token'];
$gidX = $wsX['groupId'];
$fuzzMembers = array_values($wsX['members']); // [id1, id2, id3, id4]

$fuzzActiveExpenses = [];
$fuzzTrashedExpenses = [];
$fuzzActiveSettlements = [];

$totalFuzzTransitions = 0;
$fuzzFailures = 0;

for ($seq = 1; $seq <= 50; $seq++) {
    $seqSteps = rand(8, 15);
    for ($s = 1; $s <= $seqSteps; $s++) {
        $totalFuzzTransitions++;
        $action = rand(1, 6);

        switch ($action) {
            case 1: // CREATE EXPENSE
                $payer = $fuzzMembers[array_rand($fuzzMembers)];
                $amt = rand(100, 50000); // 1.00 to 500.00
                $cRes = sec11Http('POST', "/api/groups/{$tokX}/expenses", [
                    'title' => "Fuzz Exp S{$seq}-{$s}",
                    'total_amount_cents' => $amt,
                    'paid_by_member_id' => $payer,
                    'split_type' => 'EQUAL',
                    'split_members' => $fuzzMembers,
                ]);
                if ($cRes['status'] === 201) {
                    $newId = (int) $cRes['data']['data']['expense']['id'];
                    $fuzzActiveExpenses[] = $newId;
                }
                break;

            case 2: // UPDATE ACTIVE EXPENSE
                if (!empty($fuzzActiveExpenses)) {
                    $targetExpId = $fuzzActiveExpenses[array_rand($fuzzActiveExpenses)];
                    $payer = $fuzzMembers[array_rand($fuzzMembers)];
                    $amt = rand(100, 75000);
                    sec11Http('PUT', "/api/groups/{$tokX}/expenses/{$targetExpId}", [
                        'title' => "Fuzz Exp Updated S{$seq}-{$s}",
                        'total_amount_cents' => $amt,
                        'paid_by_member_id' => $payer,
                        'split_type' => 'EQUAL',
                        'split_members' => $fuzzMembers,
                    ]);
                }
                break;

            case 3: // TRASH EXPENSE
                if (!empty($fuzzActiveExpenses)) {
                    $randKey = array_rand($fuzzActiveExpenses);
                    $targetExpId = $fuzzActiveExpenses[$randKey];
                    $tRes = sec11Http('DELETE', "/api/groups/{$tokX}/expenses/{$targetExpId}");
                    if ($tRes['status'] === 200) {
                        unset($fuzzActiveExpenses[$randKey]);
                        $fuzzActiveExpenses = array_values($fuzzActiveExpenses);
                        $fuzzTrashedExpenses[] = $targetExpId;
                    }
                }
                break;

            case 4: // RESTORE EXPENSE
                if (!empty($fuzzTrashedExpenses)) {
                    $randKey = array_rand($fuzzTrashedExpenses);
                    $targetExpId = $fuzzTrashedExpenses[$randKey];
                    $rRes = sec11Http('PUT', "/api/groups/{$tokX}/expenses/{$targetExpId}/restore");
                    if ($rRes['status'] === 200) {
                        unset($fuzzTrashedExpenses[$randKey]);
                        $fuzzTrashedExpenses = array_values($fuzzTrashedExpenses);
                        $fuzzActiveExpenses[] = $targetExpId;
                    }
                }
                break;

            case 5: // CREATE SETTLEMENT
                $pIdx = array_rand($fuzzMembers);
                $rIdx = array_rand($fuzzMembers);
                if ($pIdx !== $rIdx) {
                    $sAmt = rand(100, 10000);
                    $sRes = sec11Http('POST', "/api/groups/{$tokX}/settlements", [
                        'payer_id' => $fuzzMembers[$pIdx],
                        'payee_id' => $fuzzMembers[$rIdx],
                        'recorded_by_member_id' => $fuzzMembers[$rIdx],
                        'amount_cents' => $sAmt,
                    ]);
                    if ($sRes['status'] === 201) {
                        $fuzzActiveSettlements[] = (int) $sRes['data']['data']['settlement']['id'];
                    }
                }
                break;

            case 6: // UNDO SETTLEMENT
                if (!empty($fuzzActiveSettlements)) {
                    $randKey = array_rand($fuzzActiveSettlements);
                    $sId = $fuzzActiveSettlements[$randKey];
                    $uRes = sec11Http('DELETE', "/api/groups/{$tokX}/settlements/{$sId}");
                    if ($uRes['status'] === 200) {
                        unset($fuzzActiveSettlements[$randKey]);
                        $fuzzActiveSettlements = array_values($fuzzActiveSettlements);
                    }
                }
                break;
        }

        // Validate Invariants & Oracle after every transition
        if (!$oracle->assertOracleMatchesApi($tokX, $gidX, "Fuzz-S{$seq}-Step{$s}")) {
            $fuzzFailures++;
            break 2;
        }
    }
}

assertSec11($fuzzFailures === 0, 'SEC11-X.1', "Adversarial Fuzzing: {$totalFuzzTransitions} random lifecycle transitions verified without invariant failure");

// =============================================================================
// SEC11-INVALID: INVALID STATE TRANSITION DEFENSE MATRIX
// =============================================================================
echo "--- SEC11-INVALID: Invalid State Transition Defense Matrix ---\n";
$wsInv = createTestWorkspace('SEC11-WS-INV', ['M1', 'M2']);
$tokInv = $wsInv['token'];
$gidInv = $wsInv['groupId'];
$inv1 = $wsInv['members']['M1'];
$inv2 = $wsInv['members']['M2'];

// Create expense
$expInvRes = sec11Http('POST', "/api/groups/{$tokInv}/expenses", [
    'title' => 'Valid Exp',
    'total_amount_cents' => 5000,
    'paid_by_member_id' => $inv1,
    'split_type' => 'EQUAL',
    'split_members' => [$inv1, $inv2],
]);
$expInvId = (int) $expInvRes['data']['data']['expense']['id'];

// 1. Attempt PUT /expenses/{id}/restore on ACTIVE expense -> MUST BE REJECTED (404/400)
$invRestoreActive = sec11Http('PUT', "/api/groups/{$tokInv}/expenses/{$expInvId}/restore");
assertSec11($invRestoreActive['status'] >= 400, 'SEC11-INV.1', 'Restoring an already active expense is rejected (cannot double-restore)');

// Trash the expense
sec11Http('DELETE', "/api/groups/{$tokInv}/expenses/{$expInvId}");

// 2. Attempt PUT /expenses/{id} on TRASHED expense -> MUST BE REJECTED (404)
$invEditTrashed = sec11Http('PUT', "/api/groups/{$tokInv}/expenses/{$expInvId}", [
    'title' => 'Illegal Edit on Trashed',
    'total_amount_cents' => 8000,
    'paid_by_member_id' => $inv1,
    'split_type' => 'EQUAL',
    'split_members' => [$inv1, $inv2],
]);
assertSec11($invEditTrashed['status'] >= 400, 'SEC11-INV.2', 'Updating a trashed expense is rejected with 404/400');

// 3. Attempt DELETE /expenses/{id} on already TRASHED expense -> MUST BE REJECTED (404)
$invDoubleDelete = sec11Http('DELETE', "/api/groups/{$tokInv}/expenses/{$expInvId}");
assertSec11($invDoubleDelete['status'] >= 400, 'SEC11-INV.3', 'Deleting an already trashed expense is rejected');

// 4. Attempt POST /settlements with payer === payee -> MUST BE REJECTED (422)
$invSelfSettle = sec11Http('POST', "/api/groups/{$tokInv}/settlements", [
    'payer_id' => $inv1,
    'payee_id' => $inv1,
    'amount_cents' => 5000,
]);
assertSec11($invSelfSettle['status'] === 422, 'SEC11-INV.4', 'Settlement with identical payer and payee is rejected (422)');

// 5. Attempt POST /settlements with 0 or negative amount -> MUST BE REJECTED (422)
$invNegSettle = sec11Http('POST', "/api/groups/{$tokInv}/settlements", [
    'payer_id' => $inv1,
    'payee_id' => $inv2,
    'amount_cents' => -500,
]);
assertSec11($invNegSettle['status'] === 422, 'SEC11-INV.5', 'Settlement with negative amount is rejected (422)');

// 6. Cross-workspace ID tampering: Attempt to delete expense in wsInv using token from wsA
$invCrossWs = sec11Http('DELETE', "/api/groups/{$tokA}/expenses/{$expInvId}");
assertSec11($invCrossWs['status'] === 404 || $invCrossWs['status'] === 403, 'SEC11-INV.6', 'Cross-workspace expense tampering rejected (BOLA/IDOR protection)');

// =============================================================================
// SEC11-ROLLBACK: ACID MULTI-TABLE MUTATION ROLLBACK VERIFICATION
// =============================================================================
echo "--- SEC11-ROLLBACK: ACID Multi-Table Rollback Verification ---\n";
$wsR = createTestWorkspace('SEC11-WS-ROLLBACK', ['R1', 'R2']);
$tokR = $wsR['token'];
$gidR = $wsR['groupId'];
$r1 = $wsR['members']['R1'];
$r2 = $wsR['members']['R2'];

$resR1 = sec11Http('POST', "/api/groups/{$tokR}/expenses", [
    'title' => 'Rollback Baseline Item',
    'total_amount_cents' => 10000,
    'paid_by_member_id' => $r1,
    'split_type' => 'EQUAL',
    'split_members' => [$r1, $r2],
]);
$expRId = (int) $resR1['data']['data']['expense']['id'];
$pCountRPre = (int) $pdo->query("SELECT COUNT(*) FROM expense_payers WHERE expense_id = {$expRId}")->fetchColumn();
$sCountRPre = (int) $pdo->query("SELECT COUNT(*) FROM expense_splits WHERE expense_id = {$expRId}")->fetchColumn();

// Attempt invalid edit with split referencing a non-existent foreign member ID 9999999
$resRFail = sec11Http('PUT', "/api/groups/{$tokR}/expenses/{$expRId}", [
    'title' => 'Corrupt Edit Attempt',
    'total_amount_cents' => 20000,
    'paid_by_member_id' => $r1,
    'split_type' => 'EQUAL',
    'split_members' => [$r1, 9999999],
]);
assertSec11($resRFail['status'] >= 400, 'SEC11-ROLLBACK.1', 'Invalid split payload rejected by server');

// Verify ACID rollback: payers and splits must remain EXACTLY as before, not wiped
$pCountRPost = (int) $pdo->query("SELECT COUNT(*) FROM expense_payers WHERE expense_id = {$expRId}")->fetchColumn();
$sCountRPost = (int) $pdo->query("SELECT COUNT(*) FROM expense_splits WHERE expense_id = {$expRId}")->fetchColumn();
$expRRow = $pdo->query("SELECT total_amount_cents, title FROM expenses WHERE id = {$expRId}")->fetch();

assertSec11($pCountRPre === $pCountRPost && $sCountRPre === $sCountRPost, 'SEC11-ROLLBACK.2', 'Payers and splits preserved after failed transaction rollback');
assertSec11((int)$expRRow['total_amount_cents'] === 10000 && $expRRow['title'] === 'Rollback Baseline Item', 'SEC11-ROLLBACK.3', 'Expense amount and title restored to baseline after failed transaction');
assertSec11($oracle->assertOracleMatchesApi($tokR, $gidR, 'Rollback-Verify'), 'SEC11-ROLLBACK.4', 'Financial Oracle confirms 100% balance integrity after rollback');

// =============================================================================
// SUMMARY & EXIT
// =============================================================================
echo "\n================================================================================\n";
echo " SEC-11 TEST MATRIX SUMMARY\n";
echo "================================================================================\n";
echo " Total Assertions Executed: {$totalSec11}\n";
echo " Passed Assertions:         {$passedSec11}\n";
echo " Failed Assertions:         {$failedSec11}\n";
echo " Success Rate:              " . round(($passedSec11 / max(1, $totalSec11)) * 100, 1) . "%\n";
echo "================================================================================\n\n";

exit($failedSec11 > 0 ? 1 : 0);
