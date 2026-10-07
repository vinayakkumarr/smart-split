/**
 * Smart Split – Centralized Reactive State Store (Pub/Sub Pattern)
 */

export class Store {
    constructor(initialState = {}) {
        this.state = {
            currentGroup: null,
            members: [],
            expenses: [],
            balances: [],
            settlementPlan: {
                total_transactions: 0,
                total_settlement_volume_cents: 0,
                transactions: [],
            },
            settlements: [],
            activeView: 'landing',
            isLoading: false,
            error: null,
            draftExpense: null,
            currentUser: null,
            isAuthenticated: false,
            authLoading: true,
            isSyncing: false,
            ...initialState,
        };
        this.listeners = new Set();
    }

    /**
     * Get snapshot of current application state.
     * @returns {Object}
     */
    getState() {
        return this.state;
    }

    /**
     * Mutate state with patch and notify all registered subscribers.
     * @param {Object|Function} patch Partial state object or updater function.
     */
    setState(patch) {
        const nextState = typeof patch === 'function' ? patch(this.state) : { ...this.state, ...patch };
        this.state = nextState;
        this.notify();
    }

    /**
     * Subscribe a callback to be notified on state changes.
     * @param {Function} listener
     * @returns {Function} Unsubscribe function.
     */
    subscribe(listener) {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    }

    /**
     * Notify all subscribers with the updated state.
     */
    notify() {
        for (const listener of this.listeners) {
            try {
                listener(this.state);
            } catch (err) {
                console.error('Error in store listener:', err);
            }
        }
    }
}

// Global Singleton Instance
export const store = new Store();
