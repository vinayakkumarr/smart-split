# Smart Split V2

Smart Split V2 is a self-hosted, zero-runtime-framework group expense and debt management application designed around deterministic financial calculations, explicit settlement workflows, and strong workspace isolation.

---

## 📌 Project Status

- **Application Release Status:** `Release Frozen / Approved`
- **Verification Baseline:** 80 automated master test suites (100% pass), 77 financial lifecycle invariant checks (100% pass), and 38 adversarial security test suites passed.
- **Deployment Status:** `Not Yet Provisioned` (Production hosting infrastructure, TLS termination, and deployment pipelines are executed in a subsequent operational phase).

---

## 🚀 Key Capabilities

### 1. Workspace & Collaboration
- **Guest-First Anonymous Groups:** Create shared workspaces instantly without mandatory upfront registration.
- **Member Token Authentication:** Cryptographically isolated member access tokens for individual participant attribution.
- **Progressive Account Claiming:** Users can register an account at any time and claim their guest member profiles across multiple groups into a unified workspace portfolio.
- **Workspace Isolation:** Strict tenant boundary enforcement prevents cross-workspace data leakage (BOLA/IDOR protection).

### 2. Expense Management & Split Calculation
- **Six Flexible Split Modes:**
  - **Equal (`=`):** Divides expenses evenly across participants with deterministic penny reconciliation.
  - **Exact (`₹`):** Allows explicit fixed currency amounts per participant with real-time total validation.
  - **Percentage (`%`):** Allocates costs proportionally with Hare-Niemeyer largest-remainder penny distribution.
  - **Shares (`x`):** Weight-based distribution (e.g., 2 shares vs. 1 share).
  - **Adjustment (`+/-`):** Baseline equal splits with positive or negative individual offset adjustments.
  - **Itemized Breakdown:** Detailed line-item assignments with proportional distribution of taxes, tips, and discounts.
- **Multiple Payers:** Single expenses can be funded by multiple members in arbitrary portions.
- **Expense Lifecycle:** Supports in-place updates, categorization, soft deletion, and 1-click restoration from the trash bin.

### 3. Settlement Lifecycle & Debt Simplification
- **Greedy Min-Cash-Flow Algorithm:** Computes a debt simplification graph resolving mutual obligations in at most $N - 1$ total transfers for $N$ members.
- **Role-Aware Settlement Lifecycle:**
  - **Record:** Proposed by payer, payee, or workspace owner.
  - **Confirm:** Verified explicitly by creditor or workspace owner.
  - **Dispute:** Challenged by creditor if unreceived or incorrect.
  - **Reverse:** Reopened with full ledger balance restoration.
- **Payment Integration:** Dynamic UPI deep links (`upi://pay`) and native vector SVG QR code generation for mobile payment instructions, with optional UTR / bank transaction reference logging.

### 4. Financial Intelligence & Automation
- **12 Global Currencies:** Native support for `INR`, `USD`, `EUR`, `GBP`, `CAD`, `AUD`, `SGD`, `AED`, `JPY`, `CHF`, `CNY`, and `NZD` with cached exchange rates.
- **Native SVG Visual Analytics:** Pure vector SVG donut charts and category breakdown histograms rendered without external JavaScript charting libraries.
- **Recurring Schedules:** Automated daily, weekly, monthly, and yearly recurring expense evaluation with next-run date computation.
- **Reusable Split Templates:** Save and apply recurring expense split configurations.
- **Private Receipt Storage:** Secure uploads for JPEG, PNG, WebP, and PDF receipt files (up to 5 MB) stored outside the web root with randomized filenames and binary streaming endpoints.
- **Immutable Activity Audit Log:** Chronological, tamper-evident audit trail capturing all expense, member, and settlement modifications.

---

## 🧮 Financial Correctness & Invariants

### 1. Strict Integer-Cent Arithmetic
All monetary calculations use integer minor units to avoid floating-point ledger drift (e.g., paise for INR, cents for USD/EUR). PHP performs these calculations using native integers, while database monetary columns use integer SQL types appropriate to their role.

$$\text{Balance}_{\text{cents}} = \text{round}(\text{Amount} \times 100)$$

### 2. Hare-Niemeyer (Largest Remainder) Allocation
When dividing amounts with non-zero fractional remainders (such as percentage splits or odd-number divisions), Smart Split employs the **Hare-Niemeyer algorithm**:
1. Computes the floor allocation for every participant.
2. Ranks remaining fractional parts in descending order.
3. Distributes single minor units (pennies/paise) to the highest-ranking fractions until the total sum exactly equals the original expense amount.
4. Uses ascending member ID ordering as a deterministic tie-breaker.

This ensures that the sum of all individual splits always reconciles with the total expense amount without lost or manufactured currency.

### 3. Zero-Sum Ledger Invariant
For every group workspace, the sum of all net balances across all members is invariant and strictly equal to zero at all times:

$$\sum_{i=1}^{N} \text{Net Balance}_i \equiv 0$$

Every balance calculation verifies this invariant. Any deviation immediately fails closed and aborts the transaction.

---

## 🏛️ System Architecture

```text
┌─────────────────────────────────────────────────────────┐
│              Browser Client (Vanilla ES6 SPA)           │
└────────────────────────────┬────────────────────────────┘
                             │ HTTP / JSON API (REST)
                             ▼
┌─────────────────────────────────────────────────────────┐
│     Front Controller & Router (public/index.php)        │
├─────────────────────────────────────────────────────────┤
│ Middleware: SecurityHeaders, AuthSession, RateLimiter   │
└────────────────────────────┬────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────┐
│              Controllers (src/Controllers/)             │
└────────────────────────────┬────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────┐
│                Services (src/Services/)                 │
│  - SplitCalculator      - BalanceService                │
│  - SettlementEngine     - ExpenseService                │
│  - RateLimiterService   - ReceiptService                │
└────────────────────────────┬────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────┐
│             Repositories (src/Repositories/)            │
└────────────────────────────┬────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────┐
│          Database Layer (src/Core/Database.php)         │
│          - PDO MySQL Driver (Singletons, Transactions)  │
└────────────────────────────┬────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────┐
│              Database Engine (MySQL 8.0+)               │
└─────────────────────────────────────────────────────────┘
```

---

## 🛠️ Technology Stack

| Layer | Technology | Description |
|---|---|---|
| **Backend Runtime** | PHP 8.2+ | Native PHP with strict types (`declare(strict_types=1)`), zero external framework dependencies |
| **Database Engine** | MySQL 8.0+ | InnoDB storage engine, ACID transactions, `utf8mb4_unicode_ci` charset, row-level locking |
| **Database Driver** | PDO MySQL (`pdo_mysql`) | Parameterized prepared statements, exception mode, non-persistent connection lifecycle |
| **Frontend Architecture** | Vanilla ES6+ Modules | Modular single-page application (SPA), client-side hash router, reactive Pub/Sub store |
| **Styling & UI** | CSS3 Custom Properties | Mobile-first responsive layout, dark/light theme switching, CSS Grid/Flexbox |
| **Visualizations** | Native Vector SVG | Inline SVG chart engines (Donut rings & Bar histograms) generated client-side |
| **File Storage** | Private Local Filesystem | `storage/receipts/` outside document root with binary streaming via controller |
| **Test Tooling** | PHP CLI & Node.js | Custom test runners for backend integration, invariant verification, and frontend unit checks |

---

## 📦 Dependency Model

### Runtime Dependencies
- **PHP 8.2 or higher**
- Required PHP Extensions:
  - `pdo` & `pdo_mysql` (Database access)
  - `json` (API serialization)
  - `fileinfo` (MIME magic-byte verification)
  - `mbstring` (Multibyte UTF-8 handling)
  - `openssl` (CSPRNG cryptographic token generation)
  - `session` (HTTP session transport)
  - `ctype` & `filter` (Input validation)
  - `hash` (SHA-256 token hashing)
- **MySQL 8.0 or higher**
- **Web Server:** Any web server supporting PHP (e.g., Apache with `mod_rewrite`, Nginx with `php-fpm`, or Caddy).
- **Runtime Packages:** **Zero.** Smart Split has zero Composer or NPM runtime package dependencies.

### Development & Test Dependencies
- **Node.js (v18+)**: Used exclusively for executing client-side unit and DOM test suites.

---

## ⚙️ Installation & Setup

### 1. System Requirements
Ensure PHP 8.2+ and MySQL 8.0+ are installed with the required PHP extensions enabled.

### 2. Configure Environment
Copy the example environment configuration file to `.env`:

```bash
cp .env.example .env
```

Edit `.env` to configure your database connection and application settings:

```ini
APP_NAME="Smart Split"
APP_ENV="local"
APP_DEBUG=true
APP_URL="http://127.0.0.1:8000"

DB_CONNECTION="mysql"
DB_HOST="127.0.0.1"
DB_PORT=3306
DB_DATABASE="smart_split"
DB_USERNAME="your_mysql_user"
DB_PASSWORD="your_mysql_password"
DB_CHARSET="utf8mb4"

DEFAULT_CURRENCY="INR"
```

### 3. Initialize Database & Migrations
Create the MySQL database:

```sql
CREATE DATABASE smart_split CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Execute the database schema migrations using the automated migration runner:

```bash
php bin/migrate.php
```

The migration runner connects using your `.env` configuration (or optional CLI flags: `--host=`, `--port=`, `--db=`, `--user=`, `--pass=`), automatically ensures the `schema_migrations` tracking table exists, and applies all 13 schema migrations in sequential order idempotently.

*(Manual fallback: execute the 13 schema migrations in sequential order from `migrations/001_create_initial_schema.sql` through `migrations/013_add_settlement_idempotency_keys.sql`)*.

### 4. Run Development Server
Start the built-in PHP development server pointing to the `public/` directory:

```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

Open your browser at `http://127.0.0.1:8000/`.

> **Note on Local Development Environments:** Development on local stacks (such as XAMPP) is supported for convenience, but the application target remains MySQL 8.0+.

---

## 🗄️ Database Migrations

The database schema is managed via 13 structured SQL migrations located in [migrations/](migrations/):

| Migration | Filename | Purpose |
|---|---|---|
| **001** | `001_create_initial_schema.sql` | Core tables: `groups`, `members`, `expenses`, `expense_payers`, `expense_splits`, `settlements`, `activity_logs` |
| **002** | `002_add_categories_and_features.sql` | Tables for `categories` and `recurring_rules` |
| **003** | `003_add_itemized_and_templates.sql` | Line items (`expense_items`), assignments, `expense_templates`, `receipt_attachments` |
| **004** | `004_add_custom_categories.sql` | Custom category customization and group metadata |
| **005** | `005_add_multi_currency_support.sql` | Multi-currency exchange rate conversions on expenses |
| **006** | `006_add_hybrid_authentication.sql` | Hybrid user accounts (`users`), persistent session store (`user_sessions`), member claiming |
| **007** | `007_add_rate_limiting.sql` | Persistent atomic rate limiting store (`rate_limits`) |
| **008** | `008_add_creator_pairing.sql` | Guest creator device pairing codes (`creator_pairing_codes`) |
| **009** | `009_add_workspace_events.sql` | Real-time Server-Sent Events stream (`workspace_events`) |
| **010** | `010_add_idempotency_keys.sql` | API request deduplication and response caching (`idempotency_keys`) |
| **011** | `011_add_settlement_verification_lifecycle.sql` | Settlement lifecycle states, notes, UTR numbers, verification attribution, and reversal tracking |
| **012** | `012_add_user_profile_upi.sql` | Personal UPI ID profile attribute (`users.upi_id`) for dynamic payee settlement routing |
| **013** | `013_add_settlement_idempotency_keys.sql` | Settlement idempotency key storage and request deduplication hash |

---

## 🔐 Authentication & Authorization Model

### Hybrid Identity Architecture
1. **Guest Mode:** Workspaces can be created and shared instantly. Members receive private member claim tokens for attribution.
2. **Registered Accounts:** Users can create registered accounts (`/api/auth/register`) with email and password (hashed with bcrypt, cost 12).
3. **Session Security:**
   - 256-bit cryptographically secure session tokens (`bin2hex(random_bytes(32))`).
   - Tokens are stored in the database exclusively as **SHA-256 hashes** (`session_token_hash`), protecting active sessions against database dump compromises.
   - Cookies are issued with `HttpOnly: true`, `SameSite: Lax`, and conditional `Secure` flags.
4. **Brute-Force Lockout:** Accounts are locked for 15 minutes after 5 consecutive failed login attempts. Emergency password resets utilize a 10-character recovery code (`SMART-XXXX-XXXX`).

### Settlement Lifecycle Authorization Matrix

| Action | Permitted Actors | Description |
|---|---|---|
| **Record Settlement** | Payer, Payee, Workspace Owner | Proposes a settlement transfer (defaults to `PENDING`). |
| **Confirm Settlement** | Creditor (Payee), Workspace Owner | Verifies receipt of funds and transitions status to `CONFIRMED`. |
| **Dispute Settlement** | Creditor (Payee), Workspace Owner | Flags an unreceived or invalid payment as `DISPUTED`. |
| **Reverse Settlement** | Payer, Creditor, Workspace Owner | Reopens a confirmed settlement, restoring the debt balance. |
| **Delete Settlement** | Payer, Creditor, Workspace Owner | Soft-deletes a pending settlement record. |

> **Security Invariant:** Knowing a member ID alone is not sufficient to perform actions on their behalf. The request must originate from an authenticated session linked to that member, or supply a valid member claim token.

---

## 🛡️ Security Architecture

- **SQL Injection Defense:** All database queries utilize parameterized prepared statements via PDO with emulation disabled (`ATTR_EMULATE_PREPARES => false`).
- **HTTP Security Headers:** Centrally applied on every response:
  - `Content-Security-Policy`: Restricts scripts and styles to self/trusted CDNs; disables object execution.
  - `X-Frame-Options: DENY`: Prevents clickjacking in iframes.
  - `X-Content-Type-Options: nosniff`: Prevents MIME-type confusion attacks.
  - `Referrer-Policy: strict-origin-when-cross-origin`: Minimizes referrer leakage.
- **CSRF Defense:** State-mutating HTTP methods (`POST`, `PUT`, `DELETE`, `PATCH`) require `X-Requested-With` or `Content-Type: application/json` headers.
- **Rate Limiting:** Atomic sliding-window rate limiting in MySQL (`RateLimiterService`) protects registration (10/min), login (5/min), and mutation endpoints.
- **Upload Hardening:** Receipts are stored in `storage/receipts/` outside the web root. Files are verified via magic byte MIME validation (`finfo_buffer`) and given randomized SHA-256 filenames. Direct script execution is prohibited.
- **CSV Injection Prevention:** CSV exports are filtered via [`CsvSanitizer`](src/Utils/CsvSanitizer.php) to neutralize formula injection attacks (`=`, `+`, `-`, `@`, `\t`, `\r`).
- **Optimistic Concurrency Control (OCC):** Group and member records feature monotonic version counters (`version = version + 1`) to detect and handle concurrent mid-air collisions.
- **Automatic Deadlock Retries:** Database transactions automatically retry up to 10 times with exponential backoff and jitter upon encountering MySQL deadlock error codes `1213` or `1205`.

---

## 📁 Project Directory Structure

```text
Smartsplit/
├── config/
│   └── database.php                 # MySQL PDO connection settings
├── migrations/                      # 13 Sequential SQL schema migrations
│   ├── 001_create_initial_schema.sql
│   ├── 002_add_categories_and_features.sql
│   ├── 003_add_itemized_and_templates.sql
│   ├── 004_add_custom_categories.sql
│   ├── 005_add_multi_currency_support.sql
│   ├── 006_add_hybrid_authentication.sql
│   ├── 007_add_rate_limiting.sql
│   ├── 008_add_creator_pairing.sql
│   ├── 009_add_workspace_events.sql
│   ├── 010_add_idempotency_keys.sql
│   ├── 011_add_settlement_verification_lifecycle.sql
│   ├── 012_add_user_profile_upi.sql
│   └── 013_add_settlement_idempotency_keys.sql
├── public/                          # Public document root
│   ├── index.php                    # Front controller, router & error boundary
│   ├── favicon.svg
│   ├── manifest.json                # PWA web manifest
│   ├── sw.js                        # PWA service worker
│   └── assets/
│       ├── css/                     # Application stylesheets
│       │   ├── brand.css
│       │   └── style.css
│       └── js/                      # Vanilla ES6 SPA architecture
│           ├── app.js               # Application bootstrap & coordinator
│           ├── state.js             # Reactive Pub/Sub state store
│           ├── router.js            # Client hash router
│           ├── api.js               # HTTP API client
│           ├── theme-init.js        # Theme loader
│           ├── pwa-init.js          # Service worker registrar
│           ├── utils/               # Math, formatting, QR & SVG chart helpers
│           └── components/          # UI view components & modals
├── src/                             # Private application source
│   ├── Controllers/                 # REST API controllers
│   │   ├── ActivityController.php
│   │   ├── AuthController.php
│   │   ├── BalanceController.php
│   │   ├── CategoryController.php
│   │   ├── CurrencyController.php
│   │   ├── EventController.php
│   │   ├── ExpenseController.php
│   │   ├── GroupController.php
│   │   ├── MemberController.php
│   │   ├── ReceiptController.php
│   │   ├── RecurringController.php
│   │   ├── SettlementController.php
│   │   └── TemplateController.php
│   ├── Core/                        # Core runtime infrastructure
│   │   ├── BaseController.php
│   │   ├── Database.php             # PDO database manager & transaction wrapper
│   │   ├── Env.php                  # Environment parser
│   │   ├── Request.php              # HTTP request representation
│   │   ├── Response.php             # JSON response emitter
│   │   ├── Router.php               # Regex route dispatcher
│   │   └── Middleware/              # Security and auth middleware
│   │       ├── AuthSessionMiddleware.php
│   │       └── SecurityHeadersMiddleware.php
│   ├── Repositories/                # SQL query and persistence layer
│   ├── Services/                    # Domain logic & calculation engines
│   │   ├── ActivityLogService.php
│   │   ├── BalanceService.php
│   │   ├── CurrencyService.php
│   │   ├── EventService.php
│   │   ├── ExpenseService.php
│   │   ├── RateLimiterService.php
│   │   ├── ReceiptService.php
│   │   ├── RecurringService.php
│   │   ├── SettlementEngine.php
│   │   ├── SplitCalculator.php
│   │   └── Storage/                 # Receipt storage abstraction
│   └── Utils/
│       ├── CsvSanitizer.php         # Anti-formula injection sanitizer
│       └── Money.php                # Strict integer arithmetic utilities
├── storage/                         # Private storage directory (outside webroot)
│   └── receipts/                    # Private local receipt storage with randomized/hashed filenames
├── tests/                           # Master test suite (80 automated suites)
│   ├── run_all_tests.php            # Master test runner
│   └── ...
├── .env.example                     # Environment template
└── README.md                        # Documentation
```

---

## 📡 Core REST API Surface

All API responses follow standard JSON response envelopes:

**Success Response:**
```json
{
  "success": true,
  "data": { ... },
  "meta": { ... }
}
```

**Error Response:**
```json
{
  "status": "error",
  "error": {
    "code": "UNPROCESSABLE_ENTITY",
    "message": "Validation failed.",
    "details": null
  }
}
```

### Key API Endpoints

| Category | Method | Endpoint | Description |
|---|---|---|---|
| **Health** | `GET` | `/api/health` | System diagnostics and health status |
| **Auth** | `POST` | `/api/auth/register` | Register new user account |
| | `POST` | `/api/auth/login` | Authenticate user & issue session cookie |
| | `POST` | `/api/auth/logout` | Invalidate session token & clear cookie |
| | `GET` | `/api/auth/me` | Retrieve current authenticated user profile |
| | `POST` | `/api/auth/recover-password` | Emergency password reset via recovery code |
| **Workspaces** | `POST` | `/api/groups` | Create a new group workspace |
| | `GET` | `/api/groups/{token}` | Fetch group metadata and members |
| | `DELETE`| `/api/groups/{token}` | Delete group workspace |
| | `GET` | `/api/user/workspaces` | List claimed workspaces for logged-in user |
| **Members** | `POST` | `/api/groups/{token}/members` | Add participant to group |
| | `GET` | `/api/groups/{token}/members` | List active group members |
| | `PUT` | `/api/groups/{token}/members/{id}` | Update member profile (name, avatar) |
| | `DELETE`| `/api/groups/{token}/members/{id}` | Remove non-participating member |
| | `POST` | `/api/groups/{token}/members/{id}/claim` | Claim guest member profile to user account |
| **Expenses** | `POST` | `/api/groups/{token}/expenses` | Create expense (Equal, Exact, %, Shares, Itemized) |
| | `GET` | `/api/groups/{token}/expenses` | Search, filter, and paginate group expenses |
| | `GET` | `/api/groups/{token}/expenses/{id}` | Fetch expense details with items and receipts |
| | `PUT` | `/api/groups/{token}/expenses/{id}` | Update existing expense details and splits |
| | `DELETE`| `/api/groups/{token}/expenses/{id}` | Soft-delete expense to trash bin |
| | `PUT` | `/api/groups/{token}/expenses/{id}/restore` | Restore soft-deleted expense |
| | `GET` | `/api/groups/{token}/export.csv` | Export group expenses to sanitized CSV |
| **Balances** | `GET` | `/api/groups/{token}/balances` | Calculate net balances (asserts zero-sum) |
| | `GET` | `/api/groups/{token}/settlement-plan` | Generate greedy min-cash-flow debt plan |
| | `GET` | `/api/groups/{token}/analytics/summary` | Retrieve category breakdown and trend data |
| | `GET` | `/api/groups/{token}/activity-feed` | Fetch chronological activity audit feed |
| **Settlements** | `POST` | `/api/groups/{token}/settlements` | Propose / record settlement transfer |
| | `GET` | `/api/groups/{token}/settlements` | List settlement history |
| | `POST` | `/api/groups/{token}/settlements/{id}/confirm` | Confirm receipt of settlement payment |
| | `POST` | `/api/groups/{token}/settlements/{id}/dispute` | Dispute unreceived settlement payment |
| | `POST` | `/api/groups/{token}/settlements/{id}/reverse` | Reopen confirmed settlement & restore debt |
| | `DELETE`| `/api/groups/{token}/settlements/{id}` | Delete pending settlement |
| **Receipts** | `POST` | `/api/groups/{token}/expenses/{id}/receipts` | Upload receipt attachment (JPEG, PNG, WebP, PDF) |
| | `GET` | `/api/groups/{token}/expenses/{id}/receipts/{receiptId}/download` | Stream private receipt file |
| | `DELETE`| `/api/groups/{token}/expenses/{id}/receipts/{receiptId}` | Delete receipt attachment |

---

## 🧪 Master Test Suite & Verification

The Smart Split V2 test suite validates financial correctness, security boundaries, and concurrency stability:

```bash
# Execute the full master test runner (80 automated suites)
php tests/run_all_tests.php
```

### Verified Test Coverage Highlights
- **Mathematical Invariants:** Verification of zero-sum ledger balance conservation and Hare-Niemeyer remainder ranking.
- **State-Machine Lifecycle:** 77 assertion checks verifying state transitions (create, confirm, dispute, reverse, delete) in [tests/test_sec11_financial_lifecycle_state_machine.php](tests/test_sec11_financial_lifecycle_state_machine.php).
- **Adversarial & Authorization Tests:** Cross-workspace BOLA/IDOR matrices, race condition stress suites, and malicious parameter injection defenses.
- **Migration & Restore Safety:** Deterministic migration execution and verification in [tests/test_sec12_migration_backup_restore.php](tests/test_sec12_migration_backup_restore.php).

---

## 💱 Supported Currencies

| Code | Currency Name | Symbol |
|:---:|---|:---:|
| `INR` | Indian Rupee | ₹ |
| `USD` | US Dollar | $ |
| `EUR` | Euro | € |
| `GBP` | British Pound | £ |
| `CAD` | Canadian Dollar | CA$ |
| `AUD` | Australian Dollar | AU$ |
| `SGD` | Singapore Dollar | SG$ |
| `AED` | UAE Dirham | AED |
| `JPY` | Japanese Yen | ¥ |
| `CHF` | Swiss Franc | CHF |
| `CNY` | Chinese Yuan | ¥ |
| `NZD` | New Zealand Dollar | NZ$ |

---

## 📑 Current Architectural Boundaries & Non-Goals

- **Production Infrastructure:** The current repository represents an application-level release freeze. Production deployment has not yet been performed. Infrastructure provisioning, HTTPS/TLS termination, database provisioning, backup configuration, and deployment rehearsal remain operational steps outside the current application release freeze.
- **Real-Time Updates:** Real-time updates utilize Server-Sent Events (SSE) and HTTP polling rather than bidirectional WebSockets.
- **Single-Node Storage:** Receipt attachments are managed on local private storage (`storage/receipts/`). S3-compatible cloud object storage integration is an architectural interface supported by [`ReceiptStorageInterface`](src/Services/Storage/ReceiptStorageInterface.php), not an active default driver.
- **Rollback Strategy:** Production rollback requires an immutable deployment package and verified database backup strategy to be established during the infrastructure deployment phase.

---

## 🗺️ Planned Future Roadmap

*The following features are planned for future major releases and are not part of the frozen V2 release:*

- [ ] Multi-region S3 / cloud object storage driver implementations.
- [ ] OCR receipt scanning and automatic line-item parsing.
- [ ] Webhook notifications for external chat integrations (Telegram, Discord, Slack).
- [ ] Automated recurring expense execution via system cron worker daemon.

---

## 📚 Documentation & Audit Reports

Smart Split V2 includes detailed engineering documentation covering testing, security verification, remediation history, and release validation:

- **Testing:** [Testing Guide](docs/testing/testing_guide.md) · [E2E Architecture](docs/testing/e2e_architecture.md) · [E2E Quality Audit](docs/testing/e2e_quality_audit.md) · [E2E Verification Report](docs/testing/e2e_verification_report.md)
- **Security Audits:** [Initial Security Audit](docs/audits/initial_security_audit.md) · [Security Remediation Audit](docs/audits/security_remediation_audit.md)
- **Security Engineering:** [ACID & Auth Investigation](docs/security/acid_and_auth_investigation.md) · [ACID & Auth Remediation Ledger](docs/security/acid_and_auth_remediation_ledger.md)
- **Release Verification:** [Trust Gate Audit](docs/audits/trust_gate_audit.md) · [Post-Remediation Adversarial Security Verification](docs/audits/post_remediation_adversarial_security_verification.md)
- **Development History:** [Development Milestones](docs/history/development_milestones.md)

---

## 🔒 Security Disclosure

If you discover a security vulnerability in Smart Split, please practice responsible disclosure. Report findings with steps to reproduce to the project maintainers before public disclosure.

---

## License

Smart Split V2 is licensed under the MIT License. See the [LICENSE](LICENSE) file for the full license text.
