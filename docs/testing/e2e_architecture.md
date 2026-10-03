# Smart Split V2 — Playwright UI & End-to-End Testing Architecture Report

**Version:** 2.0.0 — Production-Grade Browser QA Layer  
**Engine:** Playwright Test 1.63+ (Chromium / Google Chrome & Mobile Chrome Viewports)  
**Execution Date:** 2026-09-27  
**Overall Status:** **100% PASS (23 / 23 Playwright Specs | 51 / 51 Baseline Suites)**

---

## 1. Executive Summary

Smart Split V2 now possesses a complete, production-grade automated browser testing layer built directly on top of the established 51 automated test suites (29 PHP 8.2 backend suites + 22 Node.js ES6 frontend suites).

In strict adherence to the **Absolute No-Duplication Rule**, this Playwright test layer does not duplicate backend mathematical invariants or pure unit tests. Instead, it tests exclusively what **only a real browser and real user interaction can validate**:
- Real DOM user journeys, form inputs, modal dialogs, and validation alerts
- Multi-dimensional instant ledger search and category pill filtering
- Interactive split tab transitions across all 5 split models (Equal, Exact, Percentage, Shares, Itemized)
- In-place editing, soft deletion, and 1-click trash bin recovery
- Interactive SVG Debt Flow diagram rendering & settlement transfer workflows
- High-resolution receipt file dropzone attachments and Lightbox viewer
- Light/Dark theme switching with `localStorage` persistence across page reloads
- Mobile touch viewport responsive layout (390×844)
- WCAG 2.1 accessibility compliance (Escape dismissal, Tab focus trapping, ARIA semantics)

---

## 2. Test Execution Verification Matrix

```
================================================================================
  SMART SPLIT V2: COMPLETE AUTOMATED TEST MATRIX
================================================================================
  Layer                         Suites / Specs    Passed    Failed    Success Rate
--------------------------------------------------------------------------------
  PHP 8.2 Backend Layer              29             29         0         100%
  Vanilla ES6 Frontend Layer         22             22         0         100%
  Playwright E2E Browser Layer       23             23         0         100%
--------------------------------------------------------------------------------
  TOTAL VERIFIED COVERAGE            74             74         0         100%
================================================================================
```

### Detailed Playwright E2E Spec Results (23 / 23 Passed)

| Spec File | Test Case Description | Viewport / Project | Status | Execution Time |
|:---|:---|:---|:---:|:---:|
| `smoke.spec.js` | Complete user lifecycle from landing to settled debt | Desktop Chrome | **PASS** | 8.0s |
| `workspace.spec.js` | Creates workspace with custom currency (USD) | Desktop Chrome | **PASS** | 1.7s |
| `workspace.spec.js` | Validates required fields on workspace creation | Desktop Chrome | **PASS** | 1.6s |
| `workspace.spec.js` | Workspaces Hub modal displays recent workspaces and navigates | Desktop Chrome | **PASS** | 3.2s |
| `members.spec.js` | Adds multiple members and customizes avatar emoji & palette | Desktop Chrome | **PASS** | 4.3s |
| `members.spec.js` | Validates empty member name in Add Member modal | Desktop Chrome | **PASS** | 2.4s |
| `expenses.spec.js` | Logs Exact split expense with live validation | Desktop Chrome | **PASS** | 6.8s |
| `expenses.spec.js` | Logs Percentage split expense (50% / 30% / 20%) | Desktop Chrome | **PASS** | 6.9s |
| `expenses.spec.js` | Logs Shares split expense (1 share vs 2 shares vs 3 shares) | Desktop Chrome | **PASS** | 6.8s |
| `expenses.spec.js` | Logs Multi-Payer expense split across 2 contributors | Desktop Chrome | **PASS** | 6.8s |
| `itemized-expenses.spec.js` | Creates itemized expense with tax & tip proportional surcharges | Desktop Chrome | **PASS** | 7.6s |
| `ledger-trash.spec.js` | Edits an existing expense in-place and updates ledger | Desktop Chrome | **PASS** | 8.1s |
| `ledger-trash.spec.js` | Soft-deletes an expense and restores it via Trash Bin | Desktop Chrome | **PASS** | 7.9s |
| `settlements.spec.js` | Toggles between Card View and SVG Flow Diagram View | Desktop Chrome | **PASS** | 6.8s |
| `settlements.spec.js` | Records payment with dynamic QR code and tests settlement undo | Desktop Chrome | **PASS** | 7.8s |
| `search-filter.spec.js` | Filters ledger by instant search, category chips, and drawer | Desktop Chrome | **PASS** | 10.4s |
| `receipts.spec.js` | Attaches PNG receipt to expense and views in Lightbox | Desktop Chrome | **PASS** | 7.2s |
| `theme-darkmode.spec.js` | Toggles light/dark theme and persists across page reloads | Desktop Chrome | **PASS** | 1.8s |
| `responsive-mobile.spec.js` | Renders responsive mobile navigation and modal workflows | Mobile Chrome | **PASS** | 3.9s |
| `accessibility.spec.js` | Dismisses modal dialog on Escape key press and restores focus | Desktop Chrome | **PASS** | 6.3s |
| `accessibility.spec.js` | Validates ARIA roles on dialogs and settlement tabs | Desktop Chrome | **PASS** | 7.6s |
| `error-states.spec.js` | Displays error UI on invalid workspace token | Desktop Chrome | **PASS** | 1.2s |
| `error-states.spec.js` | Prevents expense submission with 0 or negative amount | Desktop Chrome | **PASS** | 6.3s |

---

## 3. Playwright Architecture & Directory Layout

The Playwright framework is organized following clean Page Object Model (POM) and modular fixture patterns:

```
Smartsplit/
├── playwright.config.js               # Multi-project configuration (Desktop 1440x900 & Mobile 390x844)
└── tests/
    └── e2e/
        ├── data/                      # Test assets (PNG receipt, PDF document, invalid binaries)
        │   ├── receipt-sample.png
        │   ├── receipt-sample.pdf
        │   └── invalid-file.exe
        ├── fixtures/                  # Custom test fixtures with auto-instantiated Page Objects
        │   └── test-fixtures.js
        ├── pages/                     # Page Object Models
        │   ├── BasePage.js            # Common navigation, theme, modals, toasts
        │   ├── LandingPage.js         # Workspace creation, recents directory
        │   ├── WorkspaceHeaderPage.js # Header metrics, budget target, share menu
        │   ├── MemberListSection.js   # Member roster, avatar customization
        │   ├── ExpenseModalPage.js    # All 5 split models, multi-payer, what-if preview
        │   ├── LedgerPage.js          # Table, search, filters, in-place edit, trash
        │   ├── SettlementSection.js   # Card/Diagram view, UPI QR, settlement undo
        │   └── ReceiptLightboxPage.js # Full-screen image/PDF document viewer
        └── specs/                     # Test Specifications
            ├── smoke.spec.js
            ├── workspace.spec.js
            ├── members.spec.js
            ├── expenses.spec.js
            ├── itemized-expenses.spec.js
            ├── ledger-trash.spec.js
            ├── settlements.spec.js
            ├── search-filter.spec.js
            ├── receipts.spec.js
            ├── theme-darkmode.spec.js
            ├── responsive-mobile.spec.js
            ├── accessibility.spec.js
            └── error-states.spec.js
```

---

## 4. Execution Commands & Scripts

All testing scripts are configured directly inside `package.json`:

| Command | Action |
|:---|:---|
| `npm run test:e2e` | Run all 23 Playwright tests across Desktop and Mobile Chrome |
| `npm run test:e2e:smoke` | Run the critical path smoke journey (`smoke.spec.js`) |
| `npm run test:e2e:mobile` | Run tests on the Mobile Chrome viewport (390×844) |
| `npm run test:e2e:report` | Launch the HTML test report in browser |
| `npm run test:all` | Run all 51 baseline suites + all 23 Playwright E2E tests |
| `npm run test` / `npm run test:php` | Run the 51 baseline PHP & Node suites |

---

## 5. Non-Duplication & Quality Assurance Verification

- **Zero Test Duplication:** Pure calculation mechanics (Paise math, Hare-Niemeyer conservation, exchange rates, greedy graph reduction) are exhaustively proven by the 51 unit & invariant suites. Playwright focuses purely on browser user interaction, accessibility, layout stability, and live reactive DOM state.
- **Zero Arbitrary Sleep Flakiness:** All tests use web-first reactive assertions (`expect(locator).toBeVisible()`, `expect(locator).toHaveText()`, `expect(locator).toHaveClass()`), eliminating `waitForTimeout` race conditions.
- **Database Non-Destruction:** All tests create isolated dynamic workspace tokens (`Date.now()`), avoiding shared state collisions or destructive table wipes.
