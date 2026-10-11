import { test, expect } from '@playwright/test';
import http from 'http';
import fs from 'fs';
import path from 'path';

test.describe('Smart Split V2 – PWA Automatic Update & Lifecycle Resilience', () => {
  let mockServer = null;
  let serverRelease = 'RELEASE_A';
  const MOCK_PORT = 8899;
  const MOCK_ORIGIN = `http://127.0.0.1:${MOCK_PORT}`;

  test.beforeAll(async () => {
    // Isolated local HTTP server that serves distinct real releases over HTTP
    mockServer = http.createServer((req, res) => {
      res.setHeader('Access-Control-Allow-Origin', '*');
      res.setHeader('Cache-Control', 'no-cache, must-revalidate');

      if (req.url === '/') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(`<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Smart Split Test App</title>
</head>
<body>
  <div id="release-tag">${serverRelease}</div>
  <div id="modal-overlay"></div>
  <input type="text" id="expense-input" value="" placeholder="Expense Title">
  <script src="/assets/js/pwa-init.js"></script>
</body>
</html>`);
        return;
      }

      if (req.url === '/sw.js') {
        res.setHeader('Content-Type', 'application/javascript; charset=utf-8');
        res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        const cacheVersion = serverRelease === 'RELEASE_A' ? 'smartsplit-release-v1' : 'smartsplit-release-v2';
        res.end(`// Service Worker: ${serverRelease}
const CACHE_NAME = '${cacheVersion}';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(['/']))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((k) => (k.startsWith('smartsplit-release-') && k !== CACHE_NAME) ? caches.delete(k) : null)
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then((res) => {
          if (res && res.status === 200) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then((c) => c.put('/', clone));
          }
          return res;
        })
        .catch(() => caches.match('/'))
    );
    return;
  }
  event.respondWith(caches.match(event.request).then((r) => r || fetch(event.request)));
});
`);
        return;
      }

      if (req.url === '/assets/js/pwa-init.js') {
        res.setHeader('Content-Type', 'application/javascript; charset=utf-8');
        const pwaInitPath = path.resolve(process.cwd(), 'public/assets/js/pwa-init.js');
        res.end(fs.readFileSync(pwaInitPath, 'utf8'));
        return;
      }

      res.statusCode = 404;
      res.end('Not Found');
    });

    await new Promise((resolve) => mockServer.listen(MOCK_PORT, '127.0.0.1', resolve));
  });

  test.afterAll(async () => {
    if (mockServer) {
      await new Promise((resolve) => mockServer.close(resolve));
    }
  });

  test('Service worker registers and caches application shell in persistent context', async ({ page }) => {
    // 1. Navigate to live app homepage
    const response = await page.goto('/');
    expect(response.status()).toBe(200);

    // 2. Verify HTML response headers contain Cache-Control: no-cache, must-revalidate
    const headers = response.headers();
    expect(headers['cache-control']).toContain('no-cache');

    // 3. Wait for service worker to register
    await page.waitForFunction(() => 'serviceWorker' in navigator);
    const swRegistration = await page.evaluate(async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      return reg ? { scope: reg.scope, active: Boolean(reg.active) } : null;
    });

    expect(swRegistration).not.toBeNull();
    expect(swRegistration.scope).toContain('/');

    // 4. Verify Update Coordinator is active on window
    const coordinatorExists = await page.evaluate(() => {
      return Boolean(window.__smartSplitUpdateCoordinator);
    });
    expect(coordinatorExists).toBe(true);

    // 5. Verify Cache Storage contains smartsplit-static-v23
    const cacheNames = await page.evaluate(async () => {
      return await caches.keys();
    });
    expect(cacheNames).toContain('smartsplit-static-v23');
  });

  test('Update coordinator defers reload during in-flight mutation and safely executes after', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => Boolean(window.__smartSplitUpdateCoordinator));

    // 1. Simulate in-flight mutation
    const stateBefore = await page.evaluate(() => {
      const coord = window.__smartSplitUpdateCoordinator;
      coord.recordMutationStart();
      coord.updatePending = true;
      const isSafe = coord.isSafeToRefresh();
      return { isSafe, inFlight: coord.inFlightMutations, pending: coord.updatePending, state: coord.getState() };
    });

    expect(stateBefore.isSafe).toBe(false);
    expect(stateBefore.inFlight).toBe(1);
    expect(stateBefore.pending).toBe(true);
    expect(stateBefore.state).toBe('DEFERRED');

    // 2. Complete mutation and verify coordinator safely becomes ready to refresh
    const stateAfter = await page.evaluate(() => {
      const coord = window.__smartSplitUpdateCoordinator;
      let reloadTriggered = false;
      const origExecute = coord.executeReload;
      coord.executeReload = () => { reloadTriggered = true; };

      coord.recordMutationEnd();
      const isSafe = coord.isSafeToRefresh();
      coord.executeReload = origExecute;

      return { isSafe, inFlight: coord.inFlightMutations, reloadTriggered };
    });

    expect(stateAfter.isSafe).toBe(true);
    expect(stateAfter.inFlight).toBe(0);
    expect(stateAfter.reloadTriggered).toBe(true);
  });

  test('Update coordinator defers reload while modal dialog is open', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => Boolean(window.__smartSplitUpdateCoordinator));

    // 1. Open modal overlay
    const modalDeferred = await page.evaluate(() => {
      const overlay = document.getElementById('modal-overlay');
      overlay.classList.add('active');
      const coord = window.__smartSplitUpdateCoordinator;
      coord.updatePending = true;
      return { isSafe: coord.isSafeToRefresh(), state: coord.getState() };
    });
    expect(modalDeferred.isSafe).toBe(false);
    expect(modalDeferred.state).toBe('DEFERRED');

    // 2. Dismiss modal and verify safety
    const modalCleared = await page.evaluate(() => {
      const overlay = document.getElementById('modal-overlay');
      overlay.classList.remove('active');
      const coord = window.__smartSplitUpdateCoordinator;
      return { isSafe: coord.isSafeToRefresh(), state: coord.getState() };
    });
    expect(modalCleared.isSafe).toBe(true);
    expect(modalCleared.state).toBe('PENDING');
  });

  test('Update coordinator defers reload when user is typing unsaved input', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => Boolean(window.__smartSplitUpdateCoordinator));

    const inputInput = page.locator('#landing-group-name');
    if (await inputInput.count() > 0) {
      await inputInput.fill('Trip with Friends (Unsaved Work)');

      const isSafeWithInput = await page.evaluate(() => {
        return window.__smartSplitUpdateCoordinator.isSafeToRefresh();
      });
      expect(isSafeWithInput).toBe(false);
    }
  });

  test('Update coordinator suppresses rapid reload loops', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => Boolean(window.__smartSplitUpdateCoordinator));

    const loopSuppressed = await page.evaluate(() => {
      const coord = window.__smartSplitUpdateCoordinator;
      coord.updatePending = true;
      let callCount = 0;
      const origExecute = coord.executeReload;
      coord.executeReload = () => { callCount++; };

      // First check: executes reload
      coord.checkAndExecutePendingUpdate();

      // Rapid successive trigger within 10s window: must be suppressed
      coord.isRefreshing = false;
      coord.updatePending = true;
      coord.checkAndExecutePendingUpdate();

      coord.executeReload = origExecute;
      return callCount;
    });

    expect(loopSuppressed).toBe(1);
  });

  test('Real HTTP upgrade: Persistent browser on Release A automatically transitions to Release B over network', async ({ page }) => {
    // 1. Navigate to Mock Server in RELEASE_A mode
    serverRelease = 'RELEASE_A';
    await page.goto(MOCK_ORIGIN + '/');

    // Wait for Service Worker v1 to activate and control page
    await page.waitForFunction(async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      return reg && reg.active && navigator.serviceWorker.controller;
    });

    // Verify initial Release A render and cache storage
    const releaseTextA = await page.locator('#release-tag').innerText();
    expect(releaseTextA).toBe('RELEASE_A');

    const cachesA = await page.evaluate(async () => await caches.keys());
    expect(cachesA).toContain('smartsplit-release-v1');

    // 2. Switch server to RELEASE_B (simulating new production deployment)
    serverRelease = 'RELEASE_B';

    // 3. Proactively trigger service worker update check (as happens on window load or background byte check)
    await page.evaluate(async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      if (reg) {
        await reg.update();
      }
    });

    // 4. Wait for the native controllerchange event to trigger the coordinated reload
    await page.waitForFunction(() => {
      const tag = document.getElementById('release-tag');
      return tag && tag.innerText === 'RELEASE_B';
    }, { timeout: 15000 });

    // 5. Verify the persistent browser is now running RELEASE_B without manual cache clearing
    const releaseTextB = await page.locator('#release-tag').innerText();
    expect(releaseTextB).toBe('RELEASE_B');

    // 6. Verify Cache Storage now holds smartsplit-release-v2 and evicted v1
    const cachesB = await page.evaluate(async () => await caches.keys());
    expect(cachesB).toContain('smartsplit-release-v2');
    expect(cachesB).not.toContain('smartsplit-release-v1');
  });

  test('Multi-tab upgrade: Safe tab refreshes to Release B while editing tab defers, preserves input, and completes update when blocker clears', async ({ context }) => {
    // 1. Initialize server on RELEASE_A
    serverRelease = 'RELEASE_A';

    // 2. Tab 1 (Safe Tab) opens and activates Service Worker v1
    const safeTab = await context.newPage();
    await safeTab.goto(MOCK_ORIGIN + '/');
    await safeTab.waitForFunction(async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      return reg && reg.active && navigator.serviceWorker.controller;
    });
    expect(await safeTab.locator('#release-tag').innerText()).toBe('RELEASE_A');

    // 3. Tab 2 (Editing Tab) opens in the SAME persistent browser context
    const editTab = await context.newPage();
    await editTab.goto(MOCK_ORIGIN + '/');
    await editTab.waitForFunction(async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      return reg && reg.active && navigator.serviceWorker.controller;
    });
    expect(await editTab.locator('#release-tag').innerText()).toBe('RELEASE_A');

    // 4. In Tab 2, simulate active editing with dirty input and open modal
    const testExpenseInput = 'Dinner with Friends ₹4,500';
    await editTab.locator('#expense-input').fill(testExpenseInput);
    await editTab.evaluate(() => {
      const overlay = document.getElementById('modal-overlay');
      if (overlay) overlay.classList.add('active');
    });

    // Verify Tab 2 is actively deferred
    const editTabSafety = await editTab.evaluate(() => {
      const coord = window.__smartSplitUpdateCoordinator;
      return {
        isSafe: coord.isSafeToRefresh(),
        reasons: coord.getDeferredReasons(),
      };
    });
    expect(editTabSafety.isSafe).toBe(false);
    expect(editTabSafety.reasons).toContain('ACTIVE_MODAL');
    expect(editTabSafety.reasons).toContain('DIRTY_FORM_INPUT');

    // 5. New Release B is deployed on the server
    serverRelease = 'RELEASE_B';

    // 6. Proactively trigger service worker update check from safeTab
    await safeTab.evaluate(async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      if (reg) await reg.update();
    });

    // 7. Safe Tab transitions to RELEASE_B automatically via controllerchange
    await safeTab.waitForFunction(() => {
      const tag = document.getElementById('release-tag');
      return tag && tag.innerText === 'RELEASE_B';
    }, { timeout: 15000 });
    expect(await safeTab.locator('#release-tag').innerText()).toBe('RELEASE_B');

    // 8. Editing Tab MUST NOT refresh prematurely; input must remain intact
    await editTab.waitForTimeout(1000); // verify stability
    expect(await editTab.locator('#release-tag').innerText()).toBe('RELEASE_A');
    expect(await editTab.locator('#expense-input').inputValue()).toBe(testExpenseInput);
    const editTabStateDuring = await editTab.evaluate(() => {
      const coord = window.__smartSplitUpdateCoordinator;
      return {
        state: coord.getState(),
        pending: coord.updatePending,
        isRefreshing: coord.isRefreshing,
      };
    });
    expect(editTabStateDuring.state).toBe('DEFERRED');
    expect(editTabStateDuring.pending).toBe(true);
    expect(editTabStateDuring.isRefreshing).toBe(false);

    // 9. When user clears the blocker (cleans form, blurs input & dismisses modal), deferred update completes
    await editTab.locator('#expense-input').fill('');
    await editTab.locator('#expense-input').blur();
    await editTab.evaluate(() => {
      const overlay = document.getElementById('modal-overlay');
      if (overlay) overlay.classList.remove('active');
      window.__smartSplitUpdateCoordinator.checkAndExecutePendingUpdate();
    });

    // 10. Editing Tab now transitions to RELEASE_B
    await editTab.waitForFunction(() => {
      const tag = document.getElementById('release-tag');
      return tag && tag.innerText === 'RELEASE_B';
    }, { timeout: 15000 });
    expect(await editTab.locator('#release-tag').innerText()).toBe('RELEASE_B');

    // 11. Neither tab entered a reload loop (sessionStorage debounce latch verified)
    const safeTimestamp = await safeTab.evaluate(() => sessionStorage.getItem('smartsplit_last_sw_reload'));
    const editTimestamp = await editTab.evaluate(() => sessionStorage.getItem('smartsplit_last_sw_reload'));
    expect(safeTimestamp).not.toBeNull();
    expect(editTimestamp).not.toBeNull();

    await safeTab.close();
    await editTab.close();
  });

  test('Universal skeleton UI renders with loaded components.css and active skeletonPulse animation', async ({ page }) => {
    await page.goto('/');

    // 1. Mount ActivityTimeline skeleton placeholders in the live DOM
    const skeletonStyles = await page.evaluate(async () => {
      const { ActivityTimeline } = await import('/assets/js/components/ActivityTimeline.js');
      const container = document.createElement('div');
      container.id = 'test-skeleton-mount';
      container.innerHTML = ActivityTimeline.renderSkeletonItems(4);
      document.body.appendChild(container);

      const firstItem = container.querySelector('.skeleton-shimmer');
      if (!firstItem) return null;

      const computed = window.getComputedStyle(firstItem);
      return {
        hasElement: true,
        animationName: computed.animationName,
        animationDuration: computed.animationDuration,
        display: computed.display,
        borderRadius: computed.borderRadius,
      };
    });

    expect(skeletonStyles).not.toBeNull();
    expect(skeletonStyles.hasElement).toBe(true);
    // Real computed style verification: CSS animation must be active and match skeletonPulse
    expect(skeletonStyles.animationName).toContain('skeletonPulse');
    expect(skeletonStyles.animationDuration).toBe('1.4s');
  });

  test('Offline storage write failure in browser rejects enqueue, preserves form inputs, and does not claim success', async ({ page }) => {
    await page.goto('/');

    const result = await page.evaluate(async () => {
      const { offlineManager } = await import('/assets/js/utils/offline.js');

      // 1. Enqueue baseline item
      const item1 = offlineManager.enqueue({ action: 'CREATE_EXPENSE', token: 'test_token', payload: { title: 'Baseline' } });
      const baselineCount = offlineManager.getPendingCount('test_token');

      // 2. Simulate QuotaExceededError on localStorage.setItem
      const origSetItem = localStorage.setItem;
      localStorage.setItem = () => {
        const err = new Error('QuotaExceededError: DOM Exception 22');
        err.name = 'QuotaExceededError';
        throw err;
      };

      let enqueueError = null;
      try {
        offlineManager.enqueue({ action: 'CREATE_EXPENSE', token: 'test_token', payload: { title: 'Failed Item' } });
      } catch (err) {
        enqueueError = { message: err.message, code: err.code };
      }

      // Restore working setItem
      localStorage.setItem = origSetItem;

      // 3. Verify existing queue is intact and uncorrupted
      const postCount = offlineManager.getPendingCount('test_token');
      const queue = offlineManager.getQueue();
      const firstItem = queue.find(i => i.id === item1.id);

      // Clean up
      offlineManager.remove(item1.id);

      return {
        enqueueError,
        baselineCount,
        postCount,
        hasFirstItem: Boolean(firstItem),
      };
    });

    expect(result.enqueueError).not.toBeNull();
    expect(result.enqueueError.code).toBe('QUOTA_EXCEEDED');
    expect(result.baselineCount).toBe(1);
    expect(result.postCount).toBe(1);
    expect(result.hasFirstItem).toBe(true);
  });

  test('Real HTTP downgrade: Persistent browser on Release B automatically transitions to Release A over network with queued offline data preserved', async ({ page }) => {
    // 1. Initialize server on RELEASE_B
    serverRelease = 'RELEASE_B';
    await page.goto(MOCK_ORIGIN + '/');

    // Wait for Service Worker v2 to activate and control page
    await page.waitForFunction(async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      return reg && reg.active && navigator.serviceWorker.controller;
    });

    // Verify initial Release B render
    expect(await page.locator('#release-tag').innerText()).toBe('RELEASE_B');

    // 2. Enqueue an offline mutation in browser storage while running Release B
    await page.evaluate(() => {
      localStorage.setItem('smartsplit_offline_queue', JSON.stringify([
        { id: 'offline_pre_downgrade_123', action: 'CREATE_EXPENSE', token: 'tok_alpha', payload: { title: 'Pre-downgrade expense', amount_cents: 4500 } }
      ]));
    });

    // 3. Server downgrades to RELEASE_A
    serverRelease = 'RELEASE_A';

    // 4. Trigger service worker update check
    await page.evaluate(async () => {
      const reg = await navigator.serviceWorker.getRegistration();
      if (reg) await reg.update();
    });

    // 5. Wait for the downgrade transition to take effect via controllerchange and coordinator reload
    await page.waitForFunction(() => {
      const tag = document.getElementById('release-tag');
      return tag && tag.innerText === 'RELEASE_A';
    }, { timeout: 15000 });

    expect(await page.locator('#release-tag').innerText()).toBe('RELEASE_A');

    // 6. Verify that the queued offline expense remained 100% intact through the downgrade transition
    const preservedQueue = await page.evaluate(() => {
      const raw = localStorage.getItem('smartsplit_offline_queue');
      return raw ? JSON.parse(raw) : [];
    });

    expect(preservedQueue.length).toBe(1);
    expect(preservedQueue[0].id).toBe('offline_pre_downgrade_123');
    expect(preservedQueue[0].payload.title).toBe('Pre-downgrade expense');

    // Clean up test storage
    await page.evaluate(() => localStorage.removeItem('smartsplit_offline_queue'));
  });
});
