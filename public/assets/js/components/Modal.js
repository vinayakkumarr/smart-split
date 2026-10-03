/**
 * Smart Split – Accessible Modal & Dialog Controller
 */

export class Modal {
    static previousActiveElement = null;
    static activeKeyHandler = null;
    static closeTimeout = null;

    static getMountPoint() {
        let overlay = document.getElementById('modal-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'modal-overlay';
            overlay.className = 'modal-overlay';
            document.body.appendChild(overlay);
        }

        if (!overlay.dataset.listenersAttached) {
            overlay.dataset.listenersAttached = 'true';

            // Close on backdrop click (only when click target is the overlay itself)
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    Modal.close();
                }
            });
        }

        return overlay;
    }

    /**
     * Open a modal dialog.
     * @param {Object} options
     * @param {string} options.title
     * @param {string|HTMLElement} options.content
     * @param {string} [options.confirmText] Default: 'Confirm'
     * @param {string} [options.confirmClass] Default: 'btn-primary'
     * @param {Function} [options.onConfirm] Callback when confirm button is clicked
     * @param {boolean} [options.showFooter] Default: true
     * @param {boolean} [options.showCancel] Default: true
     * @param {Function} [options.onMount] Callback after DOM is inserted
     */
    static open(options = {}) {
        if (Modal.closeTimeout) {
            clearTimeout(Modal.closeTimeout);
            Modal.closeTimeout = null;
        }
        Modal.previousActiveElement = document.activeElement;
        const overlay = this.getMountPoint();
        const {
            title = '',
            content = '',
            size = 'md',
            confirmText = 'Confirm',
            confirmClass = 'btn-primary',
            onConfirm = null,
            showFooter = true,
            showCancel = true,
            onMount = null,
        } = options;

        const contentHtml = typeof content === 'string' ? content : '';

        overlay.innerHTML = `
            <div class="modal-dialog modal-${size}" role="dialog" aria-modal="true" aria-labelledby="modal-title-heading">
                <div class="modal-header">
                    <h3 class="modal-title" id="modal-title-heading">${title}</h3>
                    <button type="button" class="modal-close" aria-label="Close modal">&times;</button>
                </div>
                <div class="modal-body" id="modal-body-container">
                    ${contentHtml}
                </div>
                ${showFooter ? `
                <div class="modal-footer">
                    ${showCancel ? `<button type="button" class="btn btn-secondary btn-sm modal-btn-cancel">Cancel</button>` : ''}
                    <button type="button" class="btn ${confirmClass} btn-sm modal-btn-confirm">${confirmText}</button>
                </div>
                ` : ''}
            </div>
        `;

        if (content instanceof HTMLElement) {
            const bodyContainer = overlay.querySelector('#modal-body-container');
            bodyContainer.innerHTML = '';
            bodyContainer.appendChild(content);
        }

        // Attach listeners
        const closeBtn = overlay.querySelector('.modal-close');
        if (closeBtn) closeBtn.addEventListener('click', () => Modal.close());

        const cancelBtn = overlay.querySelector('.modal-btn-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', () => Modal.close());

        const confirmBtn = overlay.querySelector('.modal-btn-confirm');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', async (e) => {
                if (typeof onConfirm === 'function') {
                    confirmBtn.disabled = true;
                    const originalText = confirmBtn.textContent;
                    confirmBtn.textContent = 'Processing...';

                    try {
                        await onConfirm(e);
                        Modal.close();
                    } catch (err) {
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = originalText;
                    }
                } else {
                    Modal.close();
                }
            });
        }

        // Focus Trapping & Escape Key Dismissal (WCAG 2.1)
        if (Modal.activeKeyHandler) {
            window.removeEventListener('keydown', Modal.activeKeyHandler);
        }

        Modal.activeKeyHandler = (e) => {
            if (!overlay.classList.contains('active')) return;

            if (e.key === 'Escape') {
                e.preventDefault();
                Modal.close();
                return;
            }

            if (e.key === 'Tab') {
                const focusable = overlay.querySelectorAll(
                    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                );
                if (focusable.length === 0) return;

                const firstEl = focusable[0];
                const lastEl = focusable[focusable.length - 1];

                if (e.shiftKey) {
                    if (document.activeElement === firstEl || !overlay.contains(document.activeElement)) {
                        lastEl.focus();
                        e.preventDefault();
                    }
                } else {
                    if (document.activeElement === lastEl || !overlay.contains(document.activeElement)) {
                        firstEl.focus();
                        e.preventDefault();
                    }
                }
            }
        };
        window.addEventListener('keydown', Modal.activeKeyHandler);

        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';

        if (typeof onMount === 'function') {
            onMount(overlay);
        }

        // Auto-focus first input or confirm button
        setTimeout(() => {
            const firstInput = overlay.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled])');
            if (firstInput) {
                firstInput.focus();
            } else if (confirmBtn) {
                confirmBtn.focus();
            }
        }, 30);
    }

    /**
     * Open a confirmation modal dialog.
     * @param {Object} options
     * @param {string} [options.title] Default: 'Confirm Action'
     * @param {string} [options.message] Default: 'Are you sure you want to proceed?'
     * @param {string} [options.confirmText] Default: 'Confirm'
     * @param {string} [options.confirmClass] Default: 'btn-danger'
     * @param {Function} [options.onConfirm] Callback when confirmed
     */
    static confirm(options = {}) {
        const {
            title = 'Confirm Action',
            message = 'Are you sure you want to proceed?',
            confirmText = 'Confirm',
            confirmClass = 'btn-danger',
            onConfirm = null,
        } = options;

        Modal.open({
            title,
            content: `<p class="modal-confirm-message" style="margin: 0; color: var(--text-secondary, #6b7280); font-size: 0.95rem; line-height: 1.5;">${message}</p>`,
            confirmText,
            confirmClass,
            showFooter: true,
            showCancel: true,
            onConfirm,
        });
    }

    /**
     * Close active modal.
     */
    static close() {
        const overlay = document.getElementById('modal-overlay');
        if (overlay) {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            if (Modal.activeKeyHandler) {
                window.removeEventListener('keydown', Modal.activeKeyHandler);
                Modal.activeKeyHandler = null;
            }
            if (Modal.previousActiveElement && typeof Modal.previousActiveElement.focus === 'function') {
                Modal.previousActiveElement.focus();
                Modal.previousActiveElement = null;
            }
            if (Modal.closeTimeout) {
                clearTimeout(Modal.closeTimeout);
            }
            Modal.closeTimeout = setTimeout(() => {
                if (!overlay.classList.contains('active')) {
                    overlay.innerHTML = '';
                }
                Modal.closeTimeout = null;
            }, 200);
        }
    }
}
