import { Toast } from './components/Toast.js';

class ApiClient {
    constructor(baseUrl = '/api') {
        this.baseUrl = baseUrl;
        this.recentMutations = new Map();
        this.inFlightRequests = new Map();
    }

    /**
     * Track a locally initiated mutation to prevent redundant SSE self-echoes.
     * @param {string} type e.g. 'expense.created', 'expense.updated'
     * @param {number|string|null} [entityId]
     * @param {number|null} [version]
     */
    recordMutation(type, entityId = null, version = null) {
        const now = Date.now();
        if (entityId !== null && entityId !== undefined) {
            this.recentMutations.set(`${type}:${entityId}`, now);
            if (version !== null && version !== undefined) {
                this.recentMutations.set(`${type}:${entityId}:${version}`, now);
            }
        }
        this.recentMutations.set(`${type}:any`, now);

        // Garbage collect entries older than 10 seconds
        for (const [key, ts] of this.recentMutations.entries()) {
            if (now - ts > 10000) {
                this.recentMutations.delete(key);
            }
        }
    }

    /**
     * Check if an incoming SSE event was triggered by this client tab's own local mutation.
     * @param {Object} eventData { type, entity_id, version }
     * @returns {boolean}
     */
    isSelfMutation(eventData) {
        if (!eventData || !eventData.type) return false;
        const now = Date.now();
        const type = eventData.type;
        const entityId = eventData.entity_id;
        const version = eventData.version;

        if (entityId !== null && entityId !== undefined) {
            if (version !== null && version !== undefined) {
                const tsVer = this.recentMutations.get(`${type}:${entityId}:${version}`);
                if (tsVer && (now - tsVer < 5000)) return true;
            }
            const tsId = this.recentMutations.get(`${type}:${entityId}`);
            if (tsId && (now - tsId < 5000)) return true;
        }

        const tsType = this.recentMutations.get(`${type}:any`);
        if (tsType && (now - tsType < 3000)) return true;

        return false;
    }

    /**
     * Generic fetch wrapper handling headers, JSON encoding, and error normalization.
     * Automatically includes same-origin credentials for HTTP-Only session cookies.
     * @private
     */
    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}${endpoint}`;
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'Fetch',
            ...options.headers,
        };

        const config = {
            credentials: 'same-origin',
            ...options,
            headers,
        };

        if (options.body && typeof options.body === 'object') {
            config.body = JSON.stringify(options.body);
        }

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok || data.success === false) {
                const errorMessage = data?.error?.message || `HTTP ${response.status}: Request failed`;
                const error = new Error(errorMessage);
                error.code = data?.error?.code || 'REQUEST_FAILED';
                error.details = data?.error?.details || null;
                error.status = response.status;
                throw error;
            }

            return data;
        } catch (err) {
            if (typeof window !== 'undefined' && !window.navigator.onLine) {
                Toast.show('Network connection lost. You appear to be offline.', 'error');
                const networkError = new Error('Network connection lost. You appear to be offline.');
                networkError.code = 'NETWORK_OFFLINE';
                throw networkError;
            }
            throw err;
        }
    }

    /**
     * Deduplicate identical concurrent GET requests using in-flight Promises.
     * @param {string} key Unique request key
     * @param {Function} requestFn Factory function returning a Promise
     * @returns {Promise<any>}
     */
    async dedupedGet(key, requestFn) {
        if (this.inFlightRequests.has(key)) {
            return this.inFlightRequests.get(key);
        }
        const promise = requestFn().finally(() => {
            this.inFlightRequests.delete(key);
        });
        this.inFlightRequests.set(key, promise);
        return promise;
    }

    // --- Authentication & Session API ---

    /**
     * Register a new user account.
     * @param {Object} payload { email, password, display_name, avatar_emoji?, avatar_color? }
     */
    async register(payload) {
        return this.request('/auth/register', {
            method: 'POST',
            body: payload,
        });
    }

    /**
     * Log in user with password credentials.
     * @param {Object} payload { email, password }
     */
    async login(payload) {
        return this.request('/auth/login', {
            method: 'POST',
            body: payload,
        });
    }

    /**
     * Log out current session and invalidate server session token.
     */
    async logout() {
        return this.request('/auth/logout', {
            method: 'POST',
        });
    }

    /**
     * Retrieve currently authenticated user context or guest state.
     */
    async getMe() {
        return this.request('/auth/me');
    }

    /**
     * Reset password using emergency 10-char recovery code.
     * @param {Object} payload { email, recovery_code, new_password }
     */
    async recoverPassword(payload) {
        return this.request('/auth/recover', {
            method: 'POST',
            body: payload,
        });
    }

    // --- Progressive Identity & Claiming API ---

    /**
     * Link/claim a guest-created workspace to the authenticated user account.
     * @param {string} token Workspace invite token
     * @param {string|null} [creatorToken] Secret creator member token from localStorage
     */
    async claimWorkspace(token, creatorToken = null) {
        const secToken = creatorToken || (typeof localStorage !== 'undefined' ? localStorage.getItem(`smartsplit_creator_${token}`) : null);
        const headers = {};
        if (secToken) {
            headers['X-Creator-Token'] = secToken;
        }
        return this.request(`/groups/${encodeURIComponent(token)}/claim-workspace`, {
            method: 'POST',
            headers,
        });
    }

    /**
     * Link an existing member slot in a workspace to the authenticated user.
     * @param {string} token
     * @param {number} memberId
     */
    async claimMember(token, memberId) {
        return this.request(`/groups/${encodeURIComponent(token)}/members/${memberId}/claim`, {
            method: 'POST',
            body: { member_id: memberId },
        });
    }

    /**
     * Unlink an authenticated user from a member slot.
     * @param {string} token
     * @param {number} memberId
     */
    async unlinkMember(token, memberId) {
        return this.request(`/groups/${encodeURIComponent(token)}/members/${memberId}/unlink`, {
            method: 'POST',
            body: { member_id: memberId },
        });
    }

    // --- Cloud Workspaces & Account API ---

    /**
     * Get all workspaces owned by or linked to the authenticated user.
     */
    async getUserWorkspaces() {
        return this.dedupedGet('/user/workspaces', () => this.request('/user/workspaces'));
    }

    /**
     * Update authenticated user's profile identity.
     * @param {Object} payload { display_name?: string, avatar_emoji?: string, avatar_color?: string, upi_id?: string|null }
     */
    async updateProfile(payload) {
        return this.request('/user/profile', {
            method: 'PUT',
            body: payload,
        });
    }

    /**
     * Permanently delete user account while keeping workspace financial data intact.
     * @param {Object} payload { password: string, confirm_password?: string }
     */
    async deleteAccount(payload) {
        return this.request('/user/account', {
            method: 'DELETE',
            body: typeof payload === 'string' ? { password: payload } : payload,
        });
    }

    // --- Categories API ---

    /**
     * Get list of standard and group custom expense categories.
     * @param {string} [token]
     */
    async getCategories(token = null) {
        if (token) {
            return this.request(`/groups/${encodeURIComponent(token)}/categories`);
        }
        return this.request('/categories');
    }

    /**
     * Create a workspace custom category.
     * @param {string} token
     * @param {Object} payload { name: string, icon?: string, color_hex?: string }
     */
    async createCategory(token, payload) {
        return this.request(`/groups/${encodeURIComponent(token)}/categories`, {
            method: 'POST',
            body: payload,
        });
    }

    /**
     * Delete a workspace custom category.
     * @param {string} token
     * @param {number} categoryId
     */
    async deleteCategory(token, categoryId) {
        return this.request(`/groups/${encodeURIComponent(token)}/categories/${categoryId}`, {
            method: 'DELETE',
        });
    }

    // --- Groups API ---

    /**
     * Create a new group with organizer.
     */
    async createGroup(name, creatorName = 'Organizer', currency = 'INR') {
        return this.request('/groups', {
            method: 'POST',
            body: { name, creator_name: creatorName, currency },
        });
    }

    /**
     * Get group details and member roster by token.
     */
    async getGroup(token) {
        return this.request(`/groups/${encodeURIComponent(token)}`);
    }

    /**
     * Get consolidated workspace dataset (group, members, balances, settlement plan, expenses, settlements) in 1 request.
     * @param {string} token
     */
    async getWorkspace(token) {
        return this.request(`/groups/${encodeURIComponent(token)}/workspace`);
    }

    /**
     * Delete a workspace / group permanently.
     * @param {string} token
     */
    async deleteGroup(token) {
        const creatorToken = typeof localStorage !== 'undefined' ? localStorage.getItem(`smartsplit_creator_${token}`) : null;
        const headers = {};
        if (creatorToken) {
            headers['X-Creator-Token'] = creatorToken;
        }
        return this.request(`/groups/${encodeURIComponent(token)}`, {
            method: 'DELETE',
            headers,
        });
    }

    /**
     * Generate a short-lived single-use pairing code for transferring creator authority.
     * @param {string} token
     */
    async createCreatorPairingCode(token) {
        const creatorToken = typeof localStorage !== 'undefined' ? localStorage.getItem(`smartsplit_creator_${token}`) : null;
        const headers = {};
        if (creatorToken) {
            headers['X-Creator-Token'] = creatorToken;
        }
        return this.request(`/groups/${encodeURIComponent(token)}/creator-pairing`, {
            method: 'POST',
            headers,
        });
    }

    /**
     * Claim a pairing code on a new device to establish creator authority.
     * @param {string} token
     * @param {string} pairingCode
     */
    async claimCreatorPairingCode(token, pairingCode) {
        return this.request(`/groups/${encodeURIComponent(token)}/creator-pairing/claim`, {
            method: 'POST',
            body: { pairing_code: pairingCode },
        });
    }

    // --- Members API ---

    /**
     * Add a member to the group.
     */
    async addMember(token, name) {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/members`, {
            method: 'POST',
            body: { name },
        });
        if (res?.data?.member?.id) {
            this.recordMutation('member.created', res.data.member.id);
        }
        return res;
    }

    /**
     * Update a member in the group (name and/or upi_id).
     * @param {string} token
     * @param {number} memberId
     * @param {string|Object} data String name or object { name, upi_id }
     */
    async updateMember(token, memberId, data) {
        const body = typeof data === 'string' ? { name: data } : (data || {});
        const res = await this.request(`/groups/${encodeURIComponent(token)}/members/${memberId}`, {
            method: 'PUT',
            body,
        });
        this.recordMutation('member.updated', memberId);
        return res;
    }

    /**
     * Delete/remove a member from the group.
     * @param {string} token
     * @param {number} memberId
     */
    async deleteMember(token, memberId) {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/members/${memberId}`, {
            method: 'DELETE',
        });
        this.recordMutation('member.deleted', memberId);
        return res;
    }

    /**
     * List group members.
     */
    async getMembers(token) {
        return this.request(`/groups/${encodeURIComponent(token)}/members`);
    }

    // --- Expenses API ---

    /**
     * Create a new expense.
     * @param {string} token
     * @param {Object} payload
     * @param {string|null} [idempotencyKey]
     */
    async createExpense(token, payload, idempotencyKey = null) {
        const headers = {};
        if (idempotencyKey) {
            headers['X-Idempotency-Key'] = idempotencyKey;
        }
        const res = await this.request(`/groups/${encodeURIComponent(token)}/expenses`, {
            method: 'POST',
            body: payload,
            headers,
        });
        if (res?.data?.expense?.id) {
            this.recordMutation('expense.created', res.data.expense.id, res.data.expense.version);
        }
        return res;
    }

    /**
     * Update an existing expense.
     */
    async updateExpense(token, expenseId, payload) {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/expenses/${expenseId}`, {
            method: 'PUT',
            body: payload,
        });
        this.recordMutation('expense.updated', expenseId, res?.data?.expense?.version);
        return res;
    }

    /**
     * List expenses for a group with optional query filters.
     * @param {string} token
     * @param {Object} [filters]
     */
    async getExpenses(token, filters = {}) {
        const query = new URLSearchParams();
        for (const [key, val] of Object.entries(filters)) {
            if (val !== null && val !== undefined && val !== '') {
                query.append(key, val);
            }
        }
        const qs = query.toString();
        const endpoint = `/groups/${encodeURIComponent(token)}/expenses${qs ? `?${qs}` : ''}`;
        return this.request(endpoint);
    }

    /**
     * Get single expense details.
     */
    async getExpense(token, expenseId) {
        return this.request(`/groups/${encodeURIComponent(token)}/expenses/${expenseId}`);
    }

    /**
     * Soft-delete an expense.
     */
    async deleteExpense(token, expenseId) {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/expenses/${expenseId}`, {
            method: 'DELETE',
        });
        this.recordMutation('expense.deleted', expenseId);
        return res;
    }

    /**
     * Get list of soft-deleted expenses from the trash bin.
     * @param {string} token
     */
    async getTrashExpenses(token) {
        return this.request(`/groups/${encodeURIComponent(token)}/expenses/trash`);
    }

    /**
     * Restore a soft-deleted expense.
     * @param {string} token
     * @param {number} expenseId
     */
    async restoreExpense(token, expenseId) {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/expenses/${expenseId}/restore`, {
            method: 'PUT',
        });
        this.recordMutation('expense.restored', expenseId);
        return res;
    }

    // --- Balances, Analytics & Settlements API ---

    /**
     * Get net balances and zero-sum verification.
     */
    async getBalances(token) {
        return this.dedupedGet(`/groups/${encodeURIComponent(token)}/balances`, () =>
            this.request(`/groups/${encodeURIComponent(token)}/balances`)
        );
    }

    /**
     * Get 1-on-1 direct pairwise bilateral balances.
     */
    async getBilateralBalances(token) {
        return this.dedupedGet(`/groups/${encodeURIComponent(token)}/bilateral-balances`, () =>
            this.request(`/groups/${encodeURIComponent(token)}/bilateral-balances`)
        );
    }

    /**
     * Get visual spend analytics and category trends.
     */
    async getAnalyticsSummary(token) {
        return this.dedupedGet(`/groups/${encodeURIComponent(token)}/analytics/summary`, () =>
            this.request(`/groups/${encodeURIComponent(token)}/analytics/summary`)
        );
    }

    /**
     * Get human-readable activity and audit timeline feed.
     * @param {string} token
     * @param {Object} [params] { limit?: number, offset?: number, entity_type?: string }
     */
    async getActivityFeed(token, params = {}) {
        const query = new URLSearchParams();
        if (params.limit) query.set('limit', params.limit);
        if (params.offset) query.set('offset', params.offset);
        if (params.entity_type) query.set('entity_type', params.entity_type);
        const qs = query.toString() ? `?${query.toString()}` : '';
        const path = `/groups/${encodeURIComponent(token)}/activity-feed${qs}`;
        return this.dedupedGet(path, () => this.request(path));
    }

    /**
     * Get optimized debt simplification settlement plan.
     */
    async getSettlementPlan(token) {
        return this.dedupedGet(`/groups/${encodeURIComponent(token)}/settlement-plan`, () =>
            this.request(`/groups/${encodeURIComponent(token)}/settlement-plan`)
        );
    }

    /**
     * Get itemized ledger history for a single member.
     */
    async getMemberLedger(token, memberId) {
        return this.dedupedGet(`/groups/${encodeURIComponent(token)}/members/${encodeURIComponent(memberId)}/ledger`, () =>
            this.request(`/groups/${encodeURIComponent(token)}/members/${encodeURIComponent(memberId)}/ledger`)
        );
    }

    /**
     * Record a direct debt payment settlement.
     */
    async createSettlement(token, payload) {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/settlements`, {
            method: 'POST',
            body: payload,
        });
        if (res?.data?.settlement?.id) {
            this.recordMutation('settlement.created', res.data.settlement.id);
        }
        return res;
    }

    /**
     * Alias for createSettlement.
     */
    async recordSettlement(token, payload) {
        return this.createSettlement(token, payload);
    }

    /**
     * List all recorded settlements.
     */
    async getSettlements(token) {
        return this.request(`/groups/${encodeURIComponent(token)}/settlements`);
    }

    /**
     * Confirm receipt of a pending settlement.
     * @param {string} token
     * @param {number} settlementId
     */
    async confirmSettlement(token, settlementId) {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/settlements/${settlementId}/confirm`, {
            method: 'POST',
        });
        this.recordMutation('settlement.confirmed', settlementId);
        return res;
    }

    /**
     * Dispute a pending settlement payment.
     * @param {string} token
     * @param {number} settlementId
     * @param {string} [reason]
     */
    async disputeSettlement(token, settlementId, reason = '') {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/settlements/${settlementId}/dispute`, {
            method: 'POST',
            body: { reason },
        });
        this.recordMutation('settlement.disputed', settlementId);
        return res;
    }

    /**
     * Reverse a confirmed settlement payment.
     * @param {string} token
     * @param {number} settlementId
     * @param {string} [reason]
     */
    async reverseSettlement(token, settlementId, reason = '') {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/settlements/${settlementId}/reverse`, {
            method: 'POST',
            body: { reason },
        });
        this.recordMutation('settlement.reversed', settlementId);
        return res;
    }

    /**
     * Soft-delete a settlement.
     */
    async deleteSettlement(token, settlementId) {
        const res = await this.request(`/groups/${encodeURIComponent(token)}/settlements/${settlementId}`, {
            method: 'DELETE',
        });
        this.recordMutation('settlement.deleted', settlementId);
        return res;
    }

    // --- Recurring Schedules API ---

    /**
     * List all recurring rules for a group.
     */
    async getRecurringRules(token) {
        return this.request(`/groups/${encodeURIComponent(token)}/recurring`);
    }

    /**
     * Create a scheduled recurring expense rule.
     */
    async createRecurringRule(token, payload) {
        return this.request(`/groups/${encodeURIComponent(token)}/recurring`, {
            method: 'POST',
            body: payload,
        });
    }

    /**
     * Delete or cancel a recurring rule schedule.
     */
    async deleteRecurringRule(token, ruleId) {
        return this.request(`/groups/${encodeURIComponent(token)}/recurring/${ruleId}`, {
            method: 'DELETE',
        });
    }

    /**
     * Trigger evaluation of due recurring rules.
     */
    async evaluateRecurring(token, currentDate = null) {
        return this.request(`/groups/${encodeURIComponent(token)}/recurring/evaluate`, {
            method: 'POST',
            body: currentDate ? { current_date: currentDate } : {},
        });
    }

    // --- Expense Templates API ---

    /**
     * List all saved expense templates for a group.
     */
    async getTemplates(token) {
        return this.request(`/groups/${encodeURIComponent(token)}/templates`);
    }

    /**
     * Save an expense configuration as a reusable template.
     */
    async createTemplate(token, payload) {
        return this.request(`/groups/${encodeURIComponent(token)}/templates`, {
            method: 'POST',
            body: payload,
        });
    }

    /**
     * Delete a saved expense template preset.
     */
    async deleteTemplate(token, templateId) {
        return this.request(`/groups/${encodeURIComponent(token)}/templates/${templateId}`, {
            method: 'DELETE',
        });
    }

    // --- Receipt Attachments API ---

    /**
     * Upload a receipt attachment via FormData.
     */
    async uploadReceipt(token, expenseId, formData) {
        return this.request(`/groups/${encodeURIComponent(token)}/expenses/${expenseId}/receipts`, {
            method: 'POST',
            body: formData,
        });
    }

    /**
     * Upload a receipt attachment via Base64 data.
     */
    async uploadReceiptBase64(token, expenseId, fileName, base64Data, actorMemberId = null) {
        return this.request(`/groups/${encodeURIComponent(token)}/expenses/${expenseId}/receipts`, {
            method: 'POST',
            body: {
                file_name: fileName,
                data_base64: base64Data,
                actor_member_id: actorMemberId,
            },
        });
    }

    /**
     * List all receipts for an expense.
     */
    async getReceipts(token, expenseId) {
        return this.request(`/groups/${encodeURIComponent(token)}/expenses/${expenseId}/receipts`);
    }

    /**
     * Delete a receipt attachment.
     */
    async deleteReceipt(token, expenseId, receiptId, actorMemberId = null) {
        const query = actorMemberId ? `?actor_member_id=${actorMemberId}` : '';
        return this.request(`/groups/${encodeURIComponent(token)}/expenses/${expenseId}/receipts/${receiptId}${query}`, {
            method: 'DELETE',
        });
    }

    // --- Currencies & Rates API ---

    /**
     * Fetch supported global currencies.
     */
    async getCurrencies() {
        return this.request('/currencies');
    }

    /**
     * Fetch live benchmark exchange rate.
     */
    async getExchangeRates(from = 'USD', to = null) {
        const query = to ? `?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}` : `?from=${encodeURIComponent(from)}`;
        return this.request(`/exchange-rates${query}`);
    }

    /**
     * Helper to get CSV export URL.
     */
    getExportCsvUrl(token) {
        return `${this.baseUrl}/groups/${encodeURIComponent(token)}/export.csv`;
    }
}

export const api = new ApiClient();
