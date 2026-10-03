/**
 * Smart Split – Frontend Test Suites Runner
 * Executes all client-side unit, DOM-less formatting, QR, SVG chart, and reactive state suites.
 */

import { spawnSync } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';
import fs from 'fs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

console.log('\n================================================================================');
console.log(' SMART SPLIT: FRONTEND ES6 SUITES RUNNER');
console.log('================================================================================\n');

const testSuites = [
    { file: 'test_client_core.mjs', desc: 'Client Math, Reactive Store & State Engine' },
    { file: 'test_frontend_financial_invariants.mjs', desc: 'Frontend Financial Invariants & Client Utilities' },
    { file: 'test_step8_client_search.mjs', desc: 'Client Multi-Dimensional Search Predicates' },
    { file: 'test_step10_client_svg.mjs', desc: 'Client Pure SVG Donut Ring & Histogram Engine' },
    { file: 'test_step11_client_timeline.mjs', desc: 'Client Relative Time Formatters & Narrative' },
    { file: 'test_step12_client_qr.mjs', desc: 'Client Pure Vector SVG ISO/IEC 18004 QR Matrix' },
    { file: 'test_step13_expense_modal.mjs', desc: 'Client Expense Modal Allocation Calculations' },
    { file: 'test_step13_mobile_ux.mjs', desc: 'Client Mobile Touch Targets & Workspaces Hub' },
    { file: 'test_step14_client_currency.mjs', desc: 'Client Multi-Currency & FX Exchange Utilities' },
    { file: 'test_step15_client_receipts.mjs', desc: 'Client Receipt Lightbox & File Utilities' },
    { file: 'test_custom_avatars.mjs', desc: 'Distinctive Custom Member Avatars & Palettes' },
    { file: 'test_budget_target.mjs', desc: 'Workspace Spending Budget Target & Progress Bar' },
    { file: 'test_copy_sheets.mjs', desc: '1-Click Copy for Google Sheets / Excel (TSV)' },
    { file: 'test_debt_flowchart.mjs', desc: 'Interactive SVG Debt Flowchart Diagram' },
    { file: 'test_hashtag_support.mjs', desc: 'Hashtags Support & Ledger Filter Navigation' },
    { file: 'test_keyboard_shortcuts.mjs', desc: 'Power-User Global Keyboard Shortcuts' },
    { file: 'test_multi_workspace_summary.mjs', desc: 'Consolidated Multi-Workspace Summary' },
    { file: 'test_pwa_configuration.mjs', desc: 'PWA Service Worker & Offline Caching Engine' },
    { file: 'test_share_intents.mjs', desc: 'Web Share & WhatsApp Payment Intents' },
    { file: 'test_trash_restore.mjs', desc: 'Trash Bin & 1-Click Restore Client Workflow' },
    { file: 'test_utr_tracking.mjs', desc: 'Settlement UTR & Bank Reference Tracking' },
    { file: 'test_whatif_balance.mjs', desc: 'Live What-If Balance Impact Preview Engine' },
    { file: 'test_settlement_view_persistence.mjs', desc: 'Settlement Router View State Persistence & ARIA Navigation' },
    { file: 'test_p0_features.mjs', desc: 'P0 Progressive Split, Debt Nudges & Device Pairing' },
    { file: 'test_p1_features.mjs', desc: 'P1 Real-Time SSE, Storage & Expense Duplication' },
    { file: 'test_p3_features.mjs', desc: 'P3 Offline Outbox Sync, PDF Reports & Spending Budget' },
    { file: 'test_settings_view.mjs', desc: 'Dedicated Settings View & Preferences Engine' },
];

let passed = 0;
let failed = 0;
const startTime = Date.now();

for (const suite of testSuites) {
    const fullPath = join(__dirname, suite.file);
    if (!fs.existsSync(fullPath)) {
        console.log(`  [SKIP] ${suite.file} (File not found)`);
        continue;
    }

    const result = spawnSync(process.execPath, [fullPath], {
        encoding: 'utf-8',
        stdio: 'pipe',
    });

    if (result.status === 0) {
        console.log(`  [PASS] ${suite.file} - ${suite.desc}`);
        passed++;
    } else {
        console.log(`  [FAIL] ${suite.file} - ${suite.desc} (Exit code: ${result.status})`);
        failed++;
        if (result.stderr) {
            console.log(`         STDERR: ${result.stderr.trim().split('\n').slice(-3).join('\n         ')}`);
        }
    }
}

const elapsedSec = ((Date.now() - startTime) / 1000).toFixed(2);

console.log('\n================================================================================');
console.log(' FRONTEND RUNNER SUMMARY');
console.log('================================================================================');
console.log(` Total Suites:  ${testSuites.length}`);
console.log(` Passed:        ${passed}`);
console.log(` Failed:        ${failed}`);
console.log(` Duration:      ${elapsedSec}s`);
console.log(` Success Rate:  ${((passed / Math.max(1, testSuites.length)) * 100).toFixed(1)}%`);
console.log('================================================================================\n');

if (failed > 0) {
    process.exit(1);
}
