# Smart Split V2 — Playwright Test Quality, Duplication & Flakiness Audit

**Version:** 1.0  
**Phase:** Post-Implementation QA & Test System Quality Audit  
**Target:** Smart Split V2 Browser QA Test Infrastructure  
**Date:** 2026-09-27  
**Auditor:** Principal QA Architect & Test Automation Specialist  
**Overall Quality Status:** **READY WITH DOCUMENTED GAPS**

---

## 1. Executive Summary

This audit independently evaluated the newly implemented Playwright UI & End-to-End testing layer for **Smart Split V2**. The objective was not merely to report passing checks, but to critically assess whether the browser testing system is correct, non-duplicative, deterministic, isolated, and browser-realistic.

### Key Audit Findings:
1. **Actual Inventory Verified:** The Playwright suite comprises **13 spec files** defining **23 unique logical `test()` cases**, executing across **2 dedicated browser projects** (Desktop Chrome 1440×900 and Mobile Chrome 390×844) yielding **23 project executions per full run**.
2. **Zero Anti-Pattern Violations:**
   - `waitForTimeout`: **0** occurrences across all specs and Page Objects.
   - `networkidle`: **0** occurrences.
   - `force: true`: **0** occurrences.
   - `test.only` / `test.skip` / `test.fixme`: **0** occurrences.
   - `mode: 'serial'`: **0** occurrences.
3. **Flakiness & Repeat Stability:** A 3x repeated stress run (**69 total executions**) completed with **69 / 69 PASS (100% stability, 0 retries, 0 intermittent failures)**.
4. **Non-Duplication Adherence:** Zero mathematical algorithms (Paise rounding, Hare-Niemeyer conservation, greedy graph debt minimization) were re-implemented inside tests. Playwright tests purely validate observable DOM reactive rendering, user inputs, dialogs, and browser navigation.
5. **Full Baseline Regression Safety:** The 51 existing unit/invariant suites (**29 PHP backend + 22 Node.js frontend**) continue to pass at **100% (51/51 PASS)**.
6. **Production Code Integrity:** **Zero production code files** were altered during this audit.

---

## 2. Actual Test Inventory

| Spec File | Suite Title | Logical Tests (`test()`) | Target Project(s) | Project Executions |
|:---|:---|:---:|:---|:---:|
| [`accessibility.spec.js`](../../tests/e2e/specs/accessibility.spec.js) | Accessibility & Keyboard Navigation (WCAG 2.1) | 2 | Desktop Chrome | 2 |
| [`error-states.spec.js`](../../tests/e2e/specs/error-states.spec.js) | Resilience & Error Handling | 2 | Desktop Chrome | 2 |
| [`expenses.spec.js`](../../tests/e2e/specs/expenses.spec.js) | Expense Logging & Split Methodologies | 4 | Desktop Chrome | 4 |
| [`itemized-expenses.spec.js`](../../tests/e2e/specs/itemized-expenses.spec.js) | Itemized Receipt Splitting | 1 | Desktop Chrome | 1 |
| [`ledger-trash.spec.js`](../../tests/e2e/specs/ledger-trash.spec.js) | Ledger Actions, In-Place Editing & Trash Recovery | 2 | Desktop Chrome | 2 |
| [`members.spec.js`](../../tests/e2e/specs/members.spec.js) | Member Roster & Avatar Customization | 2 | Desktop Chrome | 2 |
| [`receipts.spec.js`](../../tests/e2e/specs/receipts.spec.js) | Receipt Attachment & Lightbox Viewer | 1 | Desktop Chrome | 1 |
| [`responsive-mobile.spec.js`](../../tests/e2e/specs/responsive-mobile.spec.js) | Responsive Mobile Viewport & Usability | 1 | Mobile Chrome | 1 |
| [`search-filter.spec.js`](../../tests/e2e/specs/search-filter.spec.js) | Multi-Dimensional Ledger Search & Filtering | 1 | Desktop Chrome | 1 |
| [`settlements.spec.js`](../../tests/e2e/specs/settlements.spec.js) | Debt Settlement & Visual Flow Diagram | 2 | Desktop Chrome | 2 |
| [`smoke.spec.js`](../../tests/e2e/specs/smoke.spec.js) | Core E2E Smoke Journey | 1 | Desktop Chrome | 1 |
| [`theme-darkmode.spec.js`](../../tests/e2e/specs/theme-darkmode.spec.js) | Dark Mode & Theme Persistence | 1 | Desktop Chrome | 1 |
| [`workspace.spec.js`](../../tests/e2e/specs/workspace.spec.js) | Workspace Management & Hub | 3 | Desktop Chrome | 3 |
| **TOTALS** | **13 Spec Files** | **23 Logical Tests** | **2 Projects** | **23 Executions** |

---

## 3. Playwright Responsibility Matrix

| Feature Domain | PHP Backend Suites (29) | Vanilla ES6 Frontend Suites (22) | Playwright E2E Layer (23) | Correct Owner |
|:---|:---|:---|:---|:---|
| **Paise & Split Math** | Integer arithmetic, Hare-Niemeyer conservation, remainder allocations | Client Math parity, decimal-to-cents formatting | DOM input keystrokes, real-time validation bar, visible totals | **Backend & Frontend Units own Math; Playwright owns Form UX** |
| **Debt Simplification** | Greedy Min-Cash-Flow graph reduction algorithm | Visual SVG node position calculations | Card vs SVG diagram view toggle, settlement modal with live QR | **Backend owns Algorithm; Playwright owns View Switching & UPI UX** |
| **Workspaces Hub** | Group CRUD API, invite token authorization | Consolidated multi-workspace financial metrics | Hub modal rendering, recent workspace switcher navigation | **Split across layers** |
| **Ledger Management** | SQL queries, soft delete flags, restore endpoints | Search predicate unit logic, hashtag regex | Instant search input typing, category chip filtering, in-place edit | **Playwright owns User Search/Edit UI** |
| **Receipts** | File upload validation, MIME checks, base64 decoding | Filename formatting, thumbnail aspect ratios | Browser file dropzone `<input type="file">`, high-res Lightbox modal | **Playwright owns Dropzone & Lightbox** |
| **Theming & Display** | Default theme attributes | Theme manager unit functions | Navbar toggle button, `data-theme` mutation, reload persistence | **Playwright owns Browser Storage Persistence** |
| **Mobile & Responsive** | — | Touch target calculations | Real Pixel 5 viewport layout, touch targets, overflow verification | **Playwright owns Viewport QA** |
| **Accessibility (WCAG)** | — | — | Escape dismissal, Tab focus trapping, ARIA roles (`dialog`, `tab`) | **Playwright owns Accessibility Automation** |

---

## 4. Duplication Audit (D0 – D3 Classification)

- **D0 (No Duplication):** Unique browser-level capability.
- **D1 (Intentional Overlap):** Validates the visible UI integration of a feature whose logic is unit-tested.
- **D2 (Partial Duplication):** Some assertions redundant with lower layers (refactored).
- **D3 (Full Duplication):** Pure duplicate calculation oracle (none found).

| Spec Test Case | Existing Baseline Coverage | Classification | Audit Evaluation & Action Taken |
|:---|:---|:---:|:---|
| `smoke.spec.js`: Complete user lifecycle | `test_e2e_flow.php` | **D1** | Essential top-level browser smoke journey. Validates cross-component DOM flow from Landing → Workspace → Expense → Settlement. |
| `workspace.spec.js`: Custom currency creation | `test_groups_api.php`, `test_step14_multi_currency.php` | **D1** | Validates `<select>` UI and dynamic currency code badge in the header. |
| `workspace.spec.js`: Required field validation | `test_groups_api.php` | **D1** | Validates HTML5 and client-side form prevention on empty inputs without page navigation. |
| `workspace.spec.js`: Workspaces Hub modal | `test_multi_workspace_summary.mjs` | **D1** | Validates recent workspaces modal rendering and quick switcher link clicks. |
| `members.spec.js`: Add member & customize avatar | `test_custom_avatars.mjs` | **D1** | Validates modal emoji picker grid and color palette swatch selection updating the DOM chip. |
| `members.spec.js`: Validate empty member name | `test_groups_api.php` | **D1** | Validates modal form stay-open behavior on blank member submission. |
| `expenses.spec.js`: Exact split | `test_math.php`, `test_client_core.mjs` | **D1** | Validates user custom input fields and live validation bar (`split-validation-bar`). |
| `expenses.spec.js`: Percentage split | `test_math.php`, `test_frontend_financial_invariants.mjs` | **D1** | Validates percentage custom inputs and total 100% check. |
| `expenses.spec.js`: Shares split | `test_math.php` | **D1** | Validates integer share ratio inputs. |
| `expenses.spec.js`: Multi-Payer mode | `test_expenses_api.php` | **D1** | Validates multi-payer toggle button and input validation bar. |
| `itemized-expenses.spec.js`: Itemized splitting | `test_step6_itemized_api.php`, `test_step13_expense_modal.mjs` | **D1** | Validates dynamic row addition, member toggle chips (`.item-member-toggle`), and subtotal previews. |
| `ledger-trash.spec.js`: In-place edit | `test_v2_features.php` | **D1** | Validates clicking edit button on row, modal pre-population, and table update. |
| `ledger-trash.spec.js`: Soft delete & restore | `test_trash_restore_api.php`, `test_trash_restore.mjs` | **D1** | Validates delete button click, Trash Bin modal opening, and 1-click restore button. |
| `settlements.spec.js`: Card vs Diagram view | `test_step10_analytics_svg.php`, `test_debt_flowchart.mjs` | **D0** | Browser-only tab switching between CSS card list and SVG flowchart. |
| `settlements.spec.js`: UPI QR payment & undo | `test_step12_upi_qr.php`, `test_step12_client_qr.mjs` | **D1** | Validates modal QR display, payment recording with custom UTR, and undo button. |
| `search-filter.spec.js`: Keyword & category filter | `test_step8_search_filtering.php`, `test_step8_client_search.mjs` | **D1** | Validates live DOM table filtering upon user keystrokes and category pill clicks. |
| `receipts.spec.js`: Receipt upload & Lightbox | `test_step15_receipt_attachments.php`, `test_step15_client_receipts.mjs` | **D0** | Real browser file input attachment and full-screen image Lightbox viewer overlay. |
| `theme-darkmode.spec.js`: Theme toggle & reload | `ThemeManager` unit tests | **D0** | Real browser DOM `data-theme` attribute and `localStorage` persistence across page reloads. |
| `responsive-mobile.spec.js`: Mobile viewport | `test_step13_mobile_ux.mjs` | **D0** | Real 390×844 Pixel 5 viewport touch layout, mobile logo, and modal bounds. |
| `accessibility.spec.js`: Escape key & ARIA roles | — | **D0** | Real browser keyboard Escape event dispatch, focus restoration, and ARIA attributes. |
| `error-states.spec.js`: Invalid token 404 UI | `test_router.php` | **D1** | Validates user-visible error banner container on bad route navigation. |
| `error-states.spec.js`: Prevent zero amount | `test_expenses_api.php` | **D1** | Validates disabled save button on 0 amount input. |

---

## 5. Anti-Pattern Audit

| Anti-Pattern | Found | Assessment & Findings |
|:---|:---:|:---|
| `waitForTimeout()` | **0** | Clean. All tests synchronize using web-first assertions (`toBeVisible()`, `toHaveText()`, `toHaveCount()`). |
| `networkidle` | **0** | Clean. No reliance on unstable networkidle heuristics. |
| `force: true` | **0** | Clean. All clicks require natural pointer visibility and interactability. |
| `.nth()` index selectors | **Limited & Valid** | Used appropriately for list indices (e.g. `tableRows.nth(0)`). Specific form fields use semantic selectors (`data-member-id`, `.item-member-toggle`, accessible labels). |
| `page.evaluate()` | **2** | Restricted strictly to reading browser-only properties (`document.documentElement.getAttribute('data-theme')` and `localStorage.getItem('smartsplit_theme')`). |
| API Bypasses | **0** | Clean. All tested user workflows are driven via authentic DOM interactions. |
| `test.only` / `test.skip` | **0** | Clean. No skipped or isolated test locks exist. |
| `mode: 'serial'` | **0** | Clean. Tests are isolated and independent. |
| Unjustified Retries | **0** | Retries set to 0 locally (`retries: process.env.CI ? 2 : 0`). All tests pass on the first attempt. |

---

## 6. Selector Robustness & Strategy

The Page Objects utilize a resilient, multi-tiered locator strategy:
1. **Semantic IDs & Accessible Attributes:** `#group-name-input`, `#modal-expense-title`, `#btn-navbar-theme`, `[role="dialog"]`, `[role="tab"]`.
2. **Text & Content Filters:** `page.locator('.member-chip').filter({ hasText: 'Kavita' })`, `locator('.item-member-toggle', { hasText: 'Alice' })`.
3. **Data Attributes for Multi-Party Grids:** `[data-member-id]`, `[data-category-id]`, `[data-type]`, `[data-expense-id]`.
4. **Resilient Class Selectors:** `.data-table tbody tr`, `.split-tab-btn.active`, `.badge-credit`.

---

## 7. Assertion Quality Audit

Assertions across all 13 spec files verify **observable user outcomes** rather than internal application internals:
- **Visibility & Content:** `expect(headerPage.title).toHaveText(workspaceName)`
- **Ledger Count & Currency:** `expect(ledgerPage.tableRows).toHaveCount(1)`, `expect(row).toContainText('₹1,500.00')`
- **Validation Feedback:** `expect(expenseModal.validationBar).toHaveClass(/valid/)`
- **Modal Lifecycle:** `expect(expenseModal.modalDialog).not.toBeVisible()`
- **Accessibility Tree:** `expect(tab).toHaveAttribute('role', 'tab')`, `expect(dialog).toHaveAttribute('aria-modal', 'true')`

---

## 8. Fixture & Test Isolation Audit

- **Isolation Strategy:** Every test generates a uniquely timestamped workspace (`Test Workspace ${Date.now()}`), preventing state pollution.
- **Fixture Lifecycle:** [`test-fixtures.js`](../../tests/e2e/fixtures/test-fixtures.js) instantiates Page Objects per test context, guaranteeing clean browser contexts and isolated sessions.
- **Database Safety:** Zero destructive queries (`DROP`, `TRUNCATE`, `DELETE FROM table`) are executed. Test data consists of isolated rows in existing tables.

---

## 9. Desktop vs. Mobile Project Distribution

```
playwright.config.js Projects:
├── Desktop Chrome (Viewport: 1440x900)
│   ├── Matches: 12 spec files (22 logical tests)
│   └── Ignores: responsive-mobile.spec.js
└── Mobile Chrome (Pixel 5, Viewport: 390x844)
    ├── Matches: responsive-mobile.spec.js (1 logical test)
    └── Ignores: desktop-specific specs
```

| Suite File | Desktop Chrome (1440×900) | Mobile Chrome (390×844) | Verification Purpose |
|:---|:---:|:---:|:---|
| `smoke.spec.js` | **YES** | — | Core end-to-end integration |
| `workspace.spec.js` | **YES** | — | Workspace creation & Hub modal |
| `members.spec.js` | **YES** | — | Member roster & avatars |
| `expenses.spec.js` | **YES** | — | All split allocation modes |
| `itemized-expenses.spec.js` | **YES** | — | Line-item allocations & taxes |
| `ledger-trash.spec.js` | **YES** | — | In-place edit & trash recovery |
| `settlements.spec.js` | **YES** | — | Card vs SVG diagram view |
| `search-filter.spec.js` | **YES** | — | Multi-dimensional search drawer |
| `receipts.spec.js` | **YES** | — | Receipt dropzone & lightbox |
| `theme-darkmode.spec.js` | **YES** | — | Dark mode toggle & reload |
| `accessibility.spec.js` | **YES** | — | Escape key & ARIA roles |
| `error-states.spec.js` | **YES** | — | 404 & zero amount validation |
| `responsive-mobile.spec.js` | — | **YES** | Mobile logo, touch layout, overflow |

---

## 10. Flakiness & Repeat Stability Investigation

To stress-test timing synchronization, state isolation, and database concurrency, a **3x repeat stress execution** was performed:

- **Command:** `npx playwright test --repeat-each=3`
- **Total Executions:** 69 test runs (23 tests × 3 iterations)
- **Passed Executions:** **69 / 69 (100%)**
- **Failed Executions:** **0**
- **Flaky / Retried Runs:** **0**
- **Total Duration:** 6.7 minutes

Zero intermittent failures or timing race conditions were detected.

---

## 11. Web Server & Environment Verification

- **Runtime:** PHP 8.2 (`C:\xampp\php\php.exe`)
- **Web Server Command:** `C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public public/index.php`
- **Base URL:** `http://127.0.0.1:8000`
- **Health Endpoint:** `http://127.0.0.1:8000/api/health` (Status 200 OK)
- **Database:** Local MySQL on `127.0.0.1:3306` (`smart_split` database)

---

## 12. Production Change Audit

A filesystem audit was conducted across all production application directories (`public/`, `src/`, `config/`, `database/`, `includes/`):

- **Production Application Code Files Modified:** **0** (Zero changes to PHP, JavaScript, CSS, or database schema).
- **Test Infrastructure Files Modified:** `playwright.config.js`, `package.json`, `tests/e2e/pages/*`, `tests/e2e/specs/*`, `tests/e2e/fixtures/*`.
- **Live Uploads Created by Tests:** Dynamic test receipts in `public/uploads/receipts/` created during file dropzone test execution.

---

## 13. Final Regression & Quality Matrix

```
================================================================================
  SMART SPLIT V2: COMPLETE VERIFICATION MATRIX
================================================================================
  Layer                         Expected Baseline    Actual Executed    Status
--------------------------------------------------------------------------------
  PHP 8.2 Backend Suites               29                  29           100% PASS
  Vanilla ES6 Frontend Suites          22                  22           100% PASS
  Playwright Logical Tests             23                  23           100% PASS
  Playwright Browser Executions        23                  23           100% PASS
  Playwright Repeat Stress (3x)        69                  69           100% PASS
--------------------------------------------------------------------------------
  TOTAL VERIFIED AUTOMATED TESTS       74                  74           100% PASS
================================================================================
```

---

## 14. Remaining Known Gaps (Documented for Future Phases)

1. **Cross-Browser Engine Expansion:** Currently executing on Chromium/Google Chrome and Mobile Chrome viewports. WebKit (Safari) and Firefox engine execution can be added in a future controlled CI phase.
2. **Visual Pixel-by-Pixel Snapshot Regression:** Visual testing is currently based on DOM bounding box, SVG rendering, and layout inspection rather than pixel screenshot diffing (to avoid noisy false positives across different OS rendering engines).
3. **Offline Service Worker Mocking:** Service worker registration is validated, but simulated offline network disconnection tests remain a future enhancement.

---

## 15. Final Classification & Recommendation

**Classification:** **READY WITH DOCUMENTED GAPS**

The Playwright browser testing suite for Smart Split V2 is thoroughly audited, deterministic, non-duplicative, and stable. It establishes an authentic, high-confidence browser testing foundation that protects the application's user experience without redundant mathematical oracles or destructive side effects.
