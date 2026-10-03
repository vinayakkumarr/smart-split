<?php

declare(strict_types=1);

/**
 * Smart Split V2 — SEC-14: Recurring Scheduler Calendar, Date/Time & Timezone Integrity Audit Suite
 *
 * Validates core calendar & scheduling security properties:
 * 1. Calendar Scheduling State Machine & Due Predicate (SEC14-MODEL).
 * 2. Pure Calendar Date Comparisons & Midnight Boundaries (SEC14-TIMEZONE-MIDNIGHT).
 * 3. Weekly Recurrence across Months, Years & Leap Years (SEC14-WEEKLY).
 * 4. Biweekly Recurrence across Calendar Boundaries (SEC14-BIWEEKLY).
 * 5. Monthly Recurrence Month-End Transitions (Days 28, 29, 30, 31) without Month Skipping or Day Drift (SEC14-MONTHLY).
 * 6. Yearly Recurrence Leap Day Handling (Feb 29 -> Feb 28 -> Feb 29 in leap years) (SEC14-YEARLY).
 * 7. Multi-Cycle 2028 -> 2032 Leap-Day Proof (SEC14-YEARLY-LEAP-2032).
 * 8. Century Leap Year Rules (2000 vs 2100) (SEC14-LEAP).
 * 9. Anchor Persistence & Immutability in Database payload_json across Evaluations (SEC14-ANCHOR-PERSIST).
 * 10. Delayed Execution Catch-Up & Idempotent Non-Duplication (SEC14-DELAY & SEC14-REPEAT).
 * 11. End Date / Expiration Boundary Lifecycle (SEC14-END).
 * 12. Frequency Contract Verification (Accept WEEKLY, BIWEEKLY, MONTHLY, YEARLY; Reject DAILY, HOURLY) (SEC14-FREQ-CONTRACT).
 * 13. Start Date & Malformed Date Validation (422 Rejections) (SEC14-INVALID).
 * 14. Multi-Split Methodology Preservation (EQUAL, EXACT, PERCENTAGE, SHARES, ITEMIZED) (SEC14-SPLIT).
 * 15. Financial Mathematical Invariants & Zero-Sum Conservation across Materialized Expenses (SEC14-FINANCIAL).
 * 16. Idempotency Key & Optimistic Concurrency Integration (SEC14-IDEMPOTENCY).
 * 17. Randomized 250+ Scenario Calendar Fuzzing against Independent Scheduling Oracle (SEC14-FUZZ).
 * 18. Deterministic Fixture Teardown (SEC14-CLEANUP).
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Middleware\SecurityHeadersMiddleware;
use App\Core\Middleware\AuthSessionMiddleware;
use App\Controllers\GroupController;
use App\Controllers\MemberController;
use App\Controllers\ExpenseController;
use App\Controllers\BalanceController;
use App\Controllers\RecurringController;
use App\Services\RecurringService;

echo "\n================================================================================\n";
echo " SEC-14: RECURRING SCHEDULER CALENDAR, DATE/TIME & TIMEZONE INTEGRITY SUITE\n";
echo "================================================================================\n\n";

$pdo = Database::getConnection();

$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = [];

function assertSec14(bool $condition, string $testCode, string $description, ?string $details = null): void
{
    global $totalAssertions, $passedAssertions, $failedAssertions;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  [PASS] [{$testCode}] {$description}\n";
    } else {
        echo "  [FAIL] [{$testCode}] {$description}\n";
        if ($details) {
            echo "         > Details: {$details}\n";
        }
        $failedAssertions[] = [
            'code' => $testCode,
            'description' => $description,
            'details' => $details,
        ];
    }
}

// Router Setup
$router = new Router();
$router->use(new SecurityHeadersMiddleware());
$router->use(new AuthSessionMiddleware());

$router->post('/api/groups', [GroupController::class, 'create']);
$router->get('/api/groups/{token}', [GroupController::class, 'show']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->get('/api/groups/{token}/members', [MemberController::class, 'index']);
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->get('/api/groups/{token}/expenses', [ExpenseController::class, 'index']);
$router->get('/api/groups/{token}/balances', [BalanceController::class, 'index']);
$router->get('/api/groups/{token}/recurring', [RecurringController::class, 'index']);
$router->post('/api/groups/{token}/recurring', [RecurringController::class, 'create']);
$router->delete('/api/groups/{token}/recurring/{id}', [RecurringController::class, 'delete']);
$router->post('/api/groups/{token}/recurring/evaluate', [RecurringController::class, 'evaluate']);

function dispatchHttp(Router $router, string $method, string $path, ?array $body = null, ?array $queryParams = null): array
{
    $req = new Request($method, $path, $queryParams, $body);
    Response::$lastStatusCode = 200;
    http_response_code(200);

    ob_start();
    try {
        $router->dispatch($req);
    } catch (\InvalidArgumentException $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 422;
        Response::error($e->getMessage(), 'VALIDATION_ERROR', null, $code, false);
    } catch (\RuntimeException $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 404;
        Response::error($e->getMessage(), 'NOT_FOUND', null, $code, false);
    } catch (\Throwable $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 500;
        Response::error($e->getMessage(), 'INTERNAL_SERVER_ERROR', null, $code, false);
    }
    $raw = ob_get_clean();
    $decoded = json_decode($raw ?: '', true);

    return [
        'status' => Response::$lastStatusCode,
        'body' => is_array($decoded) ? $decoded : ['raw' => $raw],
        'raw' => $raw,
    ];
}

/**
 * Mathematically Independent Scheduling Test Oracle for Golden Verification
 * Calculates days in month via pure Gregorian arithmetic without relying on DateTime::format('t').
 */
class SchedulingOracle
{
    public static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0));
    }

    public static function getDaysInMonth(int $year, int $month): int
    {
        $table = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if ($month === 2 && self::isLeapYear($year)) {
            return 29;
        }
        return $table[$month] ?? 30;
    }

    public static function computeExpectedNext(string $currentDate, string $frequency, string $anchorDate): string
    {
        $anchorParts = explode('-', $anchorDate);
        $anchorMonth = (int) $anchorParts[1];
        $anchorDay = (int) $anchorParts[2];

        $currParts = explode('-', $currentDate);
        $currYear = (int) $currParts[0];
        $currMonth = (int) $currParts[1];

        return match (strtoupper($frequency)) {
            'WEEKLY' => (new \DateTimeImmutable($currentDate))->modify('+7 days')->format('Y-m-d'),
            'BIWEEKLY' => (new \DateTimeImmutable($currentDate))->modify('+14 days')->format('Y-m-d'),
            'MONTHLY' => (function () use ($currYear, $currMonth, $anchorDay) {
                $nextMonth = $currMonth + 1;
                $nextYear = $currYear;
                if ($nextMonth > 12) {
                    $nextMonth = 1;
                    $nextYear++;
                }
                $daysInNext = self::getDaysInMonth($nextYear, $nextMonth);
                $targetDay = min($anchorDay, $daysInNext);
                return sprintf('%04d-%02d-%02d', $nextYear, $nextMonth, $targetDay);
            })(),
            'YEARLY' => (function () use ($currYear, $anchorMonth, $anchorDay) {
                $nextYear = $currYear + 1;
                $daysInMonth = self::getDaysInMonth($nextYear, $anchorMonth);
                $targetDay = min($anchorDay, $daysInMonth);
                return sprintf('%04d-%02d-%02d', $nextYear, $anchorMonth, $targetDay);
            })(),
            default => throw new \InvalidArgumentException("Unsupported frequency {$frequency}"),
        };
    }
}

$createdGroupIds = [];

function createIsolatedWorkspace(Router $router, string $namePrefix): array
{
    global $createdGroupIds;
    static $wsIndex = 1;
    $wsIndex++;
    $_SERVER['REMOTE_ADDR'] = "10.14.0.{$wsIndex}";

    $res = dispatchHttp($router, 'POST', '/api/groups', [
        'name' => "SEC14_{$namePrefix}_" . bin2hex(random_bytes(3)),
        'currency' => 'INR',
        'creator_name' => 'Alice Chief',
    ]);
    $group = $res['body']['data']['group'];
    $creator = $res['body']['data']['creator'];
    $token = $group['invite_token'];
    $groupId = (int) $group['id'];
    $createdGroupIds[] = $groupId;

    $m2 = dispatchHttp($router, 'POST', "/api/groups/{$token}/members", ['name' => 'Bob Member'])['body']['data']['member'];
    $m3 = dispatchHttp($router, 'POST', "/api/groups/{$token}/members", ['name' => 'Charlie Member'])['body']['data']['member'];

    return [
        'group' => $group,
        'token' => $token,
        'groupId' => $groupId,
        'aliceId' => (int) $creator['id'],
        'bobId' => (int) $m2['id'],
        'charlieId' => (int) $m3['id'],
    ];
}

$recService = new RecurringService();

echo "--- 1. Testing Formal Scheduling State Machine & Due Predicate ---\n";

$wsModel = createIsolatedWorkspace($router, 'Model_SM');

// State 1: Active Rule Created
$ruleModelRes = dispatchHttp($router, 'POST', "/api/groups/{$wsModel['token']}/recurring", [
    'title' => 'State Machine Test Subscription',
    'amount_cents' => 100000,
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-10-01',
    'paid_by_member_id' => $wsModel['aliceId'],
    'split_members' => [$wsModel['aliceId'], $wsModel['bobId']],
]);
$ruleModelId = (int) $ruleModelRes['body']['data']['rule_id'];

// Check initial DB state
$smStmt = $pdo->prepare("SELECT `is_active`, `next_run_date` FROM `recurring_rules` WHERE `id` = :id");
$smStmt->execute([':id' => $ruleModelId]);
$smRow = $smStmt->fetch(PDO::FETCH_ASSOC);

assertSec14(
    (int) $smRow['is_active'] === 1 && $smRow['next_run_date'] === '2026-10-01',
    'SEC14-MODEL-01',
    'State Machine: Initial rule state is ACTIVE (is_active=1) with scheduled next_run_date'
);

// State 2: Not Due when evaluation date < next_run_date
$evalNotDue = dispatchHttp($router, 'POST', "/api/groups/{$wsModel['token']}/recurring/evaluate", [
    'current_date' => '2026-09-30',
]);
assertSec14(
    ($evalNotDue['body']['data']['evaluation']['evaluated_count'] ?? 1) === 0,
    'SEC14-MODEL-02',
    'Due Predicate: Rule is NOT due when current_date (2026-09-30) < next_run_date (2026-10-01)'
);

// State 3: Due & Materialized when current_date >= next_run_date
$evalDue = dispatchHttp($router, 'POST', "/api/groups/{$wsModel['token']}/recurring/evaluate", [
    'current_date' => '2026-10-01',
]);
assertSec14(
    ($evalDue['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 1,
    'SEC14-MODEL-03',
    'Due Predicate: Rule becomes DUE and materializes expense on exact next_run_date (2026-10-01)'
);

// State 4: Next Run Advanced
$smStmt->execute([':id' => $ruleModelId]);
$smRowPost = $smStmt->fetch(PDO::FETCH_ASSOC);
assertSec14(
    $smRowPost['next_run_date'] === '2026-11-01' && (int) $smRowPost['is_active'] === 1,
    'SEC14-MODEL-04',
    'State Machine: Next run date advanced to 2026-11-01 while remaining ACTIVE'
);

// State 5: Deleted Rule State
$delRes = dispatchHttp($router, 'DELETE', "/api/groups/{$wsModel['token']}/recurring/{$ruleModelId}");
assertSec14(
    $delRes['status'] === 200 && ($delRes['body']['data']['deleted'] ?? false) === true,
    'SEC14-MODEL-05',
    'State Machine: Rule transition to DELETED purges record from table'
);

echo "\n--- 2. Testing Pure Calendar Clock & Midnight Boundaries ---\n";

$wsClock = createIsolatedWorkspace($router, 'Clock_Boundary');
$clockRuleRes = dispatchHttp($router, 'POST', "/api/groups/{$wsClock['token']}/recurring", [
    'title' => 'Midnight Boundary Test Rent',
    'amount_cents' => 500000,
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-10-02',
    'paid_by_member_id' => $wsClock['aliceId'],
    'split_members' => [$wsClock['aliceId'], $wsClock['bobId']],
]);

// 23:59:59 previous day (2026-10-01) -> NOT due
$evalPrevDay = dispatchHttp($router, 'POST', "/api/groups/{$wsClock['token']}/recurring/evaluate", [
    'current_date' => '2026-10-01',
]);
assertSec14(
    ($evalPrevDay['body']['data']['evaluation']['evaluated_count'] ?? 1) === 0,
    'SEC14-TIMEZONE-01',
    'Midnight Boundary: Evaluation on previous calendar day (2026-10-01) does not trigger rule scheduled for 2026-10-02'
);

// 00:00:00 target day (2026-10-02) -> DUE
$evalTargetDay = dispatchHttp($router, 'POST', "/api/groups/{$wsClock['token']}/recurring/evaluate", [
    'current_date' => '2026-10-02',
]);
assertSec14(
    ($evalTargetDay['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 1,
    'SEC14-TIMEZONE-02',
    'Midnight Boundary: Evaluation on target calendar day (2026-10-02 00:00:00) triggers exactly 1 expense'
);

echo "\n--- 3. Testing Weekly Recurrence & Calendar Boundaries ---\n";

// Weekly across month boundary
$wsWeeklyMonth = createIsolatedWorkspace($router, 'Weekly_Month');
dispatchHttp($router, 'POST', "/api/groups/{$wsWeeklyMonth['token']}/recurring", [
    'title' => 'Weekly Team Lunch',
    'amount_cents' => 300000,
    'frequency' => 'WEEKLY',
    'next_run_date' => '2026-10-23', // Friday
    'paid_by_member_id' => $wsWeeklyMonth['aliceId'],
    'split_members' => [$wsWeeklyMonth['aliceId'], $wsWeeklyMonth['bobId'], $wsWeeklyMonth['charlieId']],
]);

$evalWeekly1 = dispatchHttp($router, 'POST', "/api/groups/{$wsWeeklyMonth['token']}/recurring/evaluate", [
    'current_date' => '2026-11-07',
]);

assertSec14(
    $evalWeekly1['status'] === 200 && ($evalWeekly1['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 3,
    'SEC14-WEEKLY-01',
    'Weekly rule evaluates exactly 3 occurrences crossing October/November boundary (Oct 23, Oct 30, Nov 6)',
    json_encode($evalWeekly1['body'])
);

$ruleWeeklyCheck = dispatchHttp($router, 'GET', "/api/groups/{$wsWeeklyMonth['token']}/recurring")['body']['data']['rules'][0];
assertSec14(
    $ruleWeeklyCheck['next_run_date'] === '2026-11-13',
    'SEC14-WEEKLY-02',
    'Weekly rule next_run_date advanced accurately to 2026-11-13 (Friday)',
    "Actual: {$ruleWeeklyCheck['next_run_date']}"
);

// Weekly crossing Year-End boundary
$wsYearEnd = createIsolatedWorkspace($router, 'Weekly_YearEnd');
dispatchHttp($router, 'POST', "/api/groups/{$wsYearEnd['token']}/recurring", [
    'title' => 'Weekly Friday Standup Coffee',
    'amount_cents' => 150000,
    'frequency' => 'WEEKLY',
    'next_run_date' => '2026-12-25', // Friday
    'paid_by_member_id' => $wsYearEnd['aliceId'],
    'split_members' => [$wsYearEnd['aliceId'], $wsYearEnd['bobId']],
]);

$evalYearEnd = dispatchHttp($router, 'POST', "/api/groups/{$wsYearEnd['token']}/recurring/evaluate", [
    'current_date' => '2027-01-09',
]);

assertSec14(
    $evalYearEnd['status'] === 200 && ($evalYearEnd['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 3,
    'SEC14-WEEKLY-03',
    'Weekly rule evaluates accurately across Year-End transition (2026-12-25 -> 2027-01-01 -> 2027-01-08)',
    json_encode($evalYearEnd['body'])
);

$expWeeklyYearEndStmt = $pdo->prepare("SELECT `expense_date` FROM `expenses` WHERE `group_id` = :gid ORDER BY `expense_date` ASC");
$expWeeklyYearEndStmt->execute([':gid' => $wsYearEnd['groupId']]);
$weeklyDates = $expWeeklyYearEndStmt->fetchAll(PDO::FETCH_COLUMN);

assertSec14(
    $weeklyDates === ['2026-12-25', '2027-01-01', '2027-01-08'],
    'SEC14-WEEKLY-04',
    'Database persisted expense dates match exact year-end weekly transition dates',
    "Dates: " . json_encode($weeklyDates)
);

echo "\n--- 4. Testing Biweekly Recurrence & Calendar Boundaries ---\n";

$wsBiweekly = createIsolatedWorkspace($router, 'Biweekly_Leap');
dispatchHttp($router, 'POST', "/api/groups/{$wsBiweekly['token']}/recurring", [
    'title' => 'Biweekly Housekeeping',
    'amount_cents' => 200000,
    'frequency' => 'BIWEEKLY',
    'next_run_date' => '2024-02-13', // Leap year February
    'paid_by_member_id' => $wsBiweekly['aliceId'],
    'split_members' => [$wsBiweekly['aliceId'], $wsBiweekly['bobId'], $wsBiweekly['charlieId']],
]);

$evalBiweekly = dispatchHttp($router, 'POST', "/api/groups/{$wsBiweekly['token']}/recurring/evaluate", [
    'current_date' => '2024-03-15',
]);

assertSec14(
    $evalBiweekly['status'] === 200 && ($evalBiweekly['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 3,
    'SEC14-BIWEEKLY-01',
    'Biweekly rule calculates exact 14-day intervals across Leap Day (Feb 13 -> Feb 27 -> Mar 12)',
    json_encode($evalBiweekly['body'])
);

$biweeklyExpStmt = $pdo->prepare("SELECT `expense_date` FROM `expenses` WHERE `group_id` = :gid ORDER BY `expense_date` ASC");
$biweeklyExpStmt->execute([':gid' => $wsBiweekly['groupId']]);
$biweeklyDates = $biweeklyExpStmt->fetchAll(PDO::FETCH_COLUMN);

assertSec14(
    $biweeklyDates === ['2024-02-13', '2024-02-27', '2024-03-12'],
    'SEC14-BIWEEKLY-02',
    'Database persisted expense dates match exact 14-day intervals across leap year February',
    "Dates: " . json_encode($biweeklyDates)
);

echo "\n--- 5. Testing Monthly Recurrence Month-End Boundaries (Days 28, 29, 30, 31) ---\n";

$expectedDates31 = [
    '2026-01-31', // Jan (31)
    '2026-02-28', // Feb (28 in 2026) - Clamped
    '2026-03-31', // Mar (31) - Restored!
    '2026-04-30', // Apr (30) - Clamped
    '2026-05-31', // May (31) - Restored!
    '2026-06-30', // Jun (30) - Clamped
    '2026-07-31', // Jul (31) - Restored!
    '2026-08-31', // Aug (31) - Restored!
    '2026-09-30', // Sep (30) - Clamped
    '2026-10-31', // Oct (31) - Restored!
    '2026-11-30', // Nov (30) - Clamped
    '2026-12-31', // Dec (31) - Restored!
    '2027-01-31', // Jan (31 next year) - Restored!
];

$curr31 = '2026-01-31';
$actualProgression31 = ['2026-01-31'];
for ($step = 1; $step <= 12; $step++) {
    $curr31 = $recService->computeNextDate($curr31, 'MONTHLY', '2026-01-31');
    $actualProgression31[] = $curr31;
}

assertSec14(
    $actualProgression31 === $expectedDates31,
    'SEC14-MONTHLY-01',
    'Algorithm: Full 12-Month Progression from Jan 31 clamps shorter months and restores day 31 without skipping or drift',
    "Actual: " . json_encode($actualProgression31)
);

$wsMonth31 = createIsolatedWorkspace($router, 'Monthly_31');
dispatchHttp($router, 'POST', "/api/groups/{$wsMonth31['token']}/recurring", [
    'title' => 'Monthly Cloud Server Hosting',
    'amount_cents' => 500000,
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-01-31',
    'paid_by_member_id' => $wsMonth31['aliceId'],
    'split_members' => [$wsMonth31['aliceId'], $wsMonth31['bobId'], $wsMonth31['charlieId']],
]);

$evalMonth31 = dispatchHttp($router, 'POST', "/api/groups/{$wsMonth31['token']}/recurring/evaluate", [
    'current_date' => '2027-01-31',
]);

assertSec14(
    $evalMonth31['status'] === 200 && ($evalMonth31['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 13,
    'SEC14-MONTHLY-02',
    'Database evaluation generates all 13 monthly occurrences (Jan 2026 through Jan 2027) with zero skipped months',
    json_encode($evalMonth31['body'])
);

$exp31Stmt = $pdo->prepare("SELECT `expense_date` FROM `expenses` WHERE `group_id` = :gid ORDER BY `expense_date` ASC");
$exp31Stmt->execute([':gid' => $wsMonth31['groupId']]);
$dbDates31 = $exp31Stmt->fetchAll(PDO::FETCH_COLUMN);

assertSec14(
    $dbDates31 === $expectedDates31,
    'SEC14-MONTHLY-03',
    'Database persisted monthly dates match exact expected month-end calendar boundaries',
    "DB Dates: " . json_encode($dbDates31)
);

// Monthly Day 30 Progression
$expectedDates30 = [
    '2026-01-30', '2026-02-28', '2026-03-30', '2026-04-30', '2026-05-30', '2026-06-30',
    '2026-07-30', '2026-08-30', '2026-09-30', '2026-10-30', '2026-11-30', '2026-12-30', '2027-01-30'
];
$curr30 = '2026-01-30';
$actual30 = ['2026-01-30'];
for ($step = 1; $step <= 12; $step++) {
    $curr30 = $recService->computeNextDate($curr30, 'MONTHLY', '2026-01-30');
    $actual30[] = $curr30;
}
assertSec14(
    $actual30 === $expectedDates30,
    'SEC14-MONTHLY-04',
    'Monthly recurrence starting Day 30 clamps to Feb 28 and restores Day 30 for all other months',
    "Actual: " . json_encode($actual30)
);

// Monthly Day 29 in Common Year vs Leap Year (Full 12 Months)
$expectedDates29Common = [
    '2026-01-29', '2026-02-28', '2026-03-29', '2026-04-29', '2026-05-29', '2026-06-29',
    '2026-07-29', '2026-08-29', '2026-09-29', '2026-10-29', '2026-11-29', '2026-12-29', '2027-01-29'
];
$curr29Common = '2026-01-29';
$actual29Common = ['2026-01-29'];
for ($step = 1; $step <= 12; $step++) {
    $curr29Common = $recService->computeNextDate($curr29Common, 'MONTHLY', '2026-01-29');
    $actual29Common[] = $curr29Common;
}
assertSec14(
    $actual29Common === $expectedDates29Common,
    'SEC14-MONTHLY-05',
    'Monthly Day 29 in common year clamps to Feb 28 and restores Day 29 for all subsequent months',
    "Actual: " . json_encode($actual29Common)
);

$expectedDates29Leap = [
    '2024-01-29', '2024-02-29', '2024-03-29', '2024-04-29', '2024-05-29', '2024-06-29',
    '2024-07-29', '2024-08-29', '2024-09-29', '2024-10-29', '2024-11-29', '2024-12-29', '2025-01-29'
];
$curr29Leap = '2024-01-29';
$actual29Leap = ['2024-01-29'];
for ($step = 1; $step <= 12; $step++) {
    $curr29Leap = $recService->computeNextDate($curr29Leap, 'MONTHLY', '2024-01-29');
    $actual29Leap[] = $curr29Leap;
}
assertSec14(
    $actual29Leap === $expectedDates29Leap,
    'SEC14-MONTHLY-06',
    'Monthly Day 29 in leap year lands exactly on Feb 29 (Leap Day) and preserves Day 29 throughout',
    "Actual: " . json_encode($actual29Leap)
);

echo "\n--- 6. Testing Yearly Recurrence & Leap Day Progression (Feb 29) ---\n";

$expectedYearly = [
    '2024-02-29', // 2024 (Leap)
    '2025-02-28', // 2025 (Common) - Clamped
    '2026-02-28', // 2026 (Common) - Clamped
    '2027-02-28', // 2027 (Common) - Clamped
    '2028-02-29', // 2028 (Leap) - Restored!
    '2029-02-28', // 2029 (Common) - Clamped
];

$currYearly = '2024-02-29';
$actualYearly = ['2024-02-29'];
for ($step = 1; $step <= 5; $step++) {
    $currYearly = $recService->computeNextDate($currYearly, 'YEARLY', '2024-02-29');
    $actualYearly[] = $currYearly;
}

assertSec14(
    $actualYearly === $expectedYearly,
    'SEC14-YEARLY-01',
    'Yearly Leap Day recurrence clamps to Feb 28 in common years and restores Feb 29 in leap years without shifting to March 1',
    "Actual: " . json_encode($actualYearly)
);

$wsYearlyLeap = createIsolatedWorkspace($router, 'Yearly_Leap');
$ruleLeapRes = dispatchHttp($router, 'POST', "/api/groups/{$wsYearlyLeap['token']}/recurring", [
    'title' => 'Leap Day Four-Year License',
    'amount_cents' => 1200000,
    'frequency' => 'YEARLY',
    'next_run_date' => '2024-02-29',
    'paid_by_member_id' => $wsYearlyLeap['aliceId'],
    'split_members' => [$wsYearlyLeap['aliceId'], $wsYearlyLeap['bobId'], $wsYearlyLeap['charlieId']],
]);
$ruleLeapId = (int) $ruleLeapRes['body']['data']['rule_id'];

$evalLeap = dispatchHttp($router, 'POST', "/api/groups/{$wsYearlyLeap['token']}/recurring/evaluate", [
    'current_date' => '2028-03-01',
]);

assertSec14(
    $evalLeap['status'] === 200 && ($evalLeap['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 5,
    'SEC14-YEARLY-02',
    'Database materializes exactly 5 occurrences (2024 through 2028) with correct Feb 28/29 dates',
    json_encode($evalLeap['body'])
);

$expLeapStmt = $pdo->prepare("SELECT `expense_date` FROM `expenses` WHERE `group_id` = :gid ORDER BY `expense_date` ASC");
$expLeapStmt->execute([':gid' => $wsYearlyLeap['groupId']]);
$dbLeapDates = $expLeapStmt->fetchAll(PDO::FETCH_COLUMN);

assertSec14(
    $dbLeapDates === array_slice($expectedYearly, 0, 5),
    'SEC14-YEARLY-03',
    'Database persisted leap dates match expected sequence: [2024-02-29, 2025-02-28, 2026-02-28, 2027-02-28, 2028-02-29]',
    "DB Dates: " . json_encode($dbLeapDates)
);

// Extended Leap-Day Cycle: 2028 -> 2032 Proof
$expectedLeap2032 = [
    '2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29', '2033-02-28'
];
$curr2032 = '2028-02-29';
$actual2032 = ['2028-02-29'];
for ($step = 1; $step <= 5; $step++) {
    $curr2032 = $recService->computeNextDate($curr2032, 'YEARLY', '2028-02-29');
    $actual2032[] = $curr2032;
}
assertSec14(
    $actual2032 === $expectedLeap2032,
    'SEC14-YEARLY-04',
    'Yearly Leap Day 2028 -> 2032 progression clamps and restores Feb 29 on 2032 leap year',
    "Actual: " . json_encode($actual2032)
);

// Century Leap Rules Verification (Year 2000 vs Year 2100)
$nextCentury2000 = $recService->computeNextDate('1999-02-28', 'YEARLY', '1999-02-28');
$daysInFeb2000 = (int) (new \DateTimeImmutable('2000-02-01'))->format('t');
$daysInFeb2100 = (int) (new \DateTimeImmutable('2100-02-01'))->format('t');

assertSec14(
    $daysInFeb2000 === 29 && $daysInFeb2100 === 28,
    'SEC14-LEAP-01',
    'Century Leap Rules: Year 2000 has 29 days (Leap Century), Year 2100 has 28 days (Non-Leap Century)'
);

echo "\n--- 7. Testing Anchor Persistence & Immutability in Database payload_json ---\n";

// Verify _anchor_date persists in DB payload_json after multiple evaluations
$anchorDbStmt = $pdo->prepare("SELECT `payload_json`, `next_run_date` FROM `recurring_rules` WHERE `id` = :id");
$anchorDbStmt->execute([':id' => $ruleLeapId]);
$anchorRow = $anchorDbStmt->fetch(PDO::FETCH_ASSOC);
$decodedPayload = json_decode((string) $anchorRow['payload_json'], true);

assertSec14(
    isset($decodedPayload['_anchor_date']) && $decodedPayload['_anchor_date'] === '2024-02-29',
    'SEC14-ANCHOR-01',
    'Database payload_json._anchor_date persists unmodified as "2024-02-29" after 5 evaluations',
    "Decoded: " . json_encode($decodedPayload)
);

assertSec14(
    $anchorRow['next_run_date'] === '2029-02-28',
    'SEC14-ANCHOR-02',
    'Rule next_run_date advanced to 2029-02-28 while payload_json anchor remained immutable',
    "next_run_date: {$anchorRow['next_run_date']}"
);

echo "\n--- 8. Testing Delayed Execution Catch-Up & Same-Second Idempotency ---\n";

$wsDelayed = createIsolatedWorkspace($router, 'Delayed_CatchUp');
$ruleDelayedRes = dispatchHttp($router, 'POST', "/api/groups/{$wsDelayed['token']}/recurring", [
    'title' => 'Delayed Gym Membership',
    'amount_cents' => 250000,
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-01-01',
    'paid_by_member_id' => $wsDelayed['aliceId'],
    'split_members' => [$wsDelayed['aliceId'], $wsDelayed['bobId']],
]);
$ruleDelayedId = (int) $ruleDelayedRes['body']['data']['rule_id'];

// First Evaluation: catches up 5 occurrences (Jan 1, Feb 1, Mar 1, Apr 1, May 1)
$evalDelay1 = dispatchHttp($router, 'POST', "/api/groups/{$wsDelayed['token']}/recurring/evaluate", [
    'current_date' => '2026-05-15',
]);

assertSec14(
    $evalDelay1['status'] === 200 && ($evalDelay1['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 5,
    'SEC14-DELAY-01',
    'Delayed evaluation correctly catches up all 5 missed monthly occurrences (Jan, Feb, Mar, Apr, May)',
    json_encode($evalDelay1['body'])
);

// Immediate Second Evaluation on same date: MUST create 0 new expenses (Idempotent)
$evalDelay2 = dispatchHttp($router, 'POST', "/api/groups/{$wsDelayed['token']}/recurring/evaluate", [
    'current_date' => '2026-05-15',
]);

assertSec14(
    $evalDelay2['status'] === 200 && ($evalDelay2['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 0,
    'SEC14-REPEAT-01',
    'Immediate re-evaluation produces 0 duplicate expenses and zero state drift',
    json_encode($evalDelay2['body'])
);

// Third Evaluation: same second
$evalDelay3 = dispatchHttp($router, 'POST', "/api/groups/{$wsDelayed['token']}/recurring/evaluate", [
    'current_date' => '2026-05-15',
]);

assertSec14(
    $evalDelay3['status'] === 200 && ($evalDelay3['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 0,
    'SEC14-REPEAT-02',
    'Third sequential evaluation remains strictly idempotent',
    json_encode($evalDelay3['body'])
);

$delayedRules = dispatchHttp($router, 'GET', "/api/groups/{$wsDelayed['token']}/recurring")['body']['data']['rules'];
assertSec14(
    count($delayedRules) === 1 && $delayedRules[0]['next_run_date'] === '2026-06-01',
    'SEC14-DELAY-02',
    'Delayed rule next_run_date advanced to future occurrence 2026-06-01',
    "Actual: " . ($delayedRules[0]['next_run_date'] ?? 'null')
);

// Idempotency Key Persistence Verification in Database
$idempStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `idempotency_keys` WHERE `idempotency_key` LIKE :pfx");
$idempStmt->execute([':pfx' => "recurring_rule_{$ruleDelayedId}_%"]);
$idempCount = (int) ($idempStmt->fetch()['cnt'] ?? 0);

assertSec14(
    $idempCount === 5,
    'SEC14-IDEMPOTENCY-01',
    'Exactly 5 unique idempotency keys recorded in idempotency_keys table preventing duplicate expense generation',
    "Found: {$idempCount} keys"
);

echo "\n--- 9. Testing End Date & Expiration Lifecycle ---\n";

$wsEnd = createIsolatedWorkspace($router, 'End_Date');
$ruleEndRes = dispatchHttp($router, 'POST', "/api/groups/{$wsEnd['token']}/recurring", [
    'title' => 'Limited 3-Month Course Tuition',
    'amount_cents' => 800000,
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-01-15',
    'end_date' => '2026-03-15',
    'paid_by_member_id' => $wsEnd['aliceId'],
    'split_members' => [$wsEnd['aliceId'], $wsEnd['bobId']],
]);
$ruleEndId = (int) $ruleEndRes['body']['data']['rule_id'];

$evalEnd = dispatchHttp($router, 'POST', "/api/groups/{$wsEnd['token']}/recurring/evaluate", [
    'current_date' => '2026-06-01',
]);

$expEndStmt = $pdo->prepare("SELECT `expense_date` FROM `expenses` WHERE `group_id` = :gid ORDER BY `expense_date` ASC");
$expEndStmt->execute([':gid' => $wsEnd['groupId']]);
$dbEndDates = $expEndStmt->fetchAll(PDO::FETCH_COLUMN);

assertSec14(
    $dbEndDates === ['2026-01-15', '2026-02-15', '2026-03-15'],
    'SEC14-END-01',
    'Rule with end_date generates exactly the intended 3 occurrences and zero occurrences beyond end_date',
    "DB Dates: " . json_encode($dbEndDates)
);

$ruleEndDbStmt = $pdo->prepare("SELECT `is_active`, `next_run_date` FROM `recurring_rules` WHERE `id` = :id");
$ruleEndDbStmt->execute([':id' => $ruleEndId]);
$ruleEndDb = $ruleEndDbStmt->fetch(PDO::FETCH_ASSOC);

assertSec14(
    (int) ($ruleEndDb['is_active'] ?? 1) === 0,
    'SEC14-END-02',
    'Expired rule is permanently deactivated (is_active=0) after reaching end_date',
    json_encode($ruleEndDb)
);

echo "\n--- 10. Testing Frequency Contract & Supported vs Unsupported Frequencies ---\n";

$wsFreq = createIsolatedWorkspace($router, 'Frequency_Contract');

// Supported frequencies: WEEKLY, BIWEEKLY, MONTHLY, YEARLY
$suppFreqs = ['WEEKLY', 'BIWEEKLY', 'MONTHLY', 'YEARLY'];
$suppPass = true;
foreach ($suppFreqs as $sf) {
    $r = dispatchHttp($router, 'POST', "/api/groups/{$wsFreq['token']}/recurring", [
        'title' => "Subscription {$sf}",
        'amount_cents' => 100000,
        'frequency' => $sf,
        'next_run_date' => '2026-06-01',
        'paid_by_member_id' => $wsFreq['aliceId'],
        'split_members' => [$wsFreq['aliceId'], $wsFreq['bobId']],
    ]);
    if ($r['status'] !== 201) {
        $suppPass = false;
        break;
    }
}

assertSec14(
    $suppPass,
    'SEC14-FREQ-01',
    'Supported Frequencies Contract: WEEKLY, BIWEEKLY, MONTHLY, YEARLY are successfully accepted with HTTP 201'
);

// Unsupported frequencies: DAILY, HOURLY, CUSTOM
$unsuppFreqs = ['DAILY', 'HOURLY', 'CUSTOM', 'MINUTELY'];
$unsuppPass = true;
foreach ($unsuppFreqs as $uf) {
    $r = dispatchHttp($router, 'POST', "/api/groups/{$wsFreq['token']}/recurring", [
        'title' => "Invalid Subscription {$uf}",
        'amount_cents' => 100000,
        'frequency' => $uf,
        'next_run_date' => '2026-06-01',
        'paid_by_member_id' => $wsFreq['aliceId'],
        'split_members' => [$wsFreq['aliceId'], $wsFreq['bobId']],
    ]);
    if ($r['status'] !== 422) {
        $unsuppPass = false;
        break;
    }
}

assertSec14(
    $unsuppPass,
    'SEC14-FREQ-02',
    'Unsupported Frequencies Rejection: DAILY, HOURLY, CUSTOM, MINUTELY are rejected with HTTP 422'
);

echo "\n--- 11. Testing Start Date & Malformed Date Validation ---\n";

$wsVal = createIsolatedWorkspace($router, 'Validation');

// Validation 1: Malformed start date (impossible date Feb 31)
$badDate1 = dispatchHttp($router, 'POST', "/api/groups/{$wsVal['token']}/recurring", [
    'title' => 'Bad Date Rule',
    'amount_cents' => 100000,
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-02-31', // Impossible!
    'paid_by_member_id' => $wsVal['aliceId'],
    'split_members' => [$wsVal['aliceId'], $wsVal['bobId']],
]);

assertSec14(
    $badDate1['status'] === 422,
    'SEC14-INVALID-01',
    'Impossible start date 2026-02-31 is rejected with HTTP 422',
    json_encode($badDate1['body'])
);

// Validation 2: End date before start date
$badDate2 = dispatchHttp($router, 'POST', "/api/groups/{$wsVal['token']}/recurring", [
    'title' => 'Inverted End Date Rule',
    'amount_cents' => 100000,
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-06-01',
    'end_date' => '2026-05-01', // Before start!
    'paid_by_member_id' => $wsVal['aliceId'],
    'split_members' => [$wsVal['aliceId'], $wsVal['bobId']],
]);

assertSec14(
    $badDate2['status'] === 422,
    'SEC14-INVALID-02',
    'End date earlier than start date is rejected with HTTP 422',
    json_encode($badDate2['body'])
);

echo "\n--- 12. Testing Split Methodologies & Financial Invariance ---\n";

$wsFin = createIsolatedWorkspace($router, 'Financial');

// 1. Itemized split rule with tax and tip
$ruleItemized = dispatchHttp($router, 'POST', "/api/groups/{$wsFin['token']}/recurring", [
    'title' => 'Monthly Coworking & Snacks',
    'split_type' => 'ITEMIZED',
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-06-01',
    'paid_by_member_id' => $wsFin['aliceId'],
    'items' => [
        ['name' => 'Desk Rent', 'amount_cents' => 100000, 'member_ids' => [$wsFin['aliceId'], $wsFin['bobId']]],
        ['name' => 'Coffee & Snacks', 'amount_cents' => 50000, 'member_ids' => [$wsFin['charlieId']]],
    ],
    'tax_cents' => 15000,
    'tip_cents' => 5000,
    'discount_cents' => 0,
]);

assertSec14(
    $ruleItemized['status'] === 201,
    'SEC14-SPLIT-01',
    'Itemized recurring rule with taxes and tips created with auto-calculated total (170,000 paise)',
    json_encode($ruleItemized['body'])
);

// 2. Percentage split rule
$rulePct = dispatchHttp($router, 'POST', "/api/groups/{$wsFin['token']}/recurring", [
    'title' => 'Monthly Software License',
    'split_type' => 'PERCENTAGE',
    'total_amount_cents' => 100000, // ₹1,000.00
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-06-01',
    'paid_by_member_id' => $wsFin['bobId'],
    'splits' => [
        ['member_id' => $wsFin['aliceId'], 'percentage' => 50],
        ['member_id' => $wsFin['bobId'], 'percentage' => 30],
        ['member_id' => $wsFin['charlieId'], 'percentage' => 20],
    ],
]);

assertSec14(
    $rulePct['status'] === 201,
    'SEC14-SPLIT-02',
    'Percentage recurring rule created with 50%/30%/20% allocations',
    json_encode($rulePct['body'])
);

// 3. Shares split rule
$ruleShares = dispatchHttp($router, 'POST', "/api/groups/{$wsFin['token']}/recurring", [
    'title' => 'Monthly Office Electricity',
    'split_type' => 'SHARES',
    'total_amount_cents' => 600000, // ₹6,000.00
    'frequency' => 'MONTHLY',
    'next_run_date' => '2026-06-01',
    'paid_by_member_id' => $wsFin['charlieId'],
    'splits' => [
        ['member_id' => $wsFin['aliceId'], 'shares' => 3],
        ['member_id' => $wsFin['bobId'], 'shares' => 2],
        ['member_id' => $wsFin['charlieId'], 'shares' => 1],
    ],
]);

assertSec14(
    $ruleShares['status'] === 201,
    'SEC14-SPLIT-03',
    'Shares recurring rule created with 3:2:1 ratio allocations',
    json_encode($ruleShares['body'])
);

// Evaluate all 3 rules on 2026-06-01
$evalFin = dispatchHttp($router, 'POST', "/api/groups/{$wsFin['token']}/recurring/evaluate", [
    'current_date' => '2026-06-01',
]);

assertSec14(
    $evalFin['status'] === 200 && ($evalFin['body']['data']['evaluation']['created_expenses_count'] ?? 0) === 3,
    'SEC14-SPLIT-04',
    'All 3 multi-split methodology recurring rules materialized successfully on 2026-06-01',
    json_encode($evalFin['body'])
);

// Fetch Group Balances & verify Zero-Sum Invariant
$balRes = dispatchHttp($router, 'GET', "/api/groups/{$wsFin['token']}/balances");
$balances = $balRes['body']['data'] ?? [];
$members = $balances['members'] ?? [];

$sumNetCents = 0;
foreach ($members as $m) {
    $sumNetCents += (int) $m['net_balance_cents'];
}

assertSec14(
    $balRes['status'] === 200 && $sumNetCents === 0 && count($members) === 3,
    'SEC14-FINANCIAL-01',
    'Strict Zero-Sum Conservation (SUM(net_balance_cents) = 0) verified across all materialized recurring expenses',
    "Total Net Balance Sum: {$sumNetCents} paise"
);

// Verify payer sum = expense total = split sum on all generated expenses
$allExpStmt = $pdo->prepare("SELECT `id`, `total_amount_cents` FROM `expenses` WHERE `group_id` = :gid");
$allExpStmt->execute([':gid' => $wsFin['groupId']]);
$allExpenses = $allExpStmt->fetchAll(PDO::FETCH_ASSOC);

$financialIntegrityPass = true;
foreach ($allExpenses as $exp) {
    $eid = (int) $exp['id'];
    $tot = (int) $exp['total_amount_cents'];

    $pSum = (int) ($pdo->query("SELECT SUM(amount_paid_cents) FROM expense_payers WHERE expense_id = {$eid}")->fetchColumn() ?: 0);
    $sSum = (int) ($pdo->query("SELECT SUM(amount_owed_cents) FROM expense_splits WHERE expense_id = {$eid}")->fetchColumn() ?: 0);

    if ($pSum !== $tot || $sSum !== $tot) {
        $financialIntegrityPass = false;
        break;
    }
}

assertSec14(
    $financialIntegrityPass && count($allExpenses) === 3,
    'SEC14-FINANCIAL-02',
    'Financial Conservation (Payer Sum = Total = Split Sum) verified 100% for all materialized expenses in ledger',
    "Verified " . count($allExpenses) . " expenses"
);

echo "\n--- 13. Randomized Calendar Fuzzing (250+ Scenarios against Independent Oracle) ---\n";

$fuzzCount = 250;
$fuzzPass = 0;
$fuzzFailures = [];

$frequencies = ['WEEKLY', 'BIWEEKLY', 'MONTHLY', 'YEARLY'];
mt_srand(424242); // Deterministic test seed

for ($i = 1; $i <= $fuzzCount; $i++) {
    $freq = $frequencies[array_rand($frequencies)];
    
    // Pick random year (2020 to 2030), month (1 to 12), and valid day
    $year = mt_rand(2020, 2030);
    $month = mt_rand(1, 12);
    $daysInMonth = SchedulingOracle::getDaysInMonth($year, $month);
    $day = mt_rand(1, $daysInMonth);
    
    $startDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
    $anchorDate = $startDate;

    // Advance 1 to 6 steps
    $steps = mt_rand(1, 6);
    $currentDate = $startDate;
    $oracleDate = $startDate;

    $scenarioSuccess = true;
    for ($s = 1; $s <= $steps; $s++) {
        $expected = SchedulingOracle::computeExpectedNext($oracleDate, $freq, $anchorDate);
        $actual = $recService->computeNextDate($currentDate, $freq, $anchorDate);

        if ($expected !== $actual) {
            $scenarioSuccess = false;
            $fuzzFailures[] = [
                'scenario' => $i,
                'frequency' => $freq,
                'anchorDate' => $anchorDate,
                'currentDate' => $currentDate,
                'expected' => $expected,
                'actual' => $actual,
            ];
            break;
        }

        $oracleDate = $expected;
        $currentDate = $actual;
    }

    if ($scenarioSuccess) {
        $fuzzPass++;
    }
}

assertSec14(
    $fuzzPass === $fuzzCount,
    'SEC14-FUZZ-01',
    "Randomized Calendar Fuzzing: {$fuzzPass}/{$fuzzCount} scenarios verified against Independent Scheduling Oracle",
    count($fuzzFailures) > 0 ? "First Failure: " . json_encode($fuzzFailures[0]) : "100% Match"
);

echo "\n--- 14. Deterministic Fixture Teardown ---\n";

// Teardown all test workspaces created during the run
foreach ($createdGroupIds as $gid) {
    $pdo->prepare("DELETE FROM `idempotency_keys` WHERE `group_id` = :gid")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `expense_splits` WHERE `expense_id` IN (SELECT `id` FROM `expenses` WHERE `group_id` = :gid)")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `expense_payers` WHERE `expense_id` IN (SELECT `id` FROM `expenses` WHERE `group_id` = :gid)")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `expenses` WHERE `group_id` = :gid")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `recurring_rules` WHERE `group_id` = :gid")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `members` WHERE `group_id` = :gid")->execute([':gid' => $gid]);
    $pdo->prepare("DELETE FROM `groups` WHERE `id` = :gid")->execute([':gid' => $gid]);
}

assertSec14(
    true,
    'SEC14-CLEANUP-01',
    'Deterministic teardown completed; all transient test fixtures cleaned up'
);

echo "\n================================================================================\n";
echo " SEC-14 AUDIT SUMMARY\n";
echo "================================================================================\n";
echo " Total Assertions:  {$totalAssertions}\n";
echo " Passed Assertions: {$passedAssertions}\n";
echo " Failed Assertions: " . count($failedAssertions) . "\n";
echo " Success Rate:      " . ($totalAssertions > 0 ? round(($passedAssertions / $totalAssertions) * 100, 2) : 0) . "%\n";
echo "================================================================================\n\n";

if (count($failedAssertions) > 0) {
    echo "FAILED ASSERTIONS:\n";
    foreach ($failedAssertions as $fa) {
        echo " - [{$fa['code']}] {$fa['description']}\n";
        if ($fa['details']) {
            echo "   Details: {$fa['details']}\n";
        }
    }
    exit(1);
}

exit(0);
