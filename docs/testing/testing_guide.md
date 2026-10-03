# Smart Split V2 — Automated Testing Baseline

## Current Status

The automated test baseline is organized across three integrated testing layers:
- **76 master test suites** (50 PHP backend + 26 Vanilla ES6 frontend suites)
- **23 logical Playwright browser tests** across 13 spec files (69 executions under 3x stress repetition)
- **Total:** Comprehensive backend, client-state, and end-to-end browser coverage passing deterministically in the local development environment.

---

## Test Layers

### Layer 1 — PHP Backend
- **Suites:** 29 test suites
- **Scope:** Database schema constraints, integer Paise arithmetic, Hare-Niemeyer conservation, greedy min-cash-flow debt simplification algorithm, REST API routing, soft-delete trash & 1-click restore, dynamic UPI QR generation, multi-currency conversion, and security/adversarial boundaries.
- **Canonical Command:**
  ```bash
  php tests/run_all_tests.php
  ```

### Layer 2 — Vanilla ES6 Frontend
- **Suites:** 22 test suites
- **Scope:** Client math parity, reactive state store, pure SVG donut charts & histogram rendering, timeline formatting, client-side QR matrix generation, multi-currency utilities, and keyboard/UI workflows.
- **Execution:** Executed automatically through the master test runner (`php tests/run_all_tests.php`) or independently via `node tests/run_frontend_tests.mjs`.

### Layer 3 — Playwright Browser E2E
- **Suites:** 13 spec files defining 23 logical browser tests
- **Scope:** Full user journeys, workspace creation & switching Hub, member roster management, avatar personalization, 5 expense split models (Equal, Exact, Percentage, Shares, Multi-Payer), itemized surcharge allocations, in-place ledger editing, trash recovery, card vs SVG flowchart switching, receipt uploads & lightbox viewer, dark mode persistence, WCAG 2.1 keyboard navigation, and responsive mobile layouts.
- **Configured Projects:**
  - `Desktop Chrome` (Viewport: 1440×900)
  - `Mobile Chrome` (Pixel 5 device descriptor, Viewport: 390×844)

---

## Standard Commands

| Command | Target Scope | Verification Purpose |
|---|---|---|
| `php tests/run_all_tests.php` | Master Test Runner (51 Suites) | Executes all 29 PHP backend suites and 22 Vanilla ES6 frontend suites synchronously. |
| `npx playwright test` | Playwright E2E Layer (23 Executions) | Executes all 23 logical browser tests across Desktop Chrome (22 tests) and Mobile Chrome (1 test). |
| `npx playwright test --repeat-each=3` | Playwright Stress Suite (69 Executions) | Executes three consecutive iterations of all 23 logical tests (69 total browser executions) to verify zero timing flakiness or state leakage. |

---

## Current Browser Scope

### Covered
- **Desktop Chrome** (Chromium / Google Chrome, 1440×900 standard widescreen)
- **Mobile Chrome** (Pixel 5 mobile device descriptor, 390×844 touch viewport)

### Not Currently Covered
- **Firefox** (Gecko)
- **WebKit / Safari**

*(Note: Uncovered browser engines reflect the current scope boundary of the local automated testing suite, not test failures.)*

---

## Stability Baseline

During baseline freeze verification:
- **23 / 23 standard Playwright executions** passed.
- **69 / 69 repeat stress executions** passed (`--repeat-each=3`).
- **0 failures**.
- **0 flaky retries**.

---

## Artifact Hygiene

- Receipt uploads generated during the execution of `receipts.spec.js` are tracked via in-memory directory delta snapshots before test execution.
- Only newly created test files are unlinked in the test's `finally` block upon completion.
- Pre-existing files and seeded fixture assets in `public/uploads/receipts/` remain preserved. Zero leftover test files remain on disk following test execution.

---

## Production Integrity

- The baseline freeze verified the cryptographic SHA-256 integrity of the **70 production files included in the manifest** (`src/`, `config/`, `database/`, `includes/`, `public/index.php`, `public/assets/js/`, `public/assets/css/`).
- 70 / 70 files in the manifest matched their baseline hashes with zero modifications.
- *(Note: Cryptographic verification applies specifically to the 70 source files monitored in the integrity manifest.)*

---

## Scope Limitations

1. **Browser Matrix:** No Firefox or WebKit/Safari execution is currently configured.
2. **Visual Regression:** No pixel-level screenshot comparison or visual diffing suite is configured.
3. **Offline Caching:** No dedicated offline/service-worker network disruption test matrix is configured.
4. **Environment:** Testing is executed against the local development environment (PHP 8.2 built-in server + MySQL 8.0 on localhost).

---

## Test Baseline Policy

> Existing green tests should remain green when future application changes are introduced.

> New functional tests should only be added when a genuinely new user-visible behavior or regression risk is introduced.

> Existing backend/frontend tests and Playwright tests should not be duplicated unnecessarily.

> Production code must not be modified merely to satisfy a test unless the test exposes an actual application defect.
