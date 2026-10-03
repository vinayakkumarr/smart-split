/**
 * Smart Split V2 – Activity Feed & Immutable Audit Timeline Component
 * Principles: Chronological Audit Trail, Relative Timestamps, Clean Narrative Badges
 */

import { api } from '../api.js';
import * as Formatters from '../utils/formatters.js';
import { Modal } from './Modal.js';
import { Toast } from './Toast.js';

export class ActivityTimeline {
    /**
     * Open the Activity Timeline modal for a group workspace.
     * @param {string} token Group invite token
     * @param {string} [currency] Default: 'INR'
     */
    static async open(token, currency = 'INR') {
        if (!token) return;

        let activeFilter = 'all';
        let currentActivities = [];
        let totalCount = 0;

        const fetchAndRenderActivities = async (container, entityType = null) => {
            const listEl = container.querySelector('#timeline-feed-list');
            const countEl = container.querySelector('#timeline-total-count');
            if (!listEl) return;

            listEl.innerHTML = `
                <div style="text-align: center; color: var(--text-muted); padding: var(--space-6) 0; font-size: var(--font-size-sm);">
                    Loading activity timeline...
                </div>
            `;

            try {
                const params = { limit: 50 };
                if (entityType && entityType !== 'all') {
                    params.entity_type = entityType;
                }

                const res = await api.getActivityFeed(token, params);
                const data = res.data || {};
                currentActivities = data.activities || [];
                totalCount = data.total_count || currentActivities.length;

                if (countEl) {
                    countEl.textContent = `${totalCount} event${totalCount === 1 ? '' : 's'}`;
                }

                if (currentActivities.length === 0) {
                    listEl.innerHTML = `
                        <div class="empty-state" style="padding: var(--space-6) var(--space-4);">
                            <div class="empty-state-text">No activity recorded for this filter yet.</div>
                        </div>
                    `;
                    return;
                }

                listEl.innerHTML = currentActivities.map((act) => {
                    const narrative = Formatters.escapeHtml(act.narrative || '');
                    const timeAgo = Formatters.escapeHtml(act.time_ago || Formatters.formatRelativeTime(act.created_at));
                    const badgeClass = act.badge || 'badge-secondary';
                    const title = Formatters.escapeHtml(act.title || 'Activity');

                    return `
                        <div class="timeline-item">
                            <div class="timeline-badge">•</div>
                            <div class="timeline-card">
                                <div class="timeline-header">
                                    <span class="timeline-title">
                                        <span class="badge ${badgeClass}" style="font-size: var(--font-size-2xs); padding: 1px 6px;">${title}</span>
                                    </span>
                                    <span class="timeline-time">${timeAgo}</span>
                                </div>
                                <div class="timeline-narrative" style="margin-top: 4px;">
                                    ${narrative}
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            } catch (err) {
                listEl.innerHTML = `
                    <div style="color: var(--financial-debt); padding: var(--space-4); text-align: center; font-size: var(--font-size-sm);">
                        ${Formatters.escapeHtml(err.message || 'Failed to load activity logs.')}
                    </div>
                `;
            }
        };

        const modalHtml = `
            <div>
                <!-- Filter Pills & Total Count -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3);">
                    <div class="timeline-filter-bar" id="timeline-filter-bar" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">
                        <button type="button" class="timeline-chip is-active" data-filter="all">All</button>
                        <button type="button" class="timeline-chip" data-filter="expenses">Expenses</button>
                        <button type="button" class="timeline-chip" data-filter="settlements">Settlements</button>
                        <button type="button" class="timeline-chip" data-filter="members">Members</button>
                        <button type="button" class="timeline-chip" data-filter="categories">Categories</button>
                    </div>
                    <span id="timeline-total-count" class="badge badge-settled badge-mono" style="font-size: var(--font-size-2xs);">...</span>
                </div>

                <!-- Timeline Feed List -->
                <div class="timeline-feed" id="timeline-feed-list"></div>
            </div>
        `;

        Modal.open({
            title: 'Activity Timeline & Audit History',
            content: modalHtml,
            size: 'lg',
            confirmText: 'Done',
            confirmClass: 'btn-primary',
            showCancel: false,
            onMount: (overlay) => {
                fetchAndRenderActivities(overlay, 'all');

                const filterButtons = overlay.querySelectorAll('.timeline-chip');
                filterButtons.forEach((btn) => {
                    btn.addEventListener('click', () => {
                        filterButtons.forEach((b) => b.classList.remove('is-active'));
                        btn.classList.add('is-active');
                        activeFilter = btn.dataset.filter;
                        fetchAndRenderActivities(overlay, activeFilter);
                    });
                });
            },
        });
    }
}
