# SMART SPLIT V2 — ROOT CAUSE FORENSIC INVESTIGATION REPORT

**Date:** 2026-10-03  
**Status:** FORENSIC INVESTIGATION COMPLETE — READY FOR ARCHITECTURAL REMEDIATION  
**Investigators:** Principal Application Security Engineer & Systems Architect  
**Scope:** Investigation of `RT-VULN-DB-01` (ACID Rollback Failure) and `RT-VULN-AUTH-01` (Settlement Actor Authorization Bypass).

---

## 1. FINDING A: DATABASE TRANSACTION ROLLBACK FAILURE (`RT-VULN-DB-01`)

### 1.1 Exact Vulnerable Code Path & Root Cause
* **File:** `src/Core/Database.php`
* **Vulnerable Lines:** Lines 5, 120, 165.
* **Mechanism:**
  [`src/Core/Database.php`](../../src/Core/Database.php) defines `namespace App\Core;` and imports `use PDO; use PDOException; use RuntimeException;`, but **fails to import `use Throwable;`** or use fully-qualified `\Throwable`.
  In PHP:
  ```php
  namespace App\Core;
  // ...
  public static function isDeadlock(Throwable $e): bool // Evaluates to \App\Core\Throwable $e
  // ...
  try {
      $result = $callback($pdo);
      $pdo->commit();
      return $result;
  } catch (Throwable $e) { // Evaluates to catch (\App\Core\Throwable $e)
      if ($pdo->inTransaction()) {
          $pdo->rollBack();
      }
      throw $e;
  }
  ```
  Because `\App\Core\Throwable` does not exist, any standard exception (`\RuntimeException`, `\InvalidArgumentException`, `\PDOException`, `\TypeError`, `\Error`) thrown inside `$callback($pdo)` **completely bypasses the `catch (Throwable $e)` block**.

### 1.2 Exact Runtime & Database Behavior
1. `Database::beginTransaction()` starts a physical InnoDB transaction (`inTransaction === true`).
2. The user callback executes queries (e.g. `UPDATE settlements SET status = 'CONFIRMED'`).
3. An exception or error is thrown inside the callback before completion.
4. PHP skips the namespaced `catch (\App\Core\Throwable $e)` block.
5. `$pdo->rollBack()` is **never called**.
6. The exception bubbles out to the HTTP/CLI error handler.
7. The singleton connection returned by `Database::getConnection()` remains in an uncommitted transaction state (`inTransaction === true`).
8. On subsequent database calls on the same connection:
   - Line 152 checks `if ($pdo->inTransaction()) { return $callback($pdo); }`.
   - The subsequent transaction believes an outer transaction is managing it, so it executes without initiating a new transaction.
   - If a subsequent query calls `commit()`, the failed writes from the previous transaction are **accidentally committed into the database**!

### 1.3 Exact Reproduction Steps & Proof
```php
require_once 'tests/test_bootstrap.php';
$pdo = \App\Core\Database::getConnection();

try {
    \App\Core\Database::transaction(function(PDO $db) {
        $db->exec("UPDATE settlements SET status = 'CONFIRMED' WHERE id = 123");
        throw new RuntimeException("Simulated catastrophic crash");
    });
} catch (\Throwable $e) {
    echo "Caller caught: " . $e->getMessage() . "\n";
    echo "inTransaction on singleton: " . var_export($pdo->inTransaction(), true) . "\n";
    // Output: inTransaction on singleton: true (TRANSACTION LEAKED)
}
```

### 1.4 Why Previous Tests Failed to Detect It
Previous test suites tested the "happy path" where transactions succeeded and committed. When failure paths were tested, test runners typically executed in separate processes or did not inspect the persistent state of `$pdo->inTransaction()` on the singleton connection after an exception was thrown.

### 1.5 Systemic Pattern Audit
We audited all 20+ occurrences of `Throwable` and `catch` in the codebase:
| File | Occurrence | Namespaced / Global | Risk Status |
| :--- | :--- | :---: | :---: |
| `src/Core/Database.php` | `catch (Throwable $e)`, `isDeadlock(Throwable $e)` | **`\App\Core\Throwable` (UNIMPORTED)** | 🚨 **CRITICAL VULNERABILITY** |
| `src/Controllers/GroupController.php` | `catch (Throwable $e)` | `use Throwable;` imported at line 14 | Safe |
| `src/Controllers/AuthController.php` | `catch (\Throwable $e)` | Fully qualified `\Throwable` | Safe |
| `src/Repositories/GroupRepository.php` | `catch (\Throwable $e)` | Fully qualified `\Throwable` | Safe |
| `src/Repositories/SettlementRepository.php` | `catch (Throwable $e)` | `use Throwable;` imported at line 10 | Safe |
| `src/Repositories/ExpenseRepository.php` | `@throws Throwable` | `use Throwable;` imported at line 9 | Safe |
| `src/Services/RateLimiterService.php` | `catch (\Throwable $e)` | Fully qualified `\Throwable` | Safe |
| `src/Services/RecurringService.php` | `catch (\Throwable $e)` | Fully qualified `\Throwable` | Safe |

### 1.6 Proposed Root-Cause Remediation
1. Add `use Throwable;` to `src/Core/Database.php`.
2. Fully qualify `\Throwable` in typehints and catch blocks in `Database.php`.
3. Add `public static function inTransaction(): bool` method to `Database.php` to fix missing method under docblock.
4. Wrap `$pdo->rollBack()` in a secondary try-catch to ensure that even if rollback fails (e.g. database connection lost), the connection is reset and the original exception is preserved.
5. Standardize all repositories (`GroupRepository::deleteGroupCascade`, `SettlementRepository::softDelete`, `GroupController::create`) to use `Database::transaction(...)` rather than manual `beginTransaction()` calls.

### 1.7 Rejected Superficial Fixes
- *Rejected:* Simply adding `use Throwable;` without hardening `Database::transaction` against commit/rollback exceptions or connection resets.
- *Rejected:* Catching only `\Exception` (would miss PHP `\Error` and `\TypeError`).

---

## 2. FINDING B: SETTLEMENT ACTOR RESOLUTION & AUTHORIZATION BYPASS (`RT-VULN-AUTH-01`)

### 2.1 Exact Vulnerable Code Path & Root Cause
* **File:** `src/Controllers/SettlementController.php`
* **Vulnerable Lines:** Lines 220, 273, 356–395 (`resolveActingMemberId`).
* **Mechanism:**
  In [`SettlementController.php`](../../src/Controllers/SettlementController.php), `resolveActingMemberId` only checks `$body['recorded_by_member_id']` and `$body['confirmed_by_member_id']`.
  When `POST /dispute` or `POST /reverse` is called:
  ```php
  $actingMemberId = $this->resolveActingMemberId($request, (int) $group['id'], $groupMembers, $body, (int) $settlement['payee']['id']);
  ```
  If a caller supplies `disputed_by_member_id` or `reversed_by_member_id` (or sends a member ID from a different group), `resolveActingMemberId` fails to match the unrecognized key and falls back to `$defaultMemberId`, which is hardcoded as `(int) $settlement['payee']['id']` (the Creditor).
  As a result:
  ```php
  $isCreditor = ($actingMemberId === (int) $settlement['payee']['id']); // Evaluates to TRUE!
  ```
  Any caller (debtor, bystander, or foreign actor) is automatically granted Creditor authority to dispute or reverse settlements.

### 2.2 Exact Authorization Invariants & Matrix

| Lifecycle Action | Allowed Actors | Invariant Rule |
| :--- | :--- | :--- |
| **Record Settlement** | Payer, Payee, or Workspace Owner | Payer & Payee must be distinct active group members. If recorded by Debtor, initial status is `PENDING`. If recorded by Creditor, initial status is `CONFIRMED`. |
| **Confirm Settlement** | Creditor (`payee_id`) or Workspace Owner | Status must be `PENDING`. Actor must be Creditor or Owner. |
| **Dispute Settlement** | Creditor (`payee_id`) or Workspace Owner | Status must be `PENDING`. Actor must be Creditor or Owner. |
| **Reverse Settlement** | Payer (`payer_id`), Creditor (`payee_id`), or Workspace Owner | Status must be `CONFIRMED`. Actor must be Participant or Owner. Reason is required/validated. |
| **Delete / Undo** | Payer, Creditor, or Workspace Owner | Settlement must not already be deleted. |

### 2.3 Proposed Root-Cause Remediation
1. Centralize and strict-validate actor identity in `resolveActingMemberId()`:
   - Priority 1: If session has authenticated user (`$request->getUser()`), resolve member matching `user_id`. If user is Workspace Owner, owner authority is derived server-side.
   - Priority 2: Extract explicit client-declared actor key based on endpoint context:
     - `recorded_by_member_id` (on `create`)
     - `confirmed_by_member_id` (on `confirm`)
     - `disputed_by_member_id` (on `dispute`)
     - `reversed_by_member_id` (on `reverse`)
     - `deleted_by_member_id` (on `delete`)
   - Priority 3: Validate that the declared actor ID exists in `$groupMembers`. If an actor ID is declared but **does NOT exist in the workspace roster, immediately throw `422 Unprocessable Entity`** (Never fall back!).
   - Priority 4: If no actor ID is declared in guest mode, use the explicit contextual default ONLY for unauthenticated anonymous guest requests without contradictory claims.
2. In `dispute()` and `reverse()`, enforce strict validation so debtor/bystanders cannot dispute or reverse without proper authorization.

### 2.4 Rejected Superficial Fixes
- *Rejected:* Just adding `disputed_by_member_id` to the if-else chain without throwing a `422` error when a foreign or invalid member ID is passed.
- *Rejected:* Disabling guest workspace settlement capabilities entirely (violates product design for guest workspaces).
