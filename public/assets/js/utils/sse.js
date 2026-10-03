/**
 * Smart Split – Real-Time Server-Sent Events (SSE) Synchronization Client
 * 
 * Manages native EventSource lifecycle per active workspace, handles auto-reconnection,
 * debounces event notifications, and provides a clean pub/sub hook for workspace live-sync.
 */

export class SSEManager {
    constructor() {
        this.eventSource = null;
        this.currentToken = null;
        this.debounceTimer = null;
        this.reconnectTimer = null;
        this.retryCount = 0;
        this.maxRetries = 8;
        this.baseDelay = 1000;
        this.isExplicitlyClosed = false;
        this.listeners = new Set();
    }

    /**
     * Subscribe a callback to all received workspace events.
     * @param {Function} callback Function(eventPayload)
     * @returns {Function} Unsubscribe cleanup function
     */
    subscribe(callback) {
        this.listeners.add(callback);
        return () => this.listeners.delete(callback);
    }

    /**
     * Connect to a group's SSE stream.
     * @param {string} token Group invite token
     * @param {Function} [onEventCallback] Optional direct callback when an event arrives
     */
    connect(token, onEventCallback = null) {
        if (!token) return;

        // If already connected to this workspace, avoid reconnecting
        if (this.eventSource && this.currentToken === token && !this.isExplicitlyClosed) {
            return;
        }

        this.disconnect();
        this.currentToken = token;
        this.isExplicitlyClosed = false;

        // Check EventSource support in browser
        if (typeof window === 'undefined' || typeof window.EventSource === 'undefined') {
            return;
        }

        const url = `/api/groups/${encodeURIComponent(token)}/events`;

        try {
            this.eventSource = new EventSource(url);

            this.eventSource.onopen = () => {
                this.retryCount = 0;
            };

            this.eventSource.onmessage = (e) => {
                try {
                    const data = JSON.parse(e.data);
                    this.handleEvent(data, onEventCallback);
                } catch (err) {
                    // Non-JSON comments (like keepalive) safely ignored
                }
            };

            this.eventSource.onerror = () => {
                if (this.isExplicitlyClosed) return;

                // If EventSource enters CLOSED state, schedule a graceful backoff reconnect
                if (this.eventSource && this.eventSource.readyState === EventSource.CLOSED) {
                    this.scheduleReconnect(token, onEventCallback);
                }
            };
        } catch (err) {
            console.warn('[SSE] Connection failed to initialize:', err);
        }
    }

    /**
     * Handle incoming parsed event with debouncing to prevent UI churn during rapid mutations.
     * @private
     */
    handleEvent(data, onEventCallback) {
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        this.debounceTimer = setTimeout(() => {
            this.listeners.forEach((fn) => {
                try {
                    fn(data);
                } catch (err) {
                    console.error('[SSE] Listener error:', err);
                }
            });

            if (typeof onEventCallback === 'function') {
                try {
                    onEventCallback(data);
                } catch (err) {
                    console.error('[SSE] Callback error:', err);
                }
            }
        }, 200);
    }

    /**
     * Schedule reconnect with exponential backoff.
     * @private
     */
    scheduleReconnect(token, onEventCallback) {
        if (this.isExplicitlyClosed || this.retryCount >= this.maxRetries) return;

        const delay = Math.min(this.baseDelay * Math.pow(1.5, this.retryCount), 15000);
        this.retryCount++;

        this.reconnectTimer = setTimeout(() => {
            if (!this.isExplicitlyClosed && this.currentToken === token) {
                this.connect(token, onEventCallback);
            }
        }, delay);
    }

    /**
     * Disconnect active SSE connection and clean up all timers.
     */
    disconnect() {
        this.isExplicitlyClosed = true;

        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = null;
        }

        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }

        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }

        this.currentToken = null;
    }
}

export const sseManager = new SSEManager();
