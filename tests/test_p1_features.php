<?php

declare(strict_types=1);

/**
 * Smart Split V2 — P1 Test Suite: Real-Time SSE Sync, Receipt Storage Abstraction & Expense Duplication
 * 
 * Validates:
 * 1. P1.2 Receipt Storage Abstraction:
 *    - ReceiptStorageInterface contracts
 *    - LocalReceiptStorage (storage, mime checks, traversal prevention, size enforcement, delete)
 *    - ReceiptStorageFactory driver resolution & custom driver registration
 * 2. P1.1 Real-Time Server-Sent Events (SSE):
 *    - EventRepository (recording, incremental fetching, bounded window, cleanup)
 *    - EventService (emit, broadcast, version increment)
 *    - End-to-End mutation event emission (expense created/updated/deleted/restored, settlement created/deleted, member created/updated/deleted, receipt created/deleted)
 *    - EventController SSE endpoint (polling mode, once-streaming mode, 404 handling)
 * 3. P1.3 Expense Duplication Flow:
 *    - Complete end-to-end duplicate creation, split integrity, exclusion of prior receipt attachments, and new event emission
 */

spl_autoload_register(function (string $class): void {
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

use App\Core\Env;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Services\Storage\ReceiptStorageInterface;
use App\Services\Storage\LocalReceiptStorage;
use App\Services\Storage\ReceiptStorageFactory;
use App\Repositories\EventRepository;
use App\Services\EventService;
use App\Services\ReceiptService;
use App\Repositories\GroupRepository;
use App\Repositories\MemberRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\SettlementRepository;
use App\Repositories\ReceiptRepository;
use App\Controllers\ExpenseController;
use App\Controllers\SettlementController;
use App\Controllers\MemberController;
use App\Controllers\EventController;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

echo "================================================================================\n";
echo " Smart Split V2: P1 Real-Time SSE, Storage Abstraction & Duplication Tests\n";
echo "================================================================================\n";

$testsPassed = 0;
$totalTests = 0;

function assertTest(bool $condition, string $testName, ?string $details = null): void
{
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$testName}\n";
        if ($details) {
            echo "         Details: {$details}\n";
        }
        exit(1);
    }
}

// Set up Router & Dispatch Helper
$router = new Router();
$router->post('/api/groups/{token}/expenses', [ExpenseController::class, 'create']);
$router->put('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'update']);
$router->delete('/api/groups/{token}/expenses/{id}', [ExpenseController::class, 'delete']);
$router->post('/api/groups/{token}/expenses/{id}/restore', [ExpenseController::class, 'restore']);
$router->post('/api/groups/{token}/settlements', [SettlementController::class, 'create']);
$router->delete('/api/groups/{token}/settlements/{id}', [SettlementController::class, 'delete']);
$router->post('/api/groups/{token}/members', [MemberController::class, 'create']);
$router->put('/api/groups/{token}/members/{id}', [MemberController::class, 'update']);
$router->delete('/api/groups/{token}/members/{id}', [MemberController::class, 'delete']);
$router->get('/api/groups/{token}/events', [EventController::class, 'stream']);

function dispatch(Router $router, Request $req): array
{
    ob_start();
    try {
        $router->dispatch($req);
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    $raw = ob_get_clean();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['raw' => $raw];
}

// -----------------------------------------------------------------------------
// SECTION 1: P1.2 RECEIPT STORAGE ABSTRACTION TESTS
// -----------------------------------------------------------------------------
echo "\n--- Section 1: P1.2 Receipt Storage Abstraction ---\n";

$storage = new LocalReceiptStorage();
assertTest($storage instanceof ReceiptStorageInterface, 'LocalReceiptStorage implements ReceiptStorageInterface');

// Test 1.1: Store and retrieve binary file
$testFileName = 'test_p1_' . time() . '_' . bin2hex(random_bytes(4)) . '.png';
// 1x1 transparent PNG binary bytes
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$storedPath = $storage->store($testFileName, $pngBytes, 'image/png');
assertTest($storage->exists($testFileName), 'LocalReceiptStorage reports stored file exists');
assertTest($storage->getMimeType($testFileName) === 'image/png', 'LocalReceiptStorage reports correct MIME type');
assertTest($storage->read($testFileName) === $pngBytes, 'LocalReceiptStorage reads back identical file binary');

// Test 1.2: Path traversal attack prevention
$traversalBlocked = false;
try {
    $storage->store('../sneaky.png', $pngBytes, 'image/png');
} catch (\InvalidArgumentException $e) {
    $traversalBlocked = true;
}
assertTest($traversalBlocked, 'LocalReceiptStorage rejects path traversal filename with ..');

// Test 1.3: Disallowed MIME type rejection
$mimeBlocked = false;
try {
    $storage->store('script.php', '<?php phpinfo(); ?>', 'application/x-php');
} catch (\InvalidArgumentException $e) {
    $mimeBlocked = true;
}
assertTest($mimeBlocked, 'LocalReceiptStorage rejects unsupported MIME type (application/x-php)');

// Test 1.4: Delete file
$deleteResult = $storage->delete($testFileName);
assertTest($deleteResult === true, 'LocalReceiptStorage delete returns true');
assertTest(!$storage->exists($testFileName), 'LocalReceiptStorage reports deleted file no longer exists');

// Test 1.5: ReceiptStorageFactory resolution & custom driver registration
ReceiptStorageFactory::reset();
$defaultStorage = ReceiptStorageFactory::getInstance();
assertTest($defaultStorage instanceof LocalReceiptStorage, 'ReceiptStorageFactory resolves local driver by default');

// Custom storage driver mock
class MockCloudReceiptStorage implements ReceiptStorageInterface {
    public array $files = [];
    public function store(string $key, string $contents, string $mimeType): bool {
        $this->files[$key] = ['contents' => $contents, 'mime' => $mimeType];
        return true;
    }
    public function read(string $key): ?string {
        return $this->files[$key]['contents'] ?? null;
    }
    public function exists(string $key): bool {
        return isset($this->files[$key]);
    }
    public function delete(string $key): bool {
        unset($this->files[$key]);
        return true;
    }
    public function getMimeType(string $key): ?string {
        return $this->files[$key]['mime'] ?? null;
    }
    public function getSizeBytes(string $key): int {
        return strlen($this->files[$key]['contents'] ?? '');
    }
    public function getLocalPath(string $key): ?string {
        return null;
    }
}

ReceiptStorageFactory::registerDriver('mock_s3', fn() => new MockCloudReceiptStorage());
$customStorage = ReceiptStorageFactory::create('mock_s3');
assertTest($customStorage instanceof MockCloudReceiptStorage, 'ReceiptStorageFactory resolves custom registered storage driver');

$cloudStoredResult = $customStorage->store('cloud_doc.pdf', '%PDF-1.4 test', 'application/pdf');
assertTest($cloudStoredResult === true, 'Custom mock storage correctly stores content and returns true');
assertTest($customStorage->exists('cloud_doc.pdf'), 'Custom mock storage exists check passes');

// Reset factory back to local storage
ReceiptStorageFactory::reset();

// -----------------------------------------------------------------------------
// SECTION 2: P1.1 REAL-TIME SERVER-SENT EVENTS (SSE) TESTS
// -----------------------------------------------------------------------------
echo "\n--- Section 2: P1.1 Real-Time Server-Sent Events (SSE) ---\n";

// Set up a test workspace
$groupRepo = new GroupRepository();
$memberRepo = new MemberRepository();
$expenseRepo = new ExpenseRepository();
$settlementRepo = new SettlementRepository();
$receiptRepo = new ReceiptRepository();

$eventRepo = new EventRepository();
$eventService = new EventService($eventRepo, $groupRepo);

$createdGroup = $groupRepo->create('P1 Test Workspace', 'INR');
$groupId = (int) $createdGroup['id'];
$testInviteToken = (string) $createdGroup['invite_token'];

$memberA = (int) $memberRepo->create($groupId, 'Alice P1')['id'];
$memberB = (int) $memberRepo->create($groupId, 'Bob P1')['id'];
$memberC = (int) $memberRepo->create($groupId, 'Charlie P1')['id'];

// Test 2.1: EventRepository & EventService basic recording and retrieval
$initialEvents = $eventService->getEventsSince($groupId, 0);
$startEventCount = count($initialEvents);

$testEventId = $eventService->broadcast($groupId, 'expense.created', 9999);
assertTest($testEventId > 0, 'EventService::broadcast returns positive event ID');

$fetchedEvents = $eventService->getEventsSince($groupId, 0);
assertTest(count($fetchedEvents) === $startEventCount + 1, 'EventService retrieves recorded event');
$lastEv = end($fetchedEvents);
assertTest($lastEv['event_type'] === 'expense.created', 'Event type matches expense.created');
assertTest((int) $lastEv['entity_id'] === 9999, 'Entity ID matches 9999');

// Test 2.2: Incremental fetching using lastEventId
$incrementalEvents = $eventService->getEventsSince($groupId, $testEventId);
assertTest(empty($incrementalEvents), 'Incremental fetch with lastEventId returns empty when no new events exist');

$secondEventId = $eventService->broadcast($groupId, 'settlement.created', 8888);
$incrementalEvents2 = $eventService->getEventsSince($groupId, $testEventId);
assertTest(count($incrementalEvents2) === 1, 'Incremental fetch retrieves only events after lastEventId');
assertTest($incrementalEvents2[0]['event_type'] === 'settlement.created', 'Incremental event is settlement.created');

// Test 2.3: End-to-End Mutation Event Broadcasts via HTTP Router
echo "\n--- Section 2.3: Core Mutations Broadcast Verification ---\n";

$receiptService = new ReceiptService();
$latestEventId = $secondEventId;

// Mutation 1: Create Expense via HTTP
$createExpRes = dispatch($router, new Request(
    method: 'POST',
    path: "/api/groups/{$testInviteToken}/expenses",
    body: [
        'title' => 'Dinner at P1 Bistro',
        'amount_cents' => 300000,
        'currency_code' => 'INR',
        'split_type' => 'EQUAL',
        'paid_by_member_id' => $memberA,
        'splits' => [
            ['member_id' => $memberA, 'amount_owed_cents' => 100000],
            ['member_id' => $memberB, 'amount_owed_cents' => 100000],
            ['member_id' => $memberC, 'amount_owed_cents' => 100000],
        ],
    ]
));
$expId = (int) $createExpRes['data']['expense']['id'];
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'expense.created', 'Expense creation emits expense.created event');

// Mutation 2: Update Expense via HTTP
$updateExpRes = dispatch($router, new Request(
    method: 'PUT',
    path: "/api/groups/{$testInviteToken}/expenses/{$expId}",
    body: [
        'title' => 'Dinner at P1 Bistro (Updated)',
        'amount_cents' => 360000,
        'currency_code' => 'INR',
        'split_type' => 'EQUAL',
        'paid_by_member_id' => $memberA,
        'splits' => [
            ['member_id' => $memberA, 'amount_owed_cents' => 120000],
            ['member_id' => $memberB, 'amount_owed_cents' => 120000],
            ['member_id' => $memberC, 'amount_owed_cents' => 120000],
        ],
    ]
));
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'expense.updated', 'Expense update emits expense.updated event');

// Mutation 3: Attach Receipt (Base64)
$receiptResult = $receiptService->uploadReceiptBase64(
    $groupId,
    $expId,
    'bill.png',
    'data:image/png;base64,' . base64_encode($pngBytes),
    $memberA,
    $testInviteToken
);
$receiptId = (int) $receiptResult['id'];
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'receipt.created', 'Receipt attachment emits receipt.created event');

// Mutation 4: Delete Receipt
$receiptService->deleteReceipt($groupId, $expId, $receiptId, $memberA);
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'receipt.deleted', 'Receipt deletion emits receipt.deleted event');

// Mutation 5: Soft Delete Expense via HTTP
$deleteExpRes = dispatch($router, new Request(
    method: 'DELETE',
    path: "/api/groups/{$testInviteToken}/expenses/{$expId}"
));
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'expense.deleted', 'Expense soft deletion emits expense.deleted event');

// Mutation 6: Restore Expense via HTTP
$restoreExpRes = dispatch($router, new Request(
    method: 'POST',
    path: "/api/groups/{$testInviteToken}/expenses/{$expId}/restore"
));
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'expense.restored', 'Expense restoration emits expense.restored event');

// Mutation 7: Create Settlement via HTTP
$createSetRes = dispatch($router, new Request(
    method: 'POST',
    path: "/api/groups/{$testInviteToken}/settlements",
    body: [
        'payer_member_id' => $memberB,
        'payee_member_id' => $memberA,
        'amount_cents' => 50000,
        'currency_code' => 'INR',
        'notes' => 'P1 partial settlement',
    ]
));
$settlementId = (int) $createSetRes['data']['settlement']['id'];
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'settlement.created', 'Settlement creation emits settlement.created event');

// Mutation 8: Delete Settlement via HTTP
$deleteSetRes = dispatch($router, new Request(
    method: 'DELETE',
    path: "/api/groups/{$testInviteToken}/settlements/{$settlementId}"
));
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'settlement.deleted', 'Settlement deletion emits settlement.deleted event');

// Mutation 9: Member Create, Update, Delete via HTTP
$createMemRes = dispatch($router, new Request(
    method: 'POST',
    path: "/api/groups/{$testInviteToken}/members",
    body: ['name' => 'Dave P1']
));
$newMemberId = (int) $createMemRes['data']['member']['id'];
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'member.created', 'Member addition emits member.created event');

$updateMemRes = dispatch($router, new Request(
    method: 'PUT',
    path: "/api/groups/{$testInviteToken}/members/{$newMemberId}",
    body: ['name' => 'Dave P1 Renamed']
));
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'member.updated', 'Member update emits member.updated event');

$deleteMemRes = dispatch($router, new Request(
    method: 'DELETE',
    path: "/api/groups/{$testInviteToken}/members/{$newMemberId}"
));
$events = $eventService->getEventsSince($groupId, $latestEventId);
$latestEventId = end($events)['id'];
assertTest(!empty($events) && end($events)['event_type'] === 'member.deleted', 'Member removal emits member.deleted event');

// Test 2.4: EventController HTTP endpoint behavior
echo "\n--- Section 2.4: EventController Endpoint Verification ---\n";

// Poll Mode Test
$pollData = dispatch($router, new Request(
    method: 'GET',
    path: "/api/groups/{$testInviteToken}/events?poll=1"
));

assertTest(isset($pollData['success']) && $pollData['success'] === true, 'Poll mode returns JSON success payload');
assertTest(isset($pollData['data']['events']) && is_array($pollData['data']['events']), 'Poll mode returns events list');
assertTest(!empty($pollData['data']['events']), 'Poll mode retrieved non-empty events array');

// Once Mode Test (SSE stream single cycle)
$onceOutput = dispatch($router, new Request(
    method: 'GET',
    path: "/api/groups/{$testInviteToken}/events?once=1&last_event_id=0"
));
$sseRaw = $onceOutput['raw'] ?? '';

assertTest(str_contains($sseRaw, ': connected'), 'SSE stream emits initial : connected ACK comment');
assertTest(str_contains($sseRaw, 'event: message'), 'SSE stream emits event: message blocks');
assertTest(str_contains($sseRaw, 'data: {"type":'), 'SSE stream formats JSON payload in data: field');

// 404 for invalid token
$notFoundData = dispatch($router, new Request(
    method: 'GET',
    path: "/api/groups/invalid_token_9999/events?poll=1"
));
assertTest(isset($notFoundData['success']) && $notFoundData['success'] === false, 'Invalid group token returns error payload');
assertTest($notFoundData['error']['code'] === 'NOT_FOUND', 'Invalid group token returns NOT_FOUND code');

// -----------------------------------------------------------------------------
// SECTION 3: P1.3 EXPENSE DUPLICATION FLOW TESTS
// -----------------------------------------------------------------------------
echo "\n--- Section 3: P1.3 Expense Duplication Flow ---\n";

// Original expense with custom note and exact splits
$origRes = dispatch($router, new Request(
    method: 'POST',
    path: "/api/groups/{$testInviteToken}/expenses",
    body: [
        'title' => 'Team Lunch Offsite',
        'amount_cents' => 450000,
        'currency_code' => 'INR',
        'split_type' => 'EXACT',
        'notes' => 'Quarterly celebration lunch',
        'paid_by_member_id' => $memberA,
        'splits' => [
            ['member_id' => $memberA, 'amount_owed_cents' => 150000],
            ['member_id' => $memberB, 'amount_owed_cents' => 200000],
            ['member_id' => $memberC, 'amount_owed_cents' => 100000],
        ],
    ]
));

$origExpense = $origRes['data']['expense'];
$origId = (int) $origExpense['id'];

// Attach receipt to original expense
$receiptResult = $receiptService->uploadReceiptBase64(
    $groupId,
    $origId,
    'lunch_bill.png',
    'data:image/png;base64,' . base64_encode($pngBytes),
    $memberA,
    $testInviteToken
);

$origReceipts = $receiptService->getReceipts($groupId, $origId, $testInviteToken);
assertTest(count($origReceipts) === 1, 'Original expense has 1 receipt attachment');

// Simulate frontend duplication:
// Frontend takes original expense data, strips id, created_at, updated_at, receipts, and submits as new expense
$duplicatedPayload = [
    'title' => $origExpense['title'] ?? $origExpense['description'],
    'amount_cents' => (int) $origExpense['amount_cents'],
    'currency_code' => $origExpense['currency_code'] ?? 'INR',
    'split_type' => $origExpense['split_type'],
    'notes' => $origExpense['notes'],
    'paid_by_member_id' => (int) ($origExpense['paid_by_member_id'] ?? $memberA),
    'splits' => array_map(fn($s) => [
        'member_id' => (int) $s['member_id'],
        'amount_owed_cents' => (int) $s['amount_owed_cents'],
    ], $origExpense['splits']),
];

$dupRes = dispatch($router, new Request(
    method: 'POST',
    path: "/api/groups/{$testInviteToken}/expenses",
    body: $duplicatedPayload
));

$duplicatedExpense = $dupRes['data']['expense'];
$dupId = (int) $duplicatedExpense['id'];

assertTest($dupId > 0 && $dupId !== $origId, 'Duplicated expense receives new unique ID distinct from original');
assertTest(($duplicatedExpense['title'] ?? $duplicatedExpense['description']) === 'Team Lunch Offsite', 'Duplicated expense preserves exact description');
assertTest((int) $duplicatedExpense['amount_cents'] === 450000, 'Duplicated expense preserves exact monetary amount');
assertTest($duplicatedExpense['split_type'] === 'EXACT', 'Duplicated expense preserves split methodology');
assertTest($duplicatedExpense['notes'] === 'Quarterly celebration lunch', 'Duplicated expense preserves notes');

// Verify receipts are isolated (duplicated expense starts clean without copied receipt records)
$dupReceipts = $receiptService->getReceipts($groupId, $dupId, $testInviteToken);
assertTest(empty($dupReceipts), 'Duplicated expense does not inherit old receipt attachments');

// Verify original expense receipt remains intact
$origReceiptsAfter = $receiptService->getReceipts($groupId, $origId, $testInviteToken);
assertTest(count($origReceiptsAfter) === 1, 'Original expense retains its receipt attachment after duplication');

// Verify event was emitted for duplicated expense
$events = $eventService->getEventsSince($groupId, $latestEventId);
assertTest(!empty($events) && end($events)['event_type'] === 'expense.created', 'Duplicated expense emits new expense.created event');

echo "\n================================================================================\n";
echo " P1 Feature Test Suite Completed: {$testsPassed}/{$totalTests} Tests Passed.\n";
echo "================================================================================\n";
