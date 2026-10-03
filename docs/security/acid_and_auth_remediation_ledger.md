# SMART SPLIT V2 — ROOT-CAUSE REMEDIATION LEDGER

**Project:** Smart Split V2  
**Date:** October 3, 2026  
**Auditor / Architect:** Principal Application Security Engineer & Financial Systems Auditor  
**Scope:** Root-Cause Architectural Remediation for Forensic Findings `RT-VULN-DB-01` and `RT-VULN-AUTH-01`

---

## 1. EXECUTIVE SUMMARY

This ledger documents the exact, surgical production code remediations implemented in Smart Split V2 to address the underlying architectural defects identified during the adversarial forensic audit.

Rather than implementing superficial, endpoint-specific patches that merely satisfy test assertions, the fixes were implemented at their foundational architectural boundaries:
1. **ACID Transaction Exception Propagation Boundary:** Resolved in `src/Core/Database.php` by importing `\Throwable`, defining `inTransaction()`, and safeguarding transaction rollback against secondary exceptions.
2. **Actor Resolution & Authentication Trust Boundary:** Resolved in `src/Controllers/SettlementController.php` by establishing a strict fail-closed validation invariant across all lifecycle actor payload parameters (`actor_member_id`, `recorded_by_member_id`, `confirmed_by_member_id`, `disputed_by_member_id`, `reversed_by_member_id`, `deleted_by_member_id`).
3. **Repository Transaction Consistency:** Refactored `softDelete()` in `src/Repositories/SettlementRepository.php` to route through the centralized `Database::transaction()` wrapper with bounded deadlock retry and automatic rollback handling.

---

## 2. SURGICAL REMEDIATION ENTRIES

### Entry 1: Root-Cause Namespace Resolution & ACID Rollback Fix
- **File:** `src/Core/Database.php`
- **Vulnerability ID:** `RT-VULN-DB-01`
- **CWE:** CWE-391 (Unchecked Error Condition), CWE-404 (Improper Resource Shutdown or Release)
- **Root Cause:** In PHP 8+, when inside `namespace App\Core;` without an explicit `use Throwable;`, an unqualified `catch (Throwable $e)` resolves to `\App\Core\Throwable`. Any PHP core exception or runtime error (implementing root `\Throwable` but not `\App\Core\Throwable`) bypassed the catch block entirely, leaving the database connection with an open, uncommitted transaction.
- **Architectural Fix:**
  1. Explicitly import `use Throwable;` at file scope.
  2. Implement `Database::inTransaction(): bool` providing a public static transaction inspection helper.
  3. Wrap the transaction rollback in a defensive secondary try/catch block to prevent connection-drop exceptions from masking the root exception.

#### Unified Diff:
```diff
--- a/src/Core/Database.php
+++ b/src/Core/Database.php
@@ -7,6 +7,7 @@ namespace App\Core;
 use PDO;
 use PDOException;
 use RuntimeException;
+use Throwable;
 
 /**
  * Thread-safe singleton PDO Database Connection Manager.
@@ -110,6 +111,16 @@ class Database
      *
      * @return bool
      */
+    public static function inTransaction(): bool
+    {
+        return self::getConnection()->inTransaction();
+    }
+
     /**
      * Determine if a Throwable is a transient MySQL deadlock or serialization failure.
@@ -162,7 +173,11 @@ class Database
                 return $result;
             } catch (Throwable $e) {
                 if ($pdo->inTransaction()) {
-                    $pdo->rollBack();
+                    try {
+                        $pdo->rollBack();
+                    } catch (Throwable $rollbackEx) {
+                        // Rollback failed (e.g. lost connection)
+                    }
                 }
 
                 // Retry only transient deadlocks/serialization failures within bounds
```

---

### Entry 2: Actor Identity Verification & Fail-Closed Authorization
- **File:** `src/Controllers/SettlementController.php`
- **Vulnerability ID:** `RT-VULN-AUTH-01`
- **CWE:** CWE-285 (Improper Authorization), CWE-639 (Authorization Bypass Through User-Controlled Key)
- **Root Cause:** 
  1. `resolveActingMemberId()` only inspected `recorded_by_member_id` and `confirmed_by_member_id`. Lifecycle transition endpoints (`/dispute`, `/reverse`, `/delete`) using `disputed_by_member_id`, `reversed_by_member_id`, or `deleted_by_member_id` were ignored and fell back silently to `$defaultMemberId` (creditor/payee), allowing debtor/bystander spoofing.
  2. When an invalid, malicious, or foreign member ID was supplied, the method silently ignored the payload and defaulted to the creditor instead of failing closed.
  3. `delete()` lacked explicit participant / owner authorization verification.
- **Architectural Fix:**
  1. Centralized actor resolution to check all candidate keys: `actor_member_id`, `recorded_by_member_id`, `confirmed_by_member_id`, `disputed_by_member_id`, `reversed_by_member_id`, `deleted_by_member_id`.
  2. Enforced strict validation against the workspace's active member roster. If a client provides any actor parameter that is not a member of the group, immediately throw `InvalidArgumentException("Declared acting member does not belong to this group.", 422)` (Zero silent fallbacks!).
  3. Enforced participant / workspace owner authorization in `delete()`.

#### Unified Diff:
```diff
--- a/src/Controllers/SettlementController.php
+++ b/src/Controllers/SettlementController.php
@@ -342,6 +342,15 @@ class SettlementController extends BaseController
         $body = $request->getBody();
         $actingMemberId = $this->resolveActingMemberId($request, (int) $group['id'], $groupMembers, $body, (int) $settlement['payer']['id']);
 
+        $isParticipant = ($actingMemberId === (int) $settlement['payer']['id'] || $actingMemberId === (int) $settlement['payee']['id']);
+        $currentUser = $request->getUser();
+        $isOwner = $currentUser && $group['owner_user_id'] && ((int) $group['owner_user_id'] === (int) $currentUser['id']);
+
+        if (!$isParticipant && !$isOwner) {
+            $this->error("Only the settlement participants or workspace owner can delete a settlement.", 'FORBIDDEN', null, 403);
+            return;
+        }
+
         $deleted = $this->settlementRepo->softDelete($settlementId, $actingMemberId);
         \App\Services\EventService::broadcast((int) $group['id'], 'settlement.deleted', $settlementId);
         $updatedBalances = $this->balanceService->calculateGroupBalances((int) $group['id']);
@@ -354,6 +363,23 @@ class SettlementController extends BaseController
 
     /**
      * Helper to resolve the authenticated / acting member ID.
+     *
+     * Invariants:
+     * 1. If an authenticated user exists and belongs to the group, return their member ID.
+     * 2. If an actor claim is supplied in the request body (e.g. 'actor_member_id',
+     *    'recorded_by_member_id', 'confirmed_by_member_id', 'disputed_by_member_id',
+     *    'reversed_by_member_id', 'deleted_by_member_id'), validate that it belongs to this group.
+     *    If valid, return it.
+     *    If invalid / not in group roster, FAIL CLOSED with 422 InvalidArgumentException (NO SILENT FALLBACK).
+     * 3. Otherwise, if no client claim is provided, return $defaultMemberId (provided it is valid in this group).
+     *
+     * @param Request $request
+     * @param int $groupId
+     * @param array<int, array<string, mixed>> $groupMembers
+     * @param array<string, mixed> $body
+     * @param int $defaultMemberId
+     * @return int
+     * @throws InvalidArgumentException If a declared acting member does not belong to the group.
      */
     private function resolveActingMemberId(
         Request $request,
@@ -371,21 +397,24 @@ class SettlementController extends BaseController
             }
         }
 
-        if (isset($body['recorded_by_member_id'])) {
-            $recId = (int) $body['recorded_by_member_id'];
-            foreach ($groupMembers as $m) {
-                if ((int) $m['id'] === $recId) {
-                    return $recId;
-                }
-            }
-        }
-
-        if (isset($body['confirmed_by_member_id'])) {
-            $confId = (int) $body['confirmed_by_member_id'];
-            foreach ($groupMembers as $m) {
-                if ((int) $m['id'] === $confId) {
-                    return $confId;
-                }
+        $candidateKeys = [
+            'actor_member_id',
+            'recorded_by_member_id',
+            'confirmed_by_member_id',
+            'disputed_by_member_id',
+            'reversed_by_member_id',
+            'deleted_by_member_id',
+        ];
+
+        $validMemberIds = array_map(fn($m) => (int) $m['id'], $groupMembers);
+
+        foreach ($candidateKeys as $key) {
+            if (isset($body[$key]) && $body[$key] !== '' && $body[$key] !== null) {
+                $claimedId = (int) $body[$key];
+                if (in_array($claimedId, $validMemberIds, true)) {
+                    return $claimedId;
+                }
+                throw new InvalidArgumentException("Declared acting member does not belong to this group.", 422);
             }
         }
 
```

---

### Entry 3: Centralized ACID Transaction Wrapper in Repository
- **File:** `src/Repositories/SettlementRepository.php`
- **Component:** `softDelete()` method
- **Architectural Fix:** Standardized `softDelete()` to execute inside `Database::transaction(...)` rather than calling raw `$this->pdo->beginTransaction()`, aligning with all other repository mutation methods (`create`, `confirm`, `dispute`, `reverse`).

#### Unified Diff:
```diff
--- a/src/Repositories/SettlementRepository.php
+++ b/src/Repositories/SettlementRepository.php
@@ -430,9 +430,8 @@ class SettlementRepository
             return false;
         }
 
-        $this->pdo->beginTransaction();
-        try {
-            $stmt = $this->pdo->prepare("
+        return Database::transaction(function (PDO $pdo) use ($settlementId, $settlement, $actorMemberId): bool {
+            $stmt = $pdo->prepare("
                 UPDATE `settlements`
                 SET `is_deleted` = 1
                 WHERE `id` = :id
@@ -452,17 +451,13 @@ class SettlementRepository
             );
 
             // Increment group version
-            $versionStmt = $this->pdo->prepare("
-                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
-            ");
-            $versionStmt->execute([':group_id' => (int) $settlement['group_id']]);
-
-            $this->pdo->commit();
-            return true;
-        } catch (Throwable $e) {
-            $this->pdo->rollBack();
-            throw $e;
-        }
+            $versionStmt = $pdo->prepare("
+                UPDATE `groups` SET `version` = `version` + 1 WHERE `id` = :group_id
+            ");
+            $versionStmt->execute([':group_id' => (int) $settlement['group_id']]);
+
+            return true;
+        });
     }
```

---

## 3. INVARIANTS CONSERVED

1. **Mathematical Financial Ledger Invariants:**
   - $\sum \text{Member Net Balances} \equiv 0$ (Paise-level zero-sum conservation).
   - Authoritative balances exclude all `PENDING`, `DISPUTED`, and `REVERSED` settlements.
   - Only `CONFIRMED` settlements alter member net balances and simplified debt graph calculations.
2. **Workspace Owner Authority:**
   - Workspace creators / owners maintain authorized override capabilities on `/confirm`, `/dispute`, `/reverse`, and `/delete` when authenticated.
3. **Creditor Fast-Path:**
   - Creditor-initiated direct settlements transition instantly to `CONFIRMED` with full attribution.
4. **Fail-Closed Security:**
   - Any actor declaration outside the workspace's member roster is rejected with HTTP 422 Unprocessable Entity, preventing silent privilege escalation.
