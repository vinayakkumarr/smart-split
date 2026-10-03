/**
 * Smart Split V2 – Unsynced Offline Actions & Failed Mutations Recovery Modal
 * 
 * Provides an accessible, user-facing review and recovery panel for dead-lettered
 * offline mutations that were rejected by the server (e.g., deleted member, 422 validation).
 * Allows users to inspect error context, retry the operation safely, or dismiss the record.
 */

import { Modal } from './Modal.js';
import { Toast } from './Toast.js';
import { offlineManager } from '../utils/offline.js';
import { api } from '../api.js';
import { escapeHtml, formatDate, formatCurrency } from '../utils/formatters.js';
import { renderIcon } from '../utils/icons.js';

export class FailedMutationsModal {
    /**
     * Generate human-readable operation title from failed mutation payload.
     * @param {Object} item
     * @returns {string}
     */
    static getOperationSummary(item) {
        if (!item || !item.payload) {
            return item?.action ? item.action.replace('_', ' ') : 'Offline Action';
        }

        const p = item.payload;
        switch (item.action) {
            case 'CREATE_EXPENSE': {
                const title = p.title || 'Expense';
                const cents = p.total_amount_cents || p.amount_cents || 0;
                return `${escapeHtml(title)} • ${cents > 0 ? formatCurrency(cents) : ''}`;
            }
            case 'UPDATE_EXPENSE': {
                const title = p.title || `Expense #${item.entityId || ''}`;
                const cents = p.total_amount_cents || p.amount_cents || 0;
                return `Edit: ${escapeHtml(title)} • ${cents > 0 ? formatCurrency(cents) : ''}`;
            }
            case 'DELETE_EXPENSE':
                return `Delete Expense #${item.entityId || ''}`;
            case 'CREATE_SETTLEMENT': {
                const cents = p.amount_cents || 0;
                return `Settlement Payment • ${cents > 0 ? formatCurrency(cents) : ''}`;
            }
            default:
                return escapeHtml(item.action || 'Unknown Operation');
        }
    }

    /**
     * Get badge label and class for action type.
     * @param {string} action
     * @returns {{ label: string, badgeClass: string }}
     */
    static getActionBadge(action) {
        switch (action) {
            case 'CREATE_EXPENSE':
                return { label: 'New Expense', badgeClass: 'badge-primary' };
            case 'UPDATE_EXPENSE':
                return { label: 'Edit Expense', badgeClass: 'badge-secondary' };
            case 'DELETE_EXPENSE':
                return { label: 'Delete Expense', badgeClass: 'badge-debt' };
            case 'CREATE_SETTLEMENT':
                return { label: 'Settlement', badgeClass: 'badge-credit' };
            default:
                return { label: action || 'Action', badgeClass: 'badge-secondary' };
        }
    }

    /**
     * Render HTML for list of failed mutations.
     * @param {Array<Object>} items
     * @returns {string}
     */
    static renderListHtml(items = []) {
        if (!items || items.length === 0) {
            return `
                <div class="empty-state" style="padding: var(--space-6) var(--space-4); text-align: center;">
                    <div style="color: var(--financial-credit, #059669); margin-bottom: var(--space-2); display: flex; justify-content: center;">
                        ${renderIcon('checkCircle', { size: 36 })}
                    </div>
                    <h4 style="margin: 0 0 6px 0; font-size: var(--font-size-base); color: var(--text-primary);">All Offline Actions In Sync</h4>
                    <p style="margin: 0; font-size: var(--font-size-xs); color: var(--text-muted);">
                        No rejected offline actions found for this workspace.
                    </p>
                </div>
            `;
        }

        return `
            <div style="display: flex; flex-direction: column; gap: var(--space-3);" id="failed-mutations-list-container">
                <div style="background: var(--brand-primary-soft, #e8f0ec); border: 1px solid var(--border-color); padding: 10px 14px; border-radius: var(--radius-xs); font-size: var(--font-size-xs); color: var(--text-primary); line-height: 1.4;">
                    <strong>Notice:</strong> The operations below failed server validation upon reconnect (e.g. member removed or input conflict). They are <strong>not applied</strong> to the group balance. You may retry or dismiss them.
                </div>

                ${items.map((item) => {
                    const badge = FailedMutationsModal.getActionBadge(item.action);
                    const summary = FailedMutationsModal.getOperationSummary(item);
                    const failedTimeStr = item.failedAt ? formatDate(item.failedAt) : 'Recent';
                    const errorMsg = escapeHtml(item.errorMessage || 'Server rejected request.');
                    const statusCode = item.status ? ` (HTTP ${item.status})` : '';

                    return `
                        <div class="failed-mutation-card" data-mutation-id="${escapeHtml(item.id)}" style="background: var(--surface-primary, #ffffff); border: 1px solid var(--border-color); border-left: 4px solid var(--financial-debt, #dc2626); border-radius: var(--radius-sm); padding: var(--space-3) var(--space-4); display: flex; flex-direction: column; gap: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; flex-wrap: wrap;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span class="badge ${badge.badgeClass}" style="font-size: 0.65rem; text-transform: uppercase;">${escapeHtml(badge.label)}</span>
                                    <strong style="font-size: var(--font-size-sm); color: var(--text-primary);">${summary}</strong>
                                </div>
                                <span style="font-size: var(--font-size-2xs); color: var(--text-muted); font-family: var(--font-mono);">
                                    ${failedTimeStr}
                                </span>
                            </div>

                            <div style="background: rgba(220, 38, 38, 0.08); border: 1px solid rgba(220, 38, 38, 0.2); border-radius: var(--radius-xs); padding: 6px 10px; font-size: var(--font-size-xs); color: var(--financial-debt, #dc2626); display: flex; align-items: center; gap: 6px;">
                                <span style="display: inline-flex; flex-shrink: 0;">${renderIcon('alertTriangle', { size: 14 })}</span>
                                <span><strong>Reason:</strong> ${errorMsg}${escapeHtml(statusCode)}</span>
                            </div>

                            <div style="display: flex; justify-content: flex-end; align-items: center; gap: var(--space-2); margin-top: 4px;">
                                <button type="button" class="btn btn-secondary btn-sm btn-dismiss-failed-mutation" data-id="${escapeHtml(item.id)}" style="padding: 4px 10px; font-size: var(--font-size-xs);">
                                    Dismiss
                                </button>
                                <button type="button" class="btn btn-primary btn-sm btn-retry-failed-mutation" data-id="${escapeHtml(item.id)}" style="padding: 4px 12px; font-size: var(--font-size-xs); font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                    ${renderIcon('refreshCw', { size: 12 })}
                                    <span>Retry Sync</span>
                                </button>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }

    /**
     * Open the Failed Mutations Review modal.
     * @param {Object} options
     * @param {string|null} [options.token]
     * @param {Function} [options.onUpdated]
     */
    static open({ token = null, onUpdated = null } = {}) {
        const renderModalBody = () => {
            const items = offlineManager.getFailedMutations(token);
            return FailedMutationsModal.renderListHtml(items);
        };

        Modal.open({
            title: 'Unsynced Offline Actions & Validation Failures',
            size: 'lg',
            content: `
                <div id="failed-mutations-modal-body">
                    ${renderModalBody()}
                </div>
            `,
            showFooter: true,
            confirmText: 'Dismiss All for Workspace',
            confirmClass: 'btn-secondary',
            showCancel: true,
            onConfirm: async () => {
                const items = offlineManager.getFailedMutations(token);
                if (items.length > 0) {
                    offlineManager.clearFailedMutations(token);
                    Toast.info('All unsynced offline failure records dismissed.');
                    if (typeof onUpdated === 'function') {
                        await onUpdated();
                    }
                }
            },
            onMount: (overlay) => {
                const bodyContainer = overlay.querySelector('#failed-mutations-modal-body');

                const rebindActions = () => {
                    if (!bodyContainer) return;

                    // Retry Handlers
                    const retryBtns = bodyContainer.querySelectorAll('.btn-retry-failed-mutation');
                    retryBtns.forEach((btn) => {
                        btn.addEventListener('click', async (e) => {
                            e.preventDefault();
                            const mutationId = btn.dataset.id;
                            if (!mutationId) return;

                            const origHtml = btn.innerHTML;
                            btn.disabled = true;
                            btn.innerHTML = `<span>Retrying...</span>`;

                            try {
                                await offlineManager.retryFailedMutation(mutationId, api);
                                Toast.success('Action successfully synced with server!');
                                if (typeof onUpdated === 'function') {
                                    await onUpdated();
                                }
                                bodyContainer.innerHTML = renderModalBody();
                                rebindActions();
                            } catch (err) {
                                btn.disabled = false;
                                btn.innerHTML = origHtml;
                                Toast.error(err.message || 'Retry failed: server validation error still persists.');
                                bodyContainer.innerHTML = renderModalBody();
                                rebindActions();
                            }
                        });
                    });

                    // Dismiss Handlers
                    const dismissBtns = bodyContainer.querySelectorAll('.btn-dismiss-failed-mutation');
                    dismissBtns.forEach((btn) => {
                        btn.addEventListener('click', async (e) => {
                            e.preventDefault();
                            const mutationId = btn.dataset.id;
                            if (!mutationId) return;

                            offlineManager.removeFailedMutation(mutationId);
                            Toast.info('Failed action dismissed.');
                            if (typeof onUpdated === 'function') {
                                await onUpdated();
                            }
                            bodyContainer.innerHTML = renderModalBody();
                            rebindActions();
                        });
                    });
                };

                rebindActions();
            },
        });
    }
}
