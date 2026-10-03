<?php

declare(strict_types=1);

echo "\n================================================================================\n";
echo " SMART SPLIT V2: MASTER AUTOMATED TEST SUITE RUNNER\n";
echo "================================================================================\n\n";

$phpBinary = PHP_BINARY;
if (empty($phpBinary) || !file_exists($phpBinary) || basename($phpBinary) === 'php-cgi.exe') {
    if (file_exists('C:\\xampp\\php\\php.exe')) {
        $phpBinary = 'C:\\xampp\\php\\php.exe';
    }
}

// 1. Ensure Local Test Server is Reachable (Port 8000)
$serverStartedByRunner = false;
$serverProcess = null;

$fp = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 0.5);
if ($fp) {
    fclose($fp);
} else {
    // Start local dev server in background
    $publicDir = dirname(__DIR__) . '/public';
    $indexFile = $publicDir . '/index.php';
    $cmd = sprintf('"%s" -S 127.0.0.1:8000 -t "%s" "%s"', $phpBinary, $publicDir, $indexFile);
    
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $serverProcess = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (is_resource($serverProcess)) {
        $serverStartedByRunner = true;
        usleep(300000); // 300ms warm-up
    }
}

if ($serverStartedByRunner && is_resource($serverProcess)) {
    register_shutdown_function(function () use ($serverProcess) {
        if (is_resource($serverProcess)) {
            proc_terminate($serverProcess);
            proc_close($serverProcess);
        }
    });
}

$phpTests = [
    'test_schema.php' => 'Database Schema & Foreign Key Constraints',
    'test_math.php' => 'Integer Paise Math, Hare-Niemeyer & Allocations',
    'test_financial_invariants_deep.php' => 'Deep Financial Mathematical Invariants & Conservation Suite',
    'test_router.php' => 'REST Router Pipeline & Route Matching',
    'test_router_trash.php' => 'Trash & Restore Route Registration',
    'test_groups_api.php' => 'Groups & Workspace Lifecycle API',
    'test_expenses_api.php' => 'Expenses Core CRUD & Allocations API',
    'test_trash_restore_api.php' => 'Trash & 1-Click Soft Delete Restore API',
    'test_balances_api.php' => 'Balances & Zero-Sum Invariant Engine API',
    'test_settlement_engine.php' => 'Greedy Min-Cash-Flow Debt Simplification',
    'test_settlements_api.php' => 'Settlements Recording & Reversal API',
    'test_server_live.php' => 'Live PHP Server Dispatch & Health API',
    'test_e2e_flow.php' => 'End-to-End User Flow Execution',
    'test_step6_itemized_api.php' => 'Step 6: Itemized Receipt & Surcharge Engine',
    'test_step7_recurring_templates.php' => 'Step 7: Recurring Scheduler & Templates Engine',
    'test_step8_search_filtering.php' => 'Step 8: Multi-Dimensional Search & Filtering',
    'test_step9_custom_categories.php' => 'Step 9: Custom Categories & Metadata Taxonomy',
    'test_step10_analytics_svg.php' => 'Step 10: Financial Intelligence & SVG Analytics',
    'test_step11_activity_timeline.php' => 'Step 11: Activity Timeline & Audit History',
    'test_step12_upi_qr.php' => 'Step 12: Dynamic UPI QR Code & Settlement Router',
    'test_step14_multi_currency.php' => 'Step 14: Multi-Currency Engine & Exchange Rates',
    'test_step15_receipt_attachments.php' => 'Step 15: Receipt Attachments & File Uploads',
    'test_sec04_csv_injection.php' => 'SEC-04: CSV Formula Injection & Spreadsheet Sanitization Suite',
    'test_sec05_csp_headers.php' => 'SEC-05: Content Security Policy Hardening & Injection Defense Suite',
    'test_sec06_error_disclosure.php' => 'SEC-06: Error & Stack Trace Disclosure Prevention Suite',
    'test_sec07_rate_limiting.php' => 'SEC-07: Persistent IP Rate Limiting & Abuse Prevention Suite',
    'test_sec08_trusted_proxies.php' => 'SEC-08: Trusted Reverse Proxy Resolution & Anti-Spoofing Suite',
    'test_sec09_bola_idor_matrix.php' => 'SEC-09: Comprehensive BOLA / IDOR Cross-Workspace Authorization Matrix',
    'test_sec10_concurrency_race_matrix.php' => 'SEC-10: True Concurrent HTTP Race Stress & Financial Integrity Matrix',
    'test_sec11_financial_lifecycle_state_machine.php' => 'SEC-11: Multi-Step Financial Lifecycle State-Machine & Invariant Suite',
    'test_sec12_migration_backup_restore.php' => 'SEC-12: Database Migration, Upgrade, Backup & Restore Integrity Suite',
    'test_sec13_session_invalidation.php' => 'SEC-13: Authentication Session Invalidation & Password Recovery Security Suite',
    'test_sec14_recurring_calendar.php' => 'SEC-14: Recurring Scheduler Calendar, Date/Time & Timezone Integrity Suite',
    'test_sec15_api_contract.php' => 'SEC-15: API Contract Integrity & Boundary Validation Suite',
    'test_sec15_alias_dispatch.php' => 'SEC-15: Independent Alias Route Dispatch & Verification Suite',
    'test_sec16_remediation.php' => 'SEC-16: Environment, Configuration & Secret Safety Post-Remediation Suite',
    'test_v2_features.php' => 'V2 Features Aggregation (In-Place Edit, CSV, Analytics)',
    'adversarial_round2_suite.php' => 'Adversarial Security & Cyclic Debt Stress',
    'comprehensive_e2e_audit.php' => 'Comprehensive E2E Security & Concurrency Audit',
    'test_adversarial_deep.php' => 'Deep Adversarial Boundary & Injection Suite',
    'test_hybrid_auth_complete.php' => 'Hybrid Progressive Authentication & System Invariance Suite',
    'test_delete_workspace_and_member.php' => 'Workspace & Member Deletion Lifecycle & Invariants',
    'test_p0_creator_pairing.php' => 'P0.3: Secure Guest Creator Device Pairing & Replay Protection',
    'test_p1_features.php' => 'P1: Real-Time SSE, Storage Abstraction & Expense Duplication',
    'test_p3_remediations.php' => 'P3: Server Idempotency, Deduplication & Error Resilience',
    'test_performance_scale_benchmark.php' => 'Scale & Performance Benchmark (10, 100, 500, 1000 expenses)',
    'test_adv_remediation.php' => 'ADV-01 & ADV-02: Surgical Remediation & Re-Attack Suite',
    'test_gbk_remediation.php' => 'GBK-01 & GBK-02: Recurring & Template Deletion Remediation Suite',
    'test_step16_settlement_lifecycle.php' => 'Step 16: Role-Aware Settlement Lifecycle, Attribution & Reversal Suite',
    'test_personal_upi_identity.php' => 'Personal UPI Identity, Profile CRUD & Settlement Resolution',
    'master_pre_release_validation.php' => 'Final Master Pre-Release & Stress Matrix',
];

$nodeTests = [
    'test_client_core.mjs' => 'Client Math, Reactive Store & State Engine',
    'test_frontend_financial_invariants.mjs' => 'Frontend Financial Invariants & Client Utilities',
    'test_step8_client_search.mjs' => 'Client Multi-Dimensional Search Predicates',
    'test_step10_client_svg.mjs' => 'Client Pure SVG Donut Ring & Histogram Engine',
    'test_step11_client_timeline.mjs' => 'Client Relative Time Formatters & Narrative',
    'test_step12_client_qr.mjs' => 'Client Pure Vector SVG ISO/IEC 18004 QR Matrix',
    'test_step13_expense_modal.mjs' => 'Client Expense Modal Allocation Calculations',
    'test_step13_mobile_ux.mjs' => 'Client Mobile Touch Targets & Workspaces Hub',
    'test_step14_client_currency.mjs' => 'Client Multi-Currency & FX Exchange Utilities',
    'test_step15_client_receipts.mjs' => 'Client Receipt Lightbox & File Utilities',
    'test_custom_avatars.mjs' => 'Distinctive Custom Member Avatars & Palettes',
    'test_budget_target.mjs' => 'Workspace Spending Budget Target & Progress Bar',
    'test_copy_sheets.mjs' => '1-Click Copy for Google Sheets / Excel (TSV)',
    'test_debt_flowchart.mjs' => 'Interactive SVG Debt Flowchart Diagram',
    'test_hashtag_support.mjs' => 'Hashtags Support & Ledger Filter Navigation',
    'test_keyboard_shortcuts.mjs' => 'Power-User Global Keyboard Shortcuts',
    'test_multi_workspace_summary.mjs' => 'Consolidated Multi-Workspace Summary',
    'test_pwa_configuration.mjs' => 'PWA Service Worker & Offline Caching Engine',
    'test_share_intents.mjs' => 'Web Share & WhatsApp Payment Intents',
    'test_trash_restore.mjs' => 'Trash Bin & 1-Click Restore Client Workflow',
    'test_utr_tracking.mjs' => 'Settlement UTR & Bank Reference Tracking',
    'test_whatif_balance.mjs' => 'Live What-If Balance Impact Preview Engine',
    'test_settlement_view_persistence.mjs' => 'Settlement Router View State Persistence & ARIA Navigation',
    'test_p0_features.mjs' => 'P0 Progressive Split, Debt Nudges & Device Pairing',
    'test_p1_features.mjs' => 'P1 Real-Time SSE, Storage & Expense Duplication',
    'test_p3_features.mjs' => 'P3 Offline Outbox Sync, PDF Reports & Spending Budget',
    'test_settings_view.mjs' => 'Dedicated Settings View & Preferences Engine',
];

$totalSuites = count($phpTests) + count($nodeTests);
$suitesPassed = 0;
$suitesFailed = 0;
$startTime = microtime(true);

require_once dirname(__DIR__) . '/src/Core/Env.php';
require_once dirname(__DIR__) . '/src/Core/Database.php';
\App\Core\Env::load(dirname(__DIR__) . '/.env');
try {
    $runnerPdo = \App\Core\Database::getConnection();
} catch (\Throwable $e) {
    $runnerPdo = null;
}

$currentSuiteIndex = 0;

foreach ($phpTests as $file => $description) {
    $currentSuiteIndex++;
    $filePath = __DIR__ . '/' . $file;
    if (!file_exists($filePath)) {
        echo "  [SKIP] [{$currentSuiteIndex}/{$totalSuites}] {$file} (File not found)\n";
        continue;
    }

    if ($runnerPdo) {
        try {
            $runnerPdo->exec("DELETE FROM `rate_limits`");
        } catch (\Throwable $e) {}
    }

    echo "  [RUNNING] [{$currentSuiteIndex}/{$totalSuites}] {$file} - {$description}...";
    flush();

    $suiteStart = microtime(true);
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($filePath);
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    $suiteDuration = round(microtime(true) - $suiteStart, 2);

    if ($returnCode === 0) {
        echo "\r  [PASS] [{$currentSuiteIndex}/{$totalSuites}] {$file} - {$description} ({$suiteDuration}s)       \n";
        flush();
        $suitesPassed++;
    } else {
        echo "\r  [FAIL] [{$currentSuiteIndex}/{$totalSuites}] {$file} - {$description} (Exit code: {$returnCode}, {$suiteDuration}s)\n";
        $suitesFailed++;
        // Print snippet of error
        $tail = array_slice($output, -10);
        foreach ($tail as $l) {
            echo "         > {$l}\n";
        }
        flush();
    }
}

echo "\n--- Running Vanilla ES6 Node.js Frontend Test Suites (" . count($nodeTests) . ") ---\n\n";

foreach ($nodeTests as $file => $description) {
    $currentSuiteIndex++;
    $filePath = __DIR__ . '/' . $file;
    if (!file_exists($filePath)) {
        echo "  [SKIP] [{$currentSuiteIndex}/{$totalSuites}] {$file} (File not found)\n";
        continue;
    }

    echo "  [RUNNING] [{$currentSuiteIndex}/{$totalSuites}] {$file} - {$description}...";
    flush();

    $suiteStart = microtime(true);
    $cmd = 'node ' . escapeshellarg($filePath);
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    $suiteDuration = round(microtime(true) - $suiteStart, 2);

    if ($returnCode === 0) {
        echo "\r  [PASS] [{$currentSuiteIndex}/{$totalSuites}] {$file} - {$description} ({$suiteDuration}s)       \n";
        flush();
        $suitesPassed++;
    } else {
        echo "\r  [FAIL] [{$currentSuiteIndex}/{$totalSuites}] {$file} - {$description} (Exit code: {$returnCode}, {$suiteDuration}s)\n";
        $suitesFailed++;
        $tail = array_slice($output, -10);
        foreach ($tail as $l) {
            echo "         > {$l}\n";
        }
        flush();
    }
}

$elapsed = round(microtime(true) - $startTime, 2);

echo "\n================================================================================\n";
echo " MASTER TEST RUNNER SUMMARY\n";
echo "================================================================================\n";
echo " Total Test Suites Executed: {$totalSuites}\n";
echo " Passed Suites:              {$suitesPassed}\n";
echo " Failed Suites:              {$suitesFailed}\n";
echo " Total Execution Time:       {$elapsed}s\n";
echo " Success Rate:               " . round(($suitesPassed / max(1, $totalSuites)) * 100, 1) . "%\n";
echo "================================================================================\n\n";

exit($suitesFailed > 0 ? 1 : 0);
