/**
 * Smart Split V2 – Pure ES6 High-Resolution Receipt Attachment Lightbox & Document Viewer
 * Principles: Clean Stage, Smooth Carousel, Minimalistic Chrome
 */

import { api } from '../api.js';
import * as Formatters from '../utils/formatters.js';
import { Toast } from './Toast.js';
import { Modal } from './Modal.js';

export class ReceiptLightbox {
    /**
     * Open interactive receipt viewer for an expense.
     * @param {string} token Group invite token
     * @param {number} expenseId Expense ID
     * @param {string} expenseTitle Expense Title
     * @param {Function} [onReceiptChanged] Callback when a receipt is deleted or added
     */
    static async open(token, expenseId, expenseTitle = 'Receipt', onReceiptChanged = null) {
        let items = [];
        try {
            const res = await api.getReceipts(token, expenseId);
            items = res?.data?.receipts || [];
        } catch (err) {
            Toast.error('Failed to load receipts.');
            return;
        }

        if (items.length === 0) {
            Toast.info('No receipts attached to this transaction.');
            return;
        }

        let currentIndex = 0;

        function renderModalContent() {
            const current = items[currentIndex];
            const sizeStr = current.file_size_bytes
                ? (current.file_size_bytes < 1024 * 1024
                    ? `${(current.file_size_bytes / 1024).toFixed(1)} KB`
                    : `${(current.file_size_bytes / (1024 * 1024)).toFixed(2)} MB`)
                : '—';

            const container = document.createElement('div');
            container.innerHTML = `
                <div style="display: flex; flex-direction: column; gap: var(--space-3);">
                    <!-- Header Info Bar -->
                    <div style="display: flex; justify-content: space-between; align-items: center; background: var(--surface-secondary); padding: var(--space-2) var(--space-3); border-radius: var(--radius-sm); font-size: var(--font-size-xs);">
                        <div>
                            <span style="font-weight: 700; color: var(--text-primary);">${Formatters.escapeHtml(expenseTitle)}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: var(--space-2);">
                            ${items.length > 1 ? `
                                <span class="badge badge-mono" style="font-size: var(--font-size-2xs);">${currentIndex + 1} of ${items.length}</span>
                            ` : ''}
                            <span class="badge badge-settled" style="font-size: var(--font-size-2xs);">${sizeStr}</span>
                        </div>
                    </div>

                    <!-- Viewer Stage -->
                    <div id="lightbox-viewer-stage" style="min-height: 280px; max-height: 60vh; overflow: auto; background: #0f172a; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; position: relative; border: 1px solid var(--border-color);">
                        ${current.is_image ? `
                            <img 
                                src="${Formatters.escapeHtml(current.url)}" 
                                alt="${Formatters.escapeHtml(current.file_name)}" 
                                style="max-width: 100%; max-height: 58vh; object-fit: contain; border-radius: var(--radius-xs); transition: transform 0.2s ease;"
                                id="lightbox-image"
                            />
                        ` : `
                            <div style="padding: var(--space-6); text-align: center; color: #f8fafc;">
                                <div style="font-weight: 700; font-size: var(--font-size-md); margin-bottom: 2px;">${Formatters.escapeHtml(current.file_name)}</div>
                                <div style="font-size: var(--font-size-xs); color: #94a3b8; margin-bottom: var(--space-4);">PDF Document (${sizeStr})</div>
                                <a href="${Formatters.escapeHtml(current.url)}" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm">
                                    <span>Open PDF in New Tab &rarr;</span>
                                </a>
                            </div>
                        `}

                        <!-- Multi-item Carousel Controls -->
                        ${items.length > 1 ? `
                            <button type="button" id="lightbox-prev-btn" class="btn btn-ghost" style="position: absolute; left: 8px; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.6); color: #fff; border-radius: 50%; width: 34px; height: 34px; padding: 0; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; z-index: 10;">
                                ‹
                            </button>
                            <button type="button" id="lightbox-next-btn" class="btn btn-ghost" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.6); color: #fff; border-radius: 50%; width: 34px; height: 34px; padding: 0; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; z-index: 10;">
                                ›
                            </button>
                        ` : ''}
                    </div>

                    <!-- Footer Action Toolbar -->
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: var(--font-size-xs); border-top: 1px solid var(--border-color); padding-top: var(--space-2);">
                        <span style="color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 250px;" title="${Formatters.escapeHtml(current.file_name)}">
                            ${Formatters.escapeHtml(current.file_name)}
                        </span>
                        <div style="display: flex; align-items: center; gap: var(--space-2);">
                            <a href="${Formatters.escapeHtml(current.url)}" download="${Formatters.escapeHtml(current.file_name)}" class="btn btn-secondary btn-sm" style="font-size: var(--font-size-xs);">
                                <span>Download</span>
                            </a>
                            <button type="button" id="lightbox-delete-btn" class="btn btn-ghost btn-sm" style="color: var(--financial-debt); font-size: var(--font-size-xs);">
                                <span>Delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            `;

            return container;
        }

        Modal.open({
            title: `Receipt Viewer (${items.length})`,
            content: renderModalContent(),
            size: 'lg',
            confirmText: 'Done',
            confirmClass: 'btn-primary',
            onMount: (overlay) => {
                function setupInteractions() {
                    const prevBtn = overlay.querySelector('#lightbox-prev-btn');
                    if (prevBtn) {
                        prevBtn.addEventListener('click', () => {
                            currentIndex = (currentIndex - 1 + items.length) % items.length;
                            updateBody();
                        });
                    }

                    const nextBtn = overlay.querySelector('#lightbox-next-btn');
                    if (nextBtn) {
                        nextBtn.addEventListener('click', () => {
                            currentIndex = (currentIndex + 1) % items.length;
                            updateBody();
                        });
                    }

                    const delBtn = overlay.querySelector('#lightbox-delete-btn');
                    if (delBtn) {
                        delBtn.addEventListener('click', async () => {
                            const current = items[currentIndex];
                            if (!confirm(`Are you sure you want to remove receipt "${current.file_name}"?`)) return;

                            try {
                                await api.deleteReceipt(token, expenseId, current.id);
                                Toast.success('Receipt removed.');
                                items.splice(currentIndex, 1);
                                if (items.length === 0) {
                                    Modal.close();
                                } else {
                                    currentIndex = Math.max(0, currentIndex - 1);
                                    updateBody();
                                }
                                if (typeof onReceiptChanged === 'function') onReceiptChanged();
                            } catch (err) {
                                Toast.error(err.message || 'Failed to delete receipt.');
                            }
                        });
                    }
                }

                function updateBody() {
                    const bodyContainer = overlay.querySelector('#modal-body-container');
                    if (bodyContainer) {
                        bodyContainer.innerHTML = '';
                        bodyContainer.appendChild(renderModalContent());
                        setupInteractions();
                    }
                }

                setupInteractions();
            }
        });
    }
}
