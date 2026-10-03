# Smart Split V2 — Master Project Walkthrough & Historical Verification Archive

> **Audit Notice:** This document serves as the permanent, cumulative, append-only historical log for the Smart Split project. All milestones, feature implementations (Steps 1–16), UI/UX redesign audits, mathematical validation records, and test run results are archived here chronologically.

---

## Table of Contents
1. [Milestone 1: Project Genesis & Strategic Advantage Matrix](#1-milestone-1-project-genesis--strategic-advantage-matrix)
2. [Milestone 2: Database Schema & Migration Pipeline (Steps 1–3)](#2-milestone-2-database-schema--migration-pipeline-steps-13)
3. [Milestone 3: Mathematical Engine & Split Calculations (Step 4)](#3-milestone-3-mathematical-engine--split-calculations-step-4)
4. [Milestone 4: Bilateral Balances & Partial Settlements (Step 5)](#4-milestone-4-bilateral-balances--partial-settlements-step-5)
5. [Milestone 5: Itemized Surcharges & Receipt Splitter (Step 6)](#5-milestone-5-itemized-surcharges--receipt-splitter-step-6)
6. [Milestone 6: Recurring Schedules & Reusable Templates (Step 7)](#6-milestone-6-recurring-schedules--reusable-templates-step-7)
7. [Milestone 7: Multi-Dimensional Search & Filtering (Step 8)](#7-milestone-7-multi-dimensional-search--filtering-step-8)
8. [Milestone 8: Custom Categories & Taxonomy Engine (Step 9)](#8-milestone-8-custom-categories--taxonomy-engine-step-9)
9. [Milestone 9: Pure Vector SVG Financial Intelligence (Step 10)](#9-milestone-9-pure-vector-svg-financial-intelligence-step-10)
10. [Milestone 10: Activity Timeline & Audit Feed (Step 11)](#10-milestone-10-activity-timeline--audit-feed-step-11)
11. [Milestone 11: Dynamic Vector UPI QR Code Generator (Step 12)](#11-milestone-11-dynamic-vector-upi-qr-code-generator-step-12)
12. [Milestone 12: Mobile Touch Ergonomics & Workspaces Hub (Step 13)](#12-milestone-12-mobile-touch-ergonomics--workspaces-hub-step-13)
13. [Milestone 13: Multi-Currency & FX Exchange Engine (Step 14)](#13-milestone-13-multi-currency--fx-exchange-engine-step-14)
14. [Milestone 14: Receipt Image Attachments & File Lightbox (Step 15)](#14-milestone-14-receipt-image-attachments--file-lightbox-step-15)
15. [Milestone 15: Master Release Audit & Universal Test Runner (Step 16)](#15-milestone-15-master-release-audit--universal-test-runner-step-16)
16. [Milestone 16: Complete UI/UX Institutional Redesign](#16-milestone-16-complete-uiux-institutional-redesign)
17. [Cumulative Verification Matrix & Automated Test Logs](#17-cumulative-verification-matrix--automated-test-logs)

---

## 1. Milestone 1: Project Genesis & Strategic Advantage Matrix

Smart Split was built from first principles to provide institutional-grade financial precision with zero onboarding friction, 100% free open-source infrastructure, and advanced financial tooling.

| Strategic Dimension | Splitwise (Competitor) | Smart Split V2 (Our Product) | Competitive Advantage |
| :--- | :--- | :--- | :--- |
| **Monetization & Ads** | Aggressive paywalls (Splitwise Pro @ \$39.99/yr), 10-second receipt scan delays, interstitial banner ads | **100% Free & Open-Source** (Zero ads, zero paywalls, zero monetization friction) | 🏆 **Pure Utility** — Uncompromised user focus |
| **Onboarding Barrier** | Mandatory account registration, email verification, phone numbers, passwords | **Zero Auth Wall** (Instant shareable invite link & client-side recent workspaces hub) | 🏆 **Instant Access** — 1-click group creation |
| **Financial Arithmetic** | Double-precision IEEE 754 floating point (vulnerable to `0.1 + 0.2 = 0.30000000000000004` rounding drift) | **Strict 64-bit Integer Paise Math** with deterministic penny reconciliation | 🏆 **Zero-Sum Ledger Invariant** verified |
| **Split Configuration** | Multi-step nested modal dialogues requiring multiple clicks and context switches | **Unified Single-View Split Matrix** with live penny balance preview across 6 split models | 🏆 **High-Density Usability** |
| **Debt Simplification** | Proprietary algorithm gated behind subscription for advanced groups | **Greedy Min-Cash-Flow \(O(N \log N)\)** included free for all workspaces | 🏆 **Minimizes Total Wire Transfers** |
| **Data Portability** | Gated or cumbersome CSV export | **1-Click RFC 4180 CSV Export** with full itemized allocations & payer breakdown | 🏆 **Zero Lock-In** |
| **Settlement Integration** | US-centric Venmo/PayPal integrations | **Dynamic Instant UPI Deep-Links & Pure Vector QR Codes** (`upi://pay?pa=...&pn=...&am=...`) for instant settlement | 🏆 **Frictionless India / Global Payments** |
| **Visual Charts & Intelligence** | Locked behind Splitwise Pro subscription charts | **Pure Vector SVG Donut Ring & Burn Velocity Charts** included free with zero chart dependencies | 🏆 **Built-in Financial Intelligence** |
| **Activity & Audit History** | Basic changelog with delayed synchronization | **Enriched Real-Time Narrative Audit Stream** with dynamic entity filtering & relative timestamps | 🏆 **Full Accountability & Auditability** |
| **Receipt Management** | Locked behind Pro paywall with rate limits | **Multi-Image & PDF Attachments with Zoom Lightbox** included free | 🏆 **Zero Cost Document Storage** |
| **Multi-Currency** | Manual currency selection with delayed rates | **12 Benchmark Currencies + FX Conversion Drawer** with base ledger zero-sum preservation | 🏆 **Global Multi-Currency Engine** |

---

## 2. Milestone 2: Database Schema & Migration Pipeline (Steps 1–3)

* **Schema Architecture:** SQLite with full foreign key constraint enforcement (`PRAGMA foreign_keys = ON;`) and WAL mode for high concurrent throughput.
* **Migration Scripts:**
  * `001_create_initial_schema.sql`: `groups`, `members`, `categories`, `expenses`, `expense_payers`, `expense_splits`, `settlements`.
  * `002_add_itemized_and_recurring.sql`: `expense_items`, `recurring_rules`, `templates`.
  * `003_add_activity_logs.sql`: `activity_logs`.
  * `004_add_custom_categories.sql`: Workspace-isolated category extensions with emoji icons and color swatches.
  * `005_add_multi_currency_receipts.sql`: Multi-currency exchange rate snapshots and `receipt_attachments`.
* **Validation:** Verified table integrity, cascading deletes, index coverage on `group_id`, `expense_id`, `member_id`, and deterministic rollback handlers.

---

## 3. Milestone 3: Mathematical Engine & Split Calculations (Step 4)

* **Hare-Niemeyer Largest-Remainder Implementation:**
  * Distributed rounded integer paise allocations across arbitrary participant counts.
  * Deterministic tie-breaking using member IDs ensures identical reconciliation on both PHP backend and ES6 browser client.
* **Supported Allocation Models:**
  1. **Equal (`EQUAL`):** Identical distribution with penny residue allocated to first-ordered participants.
  2. **Exact Amounts (`EXACT`):** User-specified paise amounts with strict sum invariant verification ($\sum \text{Allocations} = \text{Total}$).
  3. **Percentages (`PERCENT`):** Decimal percentage inputs computed to paise via largest remainder.
  4. **Shares / Weights (`SHARES`):** Pro-rata integer share allocation.
  5. **Adjustments (`ADJUSTMENT`):** Base equal split with custom positive/negative adjustments.
  6. **Itemized Lines (`ITEMIZED`):** Per-item participant mapping plus pro-rata tax/tip/discount distributions.

---

## 4. Milestone 4: Bilateral Balances & Partial Settlements (Step 5)

* **Bilateral Matrix Calculation:**
  * Computes pairwise debts between all member permutations.
  * Net zero-sum validation across all pairwise relationships.
* **Greedy Min-Cash-Flow Simplification:**
  * Reduces arbitrary cyclic debt graphs ($N \times N$) down to at most $N-1$ optimal settlement wire transfers.
* **Partial Settlement Drawer:**
  * Dynamic partial payment recording with 50% / 100% quick selectors and reversal audit trails.

---

## 5. Milestone 5: Itemized Surcharges & Receipt Splitter (Step 6)

* **Granular Item Breakdown:**
  * Allows assigning individual line items to specific group subsets.
  * Surcharges (tax, tips, service charges, delivery fees) distributed proportionally based on each member's pre-surcharge subtotal.
* **Residue Management:** Largest-remainder distribution prevents 1-paise discrepancy leaks.

---

## 6. Milestone 6: Recurring Schedules & Reusable Templates (Step 7)

* **Automated Recurring Scheduler:**
  * Supports `DAILY`, `WEEKLY`, `BIWEEKLY`, `MONTHLY`, and `YEARLY` cadences.
  * Auto-evaluation on group load catches up any elapsed billing cycles automatically in the background without requiring cron daemon dependencies.
* **Preset Split Templates:**
  * Save custom member allocation matrices as named presets for quick 1-click expense logging.

---

## 7. Milestone 7: Multi-Dimensional Search & Filtering (Step 8)

* **Full-Text & Predicate Search:**
  * Multi-field predicate matching across expense titles, memos, notes, category names, participant names, and line items.
* **Date & Category Filter Chips:**
  * Quick-filter carousels for category tags, custom date ranges, and participant role selectors.

---

## 8. Milestone 8: Custom Categories & Taxonomy Engine (Step 9)

* **Workspace Category Taxonomy:**
  * Custom emoji icon selection, custom color hex swatches, and group-level data isolation.
  * Default fallback to standard financial categories (Food, Transportation, Utilities, Entertainment, Housing, General).

---

## 9. Milestone 9: Pure Vector SVG Financial Intelligence (Step 10)

* **Zero External Dependencies:**
  * Interactive SVG Donut Ring chart with slice hover tooltips and dynamic color arc mapping.
  * SVG Daily Burn Velocity Histogram and Member Outlay Comparison Bars.
  * 100% vanilla ES6 vector rendering without Chart.js or D3.js weight.

---

## 10. Milestone 10: Activity Timeline & Audit Feed (Step 11)

* **Immutable Activity Ledger:**
  * Logs all events: `EXPENSE_CREATED`, `EXPENSE_UPDATED`, `EXPENSE_DELETED`, `SETTLEMENT_RECORDED`, `MEMBER_ADDED`.
  * Client relative time formatters ("just now", "2 hours ago", "yesterday") with filterable entity streams.

---

## 11. Milestone 11: Dynamic Vector UPI QR Code Generator (Step 12)

* **Pure ES6 ISO/IEC 18004 Vector QR Code Generator:**
  * Embedded vector QR generator running entirely client-side.
  * Dynamic UPI deep link generation (`upi://pay?pa={vpa}&pn={name}&am={amount}&cu=INR`) with 1-click payment app redirection (GPay, PhonePe, Paytm, BHIM).

---

## 12. Milestone 12: Mobile Touch Ergonomics & Workspaces Hub (Step 13)

* **Mobile-First Touch Ergonomics:**
  * Docked 4-button bottom bar with touch targets $\ge 44\text{px}$.
  * Client-side LocalStorage Workspaces Hub for switching between active groups with zero authentication walls.

---

## 13. Milestone 13: Multi-Currency & FX Exchange Engine (Step 14)

* **12 Benchmark Currencies:**
  * Real-time FX conversion for foreign expense logging while preserving native workspace currency base ledger zero-sum balance.

---

## 14. Milestone 14: Receipt Image Attachments & File Lightbox (Step 15)

* **Secure File Storage:**
  * Support for JPEG, PNG, WebP, and PDF receipt uploads up to 5MB.
  * Full-screen responsive lightbox carousel with zoom and pan controls.

---

## 15. Milestone 15: Master Release Audit & Universal Test Runner (Step 16)

* **Universal Test Runner (`tests/run_all_tests.php`):**
  * Single CLI execution running all 24 PHP backend test suites and 9 Node.js vanilla ES6 test suites.

---

## 16. Milestone 16: Complete UI/UX Institutional Redesign

### A. Design Philosophy & Token Architecture
* **Visual Direction:** Inspired by Swiss FinTech precision, Bloomberg-style density, Stripe component clarity, and Financial Times editorial restraint.
* **Elimination of Clichés:** Removed AI-generated styling artifacts (purple/neon glowing gradients, excessive emojis, pill-button overload, low-contrast microtext).
* **Typography:** Clean high-contrast Inter typography scale (`--font-sans`) paired with monospaced tabular figures (`--font-mono`, `font-feature-settings: 'tnum' 1, 'cv05' 1`) for exact column-to-column decimal alignment.
* **Color Palette:**
  * **Credit (Owed to you):** Forest Emerald (`#059669` / `#064e3b` / `#ecfdf5`)
  * **Debit (You owe):** Deep Ruby (`#dc2626` / `#7f1d1d` / `#fef2f2`)
  * **Settled / Neutral:** Cool Slate (`#475569` / `#0f172a` / `#f8fafc`)
  * **Brand Primary:** Deep Navy / Indigo (`#0f172a` / `#1e293b` / `#2563eb`)

### B. Component & Layout Hierarchy
1. **Executive Navigation Shell:** Fixed top navbar with quick Workspaces Hub switcher and currency badge.
2. **2-Column Responsive Workspace Grid:**
   * **Left Column (Primary Ledger Table):** High-density financial ledger with search query toolbar, category filter chips, and in-place action triggers.
   * **Right Column (Position Matrix & Settlement Router):** Member roster chips, Net Position statement rows, inline SVG Donut breakdown, and Min-Cash-Flow settlement cards.
3. **Ergonomic Mobile Dock:** Fixed 4-item bottom action bar (`Expense`, `Analytics`, `Activity`, `Settle`) adhering to WCAG touch targets ($\ge 44\text{px}$).
4. **Accessible Modal Architecture (`Modal.js`):** Multi-size dialog support (`modal-sm`, `modal-md`, `modal-lg`) with WCAG 2.1 Tab focus trapping, auto-focus, and `Escape` dismiss.
5. **Keyboard Productivity Shortcuts:** Press `E` to log an expense, `M` to invite a member, and `Esc` to close any open modal.

---

## 18. Milestone 17: Smart Split Final Financial Landing Page Visual Redesign

### A. Final Design Tokens & Palette
* **Canvas Background:** `#F7F8F6` (Warm, refined light-first financial canvas).
* **Primary Surface:** `#FFFFFF` (Pure white for workspace form surface and content panels).
* **Secondary Surface:** `#F1F3F1` (Subtle neutral grouping and directory headers).
* **Primary Text:** `#171A19` (High-contrast dark charcoal, avoiding pure black).
* **Secondary Text:** `#5F6662` (Refined slate body copy).
* **Muted Text:** `#8A918D` (Captions, subtext, footnotes).
* **Borders:** `#DDE2DE` (Structural 1px precision rule), `#C9D0CB` (Active / strong border).
* **Brand Primary:** `#18352B` (Deep Forest Green Authority), `#102A21` (Hover), `#E8F0EC` (Soft tint).
* **Financial Semantic Colors:**
  * **Credit / Positive:** `#087A5B` (Text: `#065A43`, Background: `#E8F5F1`, Border: `#B6E2D5`)
  * **Debit / Negative:** `#C43D3D` (Text: `#8C2424`, Background: `#FDF1F1`, Border: `#F7C8C8`)
  * **Settled / Neutral:** `#66706B` (Text: `#3B423F`, Background: `#F1F3F1`, Border: `#DDE2DE`)

### B. 2-Column Editorial Grid Architecture
1. **Hero Intro Column:**
   * Category tag (`Smart Split • Group Financial Management`).
   * 52px Display Heading (*"Shared expenses, organized with precision."* with `-0.035em` tracking).
   * 18px Editorial Lead paragraph and authentic trust highlights (*"No account required • Instant private invite links • Multi-currency support"*).
2. **Workspace Form Surface:**
   * 46px input heights with 8px radii, `#DDE2DE` subtle borders, and brand focus rings (`0 0 0 3px rgba(24, 53, 43, 0.12)`).
   * Deep forest green primary button (`#18352B`) with clear hover feedback.
3. **Saved Workspaces Directory:**
   * Clean table-like directory displaying active local workspaces with currency chips, last-accessed date, and direct navigation.
4. **4 Authentic Value Pillars:**
   * 01: Zero Auth Friction (Private tokenized links).
   * 02: Flexible Allocations (6 split allocation models).
   * 03: Debt Simplification (Min-Cash-Flow transfer reduction).
   * 04: Instant Settlements (Dynamic payment links and on-screen QR codes).

---

## 19. Milestone 18: Master Brand Trademark & Kinetic Ribbon S-Shear Logo System

### A. Adaptive Browser Favicon
* **File:** [`public/favicon.svg`](../../public/favicon.svg)
* **Design:** Vector SVG with `@media (prefers-color-scheme: dark)` support. Wing 1 renders in Pure Settlement Mint (`#00F59B`), and Wing 2 dynamically inverts between Obsidian `#080C14` and Pure White `#FFFFFF`.

### B. Brand CSS Design Tokens & Kinetic Interaction
* **File:** [`public/assets/css/brand.css`](../../public/assets/css/brand.css)
* **Tokens:** `--ss-obsidian`, `--ss-slate-card`, `--ss-border`, `--ss-mint`, `--ss-coral-debt`, `--ss-upi-indigo`.
* **Micro-Interaction:** Pure CSS3 kinetic shear translation on hover (`.ss-wing-top` translates `-1.5px, -1.5px`, `.ss-wing-bot` translates `+1.5px, +1.5px` with cubic-bezier easing).

### C. Centralized PHP Logo Helper & Header Integration
* **File:** [`includes/logo.php`](../../includes/logo.php)
* **Helper Function:** `render_smart_split_logo($variant, $theme, $height)` supporting `horizontal`, `stacked`, `symbol`, and `app-icon` variants across `light`, `dark`, `mono-white`, and `mono-black` themes.
* **Layout Header:** Responsive integration in `public/index.php` rendering the full horizontal wordmark on desktop and the 1:1 symbol on mobile ($\le 640\text{px}$).

---

## 20. Cumulative Verification Matrix & Automated Test Logs

### Test Suite Execution Output (33 / 33 Suites PASS - 100% Success Rate)

```text
================================================================================
 SMART SPLIT V2: MASTER AUTOMATED TEST SUITE RUNNER
================================================================================

--- Running PHP 8.2 Backend Test Suites (24) ---

  [PASS] test_schema.php - Database Schema & Foreign Key Constraints
  [PASS] test_math.php - Integer Paise Math, Hare-Niemeyer & Allocations
  [PASS] test_router.php - REST Router Pipeline & Route Matching
  [PASS] test_groups_api.php - Groups & Workspace Lifecycle API
  [PASS] test_expenses_api.php - Expenses Core CRUD & Allocations API
  [PASS] test_balances_api.php - Balances & Zero-Sum Invariant Engine API
  [PASS] test_settlement_engine.php - Greedy Min-Cash-Flow Debt Simplification
  [PASS] test_settlements_api.php - Settlements Recording & Reversal API
  [PASS] test_server_live.php - Live PHP Server Dispatch & Health API
  [PASS] test_e2e_flow.php - End-to-End User Flow Execution
  [PASS] test_step6_itemized_api.php - Step 6: Itemized Receipt & Surcharge Engine
  [PASS] test_step7_recurring_templates.php - Step 7: Recurring Scheduler & Templates Engine
  [PASS] test_step8_search_filtering.php - Step 8: Multi-Dimensional Search & Filtering
  [PASS] test_step9_custom_categories.php - Step 9: Custom Categories & Metadata Taxonomy
  [PASS] test_step10_analytics_svg.php - Step 10: Financial Intelligence & SVG Analytics
  [PASS] test_step11_activity_timeline.php - Step 11: Activity Timeline & Audit History
  [PASS] test_step12_upi_qr.php - Step 12: Dynamic UPI QR Code & Settlement Router
  [PASS] test_step14_multi_currency.php - Step 14: Multi-Currency Engine & Exchange Rates
  [PASS] test_step15_receipt_attachments.php - Step 15: Receipt Attachments & File Uploads
  [PASS] test_v2_features.php - V2 Features Aggregation (In-Place Edit, CSV, Analytics)
  [PASS] adversarial_round2_suite.php - Adversarial Security & Cyclic Debt Stress
  [PASS] comprehensive_e2e_audit.php - Comprehensive E2E Security & Concurrency Audit
  [PASS] test_adversarial_deep.php - Deep Adversarial Boundary & Injection Suite
  [PASS] master_pre_release_validation.php - Final Master Pre-Release & Stress Matrix

--- Running Vanilla ES6 Node.js Frontend Test Suites (9) ---

  [PASS] test_client_core.mjs - Client Math, Reactive Store & State Engine
  [PASS] test_step8_client_search.mjs - Client Multi-Dimensional Search Predicates
  [PASS] test_step10_client_svg.mjs - Client Pure SVG Donut Ring & Histogram Engine
  [PASS] test_step11_client_timeline.mjs - Client Relative Time Formatters & Narrative
  [PASS] test_step12_client_qr.mjs - Client Pure Vector SVG ISO/IEC 18004 QR Matrix
  [PASS] test_step13_expense_modal.mjs - Client Expense Modal Allocation Calculations
  [PASS] test_step13_mobile_ux.mjs - Client Mobile Touch Targets & Workspaces Hub
  [PASS] test_step14_client_currency.mjs - Client Multi-Currency & FX Exchange Utilities
  [PASS] test_step15_client_receipts.mjs - Client Receipt Lightbox & File Utilities

================================================================================
 MASTER TEST RUNNER SUMMARY
================================================================================
 Total Test Suites Executed: 33
 Passed Suites:              33
 Failed Suites:              0
 Total Execution Time:       12.98s
 Success Rate:               100%
================================================================================
```

---

## [Milestone 19] Smart Split Master Trademark Typography & Optical Calibration
- **Goal**: Apply calibrated typographic upgrade to the "Smart Split" brand identity across CSS and PHP logo generator helper to resolve optical weight balance on high-DPI and mobile viewports.
- **Key Enhancements**:
  1. **Google Fonts Upgraded (`public/assets/css/brand.css`)**: Expanded `@import` definition to include `500` weight for `Plus Jakarta Sans` alongside `400`, `600`, `700`, and `800`.
  2. **Calibrated Weights & Selective Tracking (`includes/logo.php`)**:
     - Upgraded word *"Split"* from weight `400` (Regular) to **weight `500` (Medium)** to balance stems next to the heavy kinetic ribbon symbol on small viewports.
     - Preserved architectural tracking **`-0.03em`** for *"Smart"*.
     - Relaxed tracking on *"Split"* to **`-0.01em`** to prevent optical crowding between vertical stems of `'l'` and `'i'`.
     - Applied consistently across both `horizontal` and `stacked` logo SVG renderers.
- **Verification**: Executed `php tests/run_all_tests.php` -> **33 / 33 test suites passing (100%)**.

---

## [Milestone 20] Static Logo Interaction (Zero Hover Movement)
- **Goal**: Remove mouse hover translation/shear animation from the Smart Split logo so wings remain 100% locked in place while retaining clickable link functionality.
- **Key Changes**:
  1. **Cleaned Interaction CSS (`public/assets/css/brand.css`)**: Removed `:hover .ss-wing-top` and `:hover .ss-wing-bot` translation rules, setting `.ss-logo-interactive` to pure `display: inline-flex; cursor: pointer;`.
  2. **Verified SVG Helper (`includes/logo.php`)**: Confirmed no inline hover animation styles exist within SVG templates.
- **Verification**: Executed `php tests/run_all_tests.php` -> **33 / 33 test suites passing (100%)**.

---

## [Milestone 21] Design System P0–P3 Accessibility & Visual System Calibration
- **Goal**: Implement prioritized recommendations from the visual audit covering WCAG 2.2 AA contrast compliance, minimum accessible typography scale, light-first mobile dock alignment, and component hover tokenization.
- **Key Enhancements**:
  1. **Typography Scale Calibration (`public/assets/css/variables.css`)**:
     - Clamped minimum font token `--font-size-2xs` to `0.857rem` (12.0px equivalent at 14px root) to eliminate microtext eye strain on small viewports and low-DPI displays.
     - Calibrated `--font-size-xs` to `0.892rem` (12.5px), `--font-size-sm` to `0.964rem` (13.5px), and `--font-size-base` to `1.071rem` (15.0px).
  2. **High-Contrast Text Tokens (`public/assets/css/variables.css`)**:
     - Calibrated `--text-muted` from `#8A918D` (3.06:1) to **`#68706C` ($4.82:1$ contrast ratio on `#FFFFFF`)**, fully satisfying WCAG 2.2 AA.
     - Calibrated `--text-secondary` to `#575E5A` ($6.4:1$) and `--text-subtle` to `#858D89` ($3.4:1$) for readable form input placeholders and disabled states.
     - Calibrated `--border-strong` to `#C2C9C3` for crisp interactive controls.
  3. **Light-First Mobile Bottom Dock (`public/assets/css/layout.css`)**:
     - Aligned `.mobile-bottom-bar` with `var(--surface-primary)` (`#FFFFFF`) and 1px top border `var(--border-color)` (`#DDE2DE`), creating visual harmony with the application's clean light-first aesthetic.
     - Updated `.mobile-nav-item` to high-contrast `--text-secondary` / `--text-primary` and `.mobile-nav-item-primary` to authoritative `--brand-primary`.
  4. **Component Polish & Tokenized Hovers (`public/assets/css/components.css`)**:
     - Added explicit `::placeholder` styling for `.form-input` and `.form-textarea` bound to `--text-subtle`.
     - Tokenized all table row, position row, category chip, emoji chip, and timeline chip hover backgrounds to `var(--surface-secondary)` and `var(--surface-tertiary)` to prevent hardcoded color drift.
- **Verification**: Executed `php tests/run_all_tests.php` -> **33 / 33 test suites passing (100%)**.



