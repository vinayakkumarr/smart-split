/**
 * Offline action queue and sync manager.
 * Handles local mutation queuing, retries, and background sync on reconnect.
 */

import { Toast } from '../components/Toast.js';

export class OfflineManager {
    constructor(storageKey = 'smartsplit_offline_queue', deadLetterKey = 'smartsplit_failed_offline_mutations') {
        this.storageKey = storageKey;
        this.deadLetterKey = deadLetterKey;
        this.isDraining = false;
        this.listeners = new Set();
        this.failedListeners = new Set();
    }

    /**
     * Subscribe a callback to queue state changes (e.g. queue count changes).
     * @param {Function} callback Function(queueArray)
     * @returns {Function} Unsubscribe function
     */
    subscribe(callback) {
        this.listeners.add(callback);
        return () => this.listeners.delete(callback);
    }

    /**
     * Subscribe a callback to failed mutations state changes.
     * @param {Function} callback Function(failuresArray)
     * @returns {Function} Unsubscribe function
     */
    subscribeFailed(callback) {
        this.failedListeners.add(callback);
        return () => this.failedListeners.delete(callback);
    }

    /**
     * Notify all subscribers with the latest queue state.
     * @private
     */
    notify() {
        const queue = this.getQueue();
        this.listeners.forEach((fn) => {
            try { fn(queue); } catch (e) { console.error('[Offline] Listener error:', e); }
        });
    }

    /**
     * Notify all subscribers with the latest failed mutations state.
     * @private
     */
    notifyFailed() {
        const failures = this.getFailedMutations();
        this.failedListeners.forEach((fn) => {
            try { fn(failures); } catch (e) { console.error('[Offline] Failed-mutation listener error:', e); }
        });
    }

    /**
     * Retrieve all pending queued mutations.
     * @returns {Array<Object>}
     */
    getQueue() {
        if (typeof localStorage === 'undefined') return [];
        try {
            const raw = localStorage.getItem(this.storageKey);
            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    }

    /**
     * Save queue to storage and notify subscribers.
     * @param {Array<Object>} queue
     * @private
     */
    saveQueue(queue) {
        if (typeof localStorage === 'undefined') return;
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(queue));
            this.notify();
        } catch (err) {
            console.error('[Offline] Failed to save queue to localStorage:', err);
        }
    }

    /**
     * Retrieve all permanently failed (dead-letter) mutations with optional token filter.
     * @param {string|null} token
     * @returns {Array<Object>}
     */
    getFailedMutations(token = null) {
        if (typeof localStorage === 'undefined') return [];
        try {
            const raw = localStorage.getItem(this.deadLetterKey);
            const all = raw ? JSON.parse(raw) : [];
            if (!token) return all;
            return all.filter(item => item.token === token);
        } catch {
            return [];
        }
    }

    /**
     * Record a permanent failure in the dead-letter store to preserve user data.
     * @param {Object} item
     * @param {Error|Object} err
     */
    recordFailedMutation(item, err) {
        if (typeof localStorage === 'undefined') return;
        try {
            const failures = this.getFailedMutations();
            // Sanitize item: exclude internal credentials
            const sanitizedItem = {
                id: item.id,
                action: item.action,
                token: item.token,
                entityId: item.entityId ?? null,
                payload: item.payload ?? null,
                timestamp: item.timestamp ?? Date.now(),
                failedAt: Date.now(),
                errorCode: err?.code || 'VALIDATION_FAILED',
                errorMessage: err?.message || 'Operation failed server validation.',
                status: err?.status || null,
            };
            failures.push(sanitizedItem);
            localStorage.setItem(this.deadLetterKey, JSON.stringify(failures));
            this.notifyFailed();
        } catch (storageErr) {
            console.error('[Offline] Failed to record dead-letter mutation:', storageErr);
        }
    }

    /**
     * Dismiss / remove a single failed mutation by ID.
     * @param {string} id
     */
    removeFailedMutation(id) {
        if (typeof localStorage === 'undefined' || !id) return;
        try {
            const all = this.getFailedMutations();
            const updated = all.filter(item => item.id !== id);
            localStorage.setItem(this.deadLetterKey, JSON.stringify(updated));
            this.notifyFailed();
        } catch (err) {
            console.error('[Offline] Failed to remove failed mutation:', err);
        }
    }

    /**
     * Clear all failed mutations, optionally filtered by workspace token.
     * @param {string|null} token
     */
    clearFailedMutations(token = null) {
        if (typeof localStorage === 'undefined') return;
        try {
            if (!token) {
                localStorage.removeItem(this.deadLetterKey);
            } else {
                const all = this.getFailedMutations();
                const remaining = all.filter(item => item.token !== token);
                localStorage.setItem(this.deadLetterKey, JSON.stringify(remaining));
            }
            this.notifyFailed();
        } catch (err) {
            console.error('[Offline] Failed to clear failed mutations:', err);
        }
    }

    /**
     * Attempt to retry a failed mutation through the live API pipeline.
     * On success, removes it from dead-letter storage.
     * @param {string} id
     * @param {Object} api Direct ApiClient instance
     * @returns {Promise<Object>}
     */
    async retryFailedMutation(id, api) {
        const all = this.getFailedMutations();
        const item = all.find(i => i.id === id);
        if (!item) {
            throw new Error('Failed mutation not found.');
        }

        switch (item.action) {
            case 'CREATE_EXPENSE':
                await api.createExpense(item.token, item.payload, item.id);
                break;
            case 'UPDATE_EXPENSE':
                await api.updateExpense(item.token, item.entityId, item.payload);
                break;
            case 'DELETE_EXPENSE':
                await api.deleteExpense(item.token, item.entityId);
                break;
            case 'CREATE_SETTLEMENT':
                await api.createSettlement(item.token, item.payload);
                break;
            default:
                throw new Error(`Unrecognized action: ${item.action}`);
        }

        // Successfully synced: remove from failed mutations
        this.removeFailedMutation(item.id);
        return { success: true, item };
    }

    /**
     * Generate a unique client-side transaction ID.
     * @returns {string}
     */
    generateClientUuid() {
        return 'offline_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);
    }

    /**
     * Check if currently online.
     * @returns {boolean}
     */
    isOnline() {
        return typeof navigator !== 'undefined' ? navigator.onLine !== false : true;
    }

    /**
     * Enqueue an action to be executed when back online.
     * @param {Object} actionItem
     * @param {string} actionItem.action Action type: 'CREATE_EXPENSE', 'UPDATE_EXPENSE', 'DELETE_EXPENSE', 'CREATE_SETTLEMENT'
     * @param {string} actionItem.token Group invite token
     * @param {Object} actionItem.payload Action payload data
     * @param {string|number} [actionItem.entityId] Target entity ID (for update/delete)
     * @returns {Object} Enqueued action with generated idempotencyKey
     */
    enqueue({ action, token, payload, entityId = null }) {
        const queue = this.getQueue();
        const idempotencyKey = this.generateClientUuid();
        const item = {
            id: idempotencyKey,
            action,
            token,
            entityId,
            payload,
            timestamp: Date.now(),
        };

        queue.push(item);
        this.saveQueue(queue);
        return item;
    }

    /**
     * Remove an item from the queue by its ID.
     * @param {string} id
     */
    remove(id) {
        let queue = this.getQueue();
        queue = queue.filter(item => item.id !== id);
        this.saveQueue(queue);
    }

    /**
     * Check if a specific workspace has pending offline mutations.
     * @param {string} token
     * @returns {boolean}
     */
    hasPendingForToken(token) {
        if (!token) return false;
        const queue = this.getQueue();
        return queue.some(item => item.token === token);
    }

    /**
     * Get count of pending items for a workspace.
     * @param {string} token
     * @returns {number}
     */
    getPendingCount(token = null) {
        const queue = this.getQueue();
        if (!token) return queue.length;
        return queue.filter(item => item.token === token).length;
    }

    /**
     * Sequentially drain and execute all queued mutations against the live API.
     * Distinguishes transient network errors, permanent 4xx validation failures, and 401/403 auth errors.
     * 
     * @param {Object} api Direct ApiClient instance
     * @param {Function} [onWorkspaceSynced] Callback when a workspace finishes sync
     * @returns {Promise<number>} Number of successfully synced operations
     */
    async drainQueue(api, onWorkspaceSynced = null) {
        if (!this.isOnline() || this.isDraining) {
            return 0;
        }

        const queue = this.getQueue();
        if (queue.length === 0) {
            return 0;
        }

        this.isDraining = true;
        let syncedCount = 0;
        let permanentFailuresCount = 0;
        let authFailuresCount = 0;
        const syncedTokens = new Set();

        try {
            for (const item of queue) {
                try {
                    switch (item.action) {
                        case 'CREATE_EXPENSE':
                            // Pass item.id as stable server-authoritative idempotency key
                            await api.createExpense(item.token, item.payload, item.id);
                            break;
                        case 'UPDATE_EXPENSE':
                            await api.updateExpense(item.token, item.entityId, item.payload);
                            break;
                        case 'DELETE_EXPENSE':
                            await api.deleteExpense(item.token, item.entityId);
                            break;
                        case 'CREATE_SETTLEMENT':
                            await api.createSettlement(item.token, item.payload);
                            break;
                        default:
                            console.warn('[Offline] Unrecognized action type:', item.action);
                    }

                    // Remove successfully processed mutation
                    this.remove(item.id);
                    syncedCount++;
                    if (item.token) syncedTokens.add(item.token);
                } catch (err) {
                    console.error('[Offline] Failed to sync item:', item, err);

                    const status = typeof err.status === 'number' ? err.status : null;
                    const isNetworkFailure = err.code === 'NETWORK_OFFLINE' || !this.isOnline() || status === null;

                    if (isNetworkFailure || (status && status >= 500)) {
                        // 1. Transient Network / Server 5xx Outage: Preserve item, pause queue drain
                        break;
                    } else if (status === 401 || status === 403) {
                        // 2. Authorization Failure: Preserve user data, pause queue, notify user to re-authenticate
                        authFailuresCount++;
                        break;
                    } else if (status && status >= 400 && status < 500) {
                        // 3. Permanent Client / Validation / Not Found / Conflict Error (400, 404, 409, 422):
                        // Move to dead-letter store to preserve user input & prevent infinite retry loop
                        this.recordFailedMutation(item, err);
                        this.remove(item.id);
                        permanentFailuresCount++;
                        // Continue loop so subsequent valid operations are not blocked
                    } else {
                        // Unclassified error: break cautiously without dropping item
                        break;
                    }
                }
            }
        } finally {
            this.isDraining = false;
        }

        // Inform user with appropriate summary notifications
        if (syncedCount > 0) {
            Toast.success(`${syncedCount} offline ${syncedCount === 1 ? 'transaction' : 'transactions'} synced with server.`);

            if (typeof onWorkspaceSynced === 'function') {
                for (const token of syncedTokens) {
                    await onWorkspaceSynced(token);
                }
            }
        }

        if (permanentFailuresCount > 0) {
            Toast.warning(`${permanentFailuresCount} offline ${permanentFailuresCount === 1 ? 'action' : 'actions'} could not be synced due to validation errors.`);
        }

        if (authFailuresCount > 0) {
            Toast.error('Offline sync paused: authorization required. Please check your workspace credentials.');
        }

        return syncedCount;
    }
}

export const offlineManager = new OfflineManager();
