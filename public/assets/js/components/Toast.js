/**
 * Smart Split – Global Toast Notification System
 */

import { escapeHtml } from '../utils/formatters.js';
import { renderIcon } from '../utils/icons.js';

export class Toast {
    static getContainer() {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            document.body.appendChild(container);
        }
        return container;
    }

    /**
     * Display a floating toast notification.
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} type
     * @param {number} duration Timeout in milliseconds (default: 3500ms)
     */
    static show(message, type = 'info', duration = 3500) {
        if (typeof document === 'undefined') return;
        const container = this.getContainer();
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        const iconMap = {
            success: 'checkCircle',
            error: 'xCircle',
            warning: 'alertTriangle',
            info: 'info',
        };

        const iconName = iconMap[type] || 'info';
        const iconHtml = renderIcon(iconName, { size: 16 });

        toast.innerHTML = `
            <div style="display: flex; align-items: center; gap: var(--space-2); min-width: 0; flex: 1;">
                <span style="display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">${iconHtml}</span>
                <span style="overflow: hidden; text-overflow: ellipsis; line-height: 1.4;">${escapeHtml(message)}</span>
            </div>
            <button type="button" aria-label="Dismiss notification" style="background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 2px; line-height: 1; display: inline-flex; align-items: center; opacity: 0.7; transition: opacity 0.15s ease;">${renderIcon('x', { size: 14 })}</button>
        `;

        const closeBtn = toast.querySelector('button');
        const dismiss = () => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
            toast.style.transition = 'opacity 200ms ease, transform 200ms ease';
            setTimeout(() => toast.remove(), 200);
        };

        closeBtn.addEventListener('click', dismiss);

        container.appendChild(toast);

        if (duration > 0) {
            setTimeout(dismiss, duration);
        }
    }

    static success(message, duration) {
        this.show(message, 'success', duration);
    }

    static error(message, duration) {
        this.show(message, 'error', duration);
    }

    static warning(message, duration) {
        this.show(message, 'warning', duration);
    }

    static info(message, duration) {
        this.show(message, 'info', duration);
    }
}
