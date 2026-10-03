/**
 * Settlement plan view with UPI QR generation and debt flow diagram.
 * Features: Role-Aware Contextual Actions, Attribution Tracking, Two-Party Confirmation Lifecycle,
 * Dynamic UPI QR Code, Deep-Links, Interactive SVG Debt Flow Diagram, Partial Payments, Immutable Reversals
 */

import { api } from '../api.js';
import * as Formatters from '../utils/formatters.js';
import { Toast } from './Toast.js';
import { Modal } from './Modal.js';
import { QRCode } from '../utils/qrcode.js';
import { renderIcon } from '../utils/icons.js';
import { store } from '../state.js';

export class SettlementPlan {
    /** @type {Map<string, 'cards' | 'diagram'>} Internal cache for active presentation view */
    static _activeViewMap = new Map();

    /**
     * Get the active view ('cards' or 'diagram') for a workspace token.
     * Checks memory cache first, then sessionStorage, defaulting to 'cards'.
     * @param {string} token
     * @returns {'cards' | 'diagram'}
     */
    static getActiveView(token) {
        if (token && SettlementPlan._activeViewMap.has(token)) {
            return SettlementPlan._activeViewMap.get(token);
        }
        if (typeof sessionStorage !== 'undefined' && token) {
            try {
                const saved = sessionStorage.getItem(`smartsplit_settlement_view_${token}`);
                if (saved === 'cards' || saved === 'diagram') {
                    SettlementPlan._activeViewMap.set(token, saved);
                    return saved;
                }
            } catch (_) {}
        }
        return 'cards';
    }

    /**
     * Set and persist the active view ('cards' or 'diagram') for a workspace token.
     * @param {string} token
     * @param {'cards' | 'diagram'} view
     */
    static setActiveView(token, view) {
        if (view !== 'cards' && view !== 'diagram') return;
        if (token) {
            SettlementPlan._activeViewMap.set(token, view);
            if (typeof sessionStorage !== 'undefined') {
                try {
                    sessionStorage.setItem(`smartsplit_settlement_view_${token}`, view);
                } catch (_) {}
            }
        }
    }

    /**
     * Build standard formatted nudge text message.
     * @param {Object} params
     * @returns {string}
     */
    static getNudgeMessage({ groupName, fromName, toName, amountFormatted, payeeUpiId, upiUrl, workspaceUrl }) {
        const wsName = groupName || (typeof document !== 'undefined' ? document.querySelector('.exec-title')?.textContent?.trim() : '') || 'Smart Split Workspace';
        const targetUrl = workspaceUrl || (typeof window !== 'undefined' ? window.location.href : '');

        const messageLines = [
            'Smart Split Payment Reminder',
            `Workspace: ${wsName}`,
            `${fromName} ➔ ${toName}`,
            `Amount: ${amountFormatted}`,
        ];

        if (payeeUpiId) {
            messageLines.push(`Payee UPI: ${payeeUpiId}`);
        }
        if (upiUrl) {
            messageLines.push(`Pay Link: ${upiUrl}`);
        }
        if (targetUrl) {
            messageLines.push(`Workspace: ${targetUrl}`);
        }

        return messageLines.join('\n');
    }

    /**
     * Send payment nudge via SMS deep-link.
     * @param {Object} params
     */
    static shareSmsNudge(params) {
        const message = SettlementPlan.getNudgeMessage(params);
        const smsUrl = `sms:?body=${encodeURIComponent(message)}`;
        if (typeof window !== 'undefined') {
            window.location.href = smsUrl;
        }
    }

    /**
     * Open dedicated multi-channel Nudge dialog (WhatsApp, SMS, Copy message).
     * @param {Object} params
     */
    static openNudgeModal(params) {
        const message = SettlementPlan.getNudgeMessage(params);
        const whatsappUrl = `https://api.whatsapp.com/send?text=${encodeURIComponent(message)}`;
        const smsUrl = `sms:?body=${encodeURIComponent(message)}`;

        Modal.open({
            title: `Nudge ${Formatters.escapeHtml(params.fromName)} to Settle`,
            size: 'sm',
            content: `
                <div style="display: flex; flex-direction: column; gap: var(--space-3);">
                    <p style="font-size: var(--font-size-sm); color: var(--text-secondary); margin: 0;">
                        Send a friendly payment reminder with payment details and direct UPI link:
                    </p>

                    <div style="display: flex; flex-direction: column; gap: var(--space-2);">
                        <a href="${whatsappUrl}" target="_blank" rel="noopener noreferrer" class="nudge-channel-btn" id="btn-nudge-whatsapp">
                            <span style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: #25D366; display: inline-flex;">${renderIcon('whatsapp', { size: 16 })}</span>
                                <span>Send via WhatsApp</span>
                            </span>
                            <span style="font-size: var(--font-size-xs); color: var(--text-muted);">Open App &rarr;</span>
                        </a>

                        <a href="${smsUrl}" class="nudge-channel-btn" id="btn-nudge-sms">
                            <span style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: var(--brand-primary); display: inline-flex;">${renderIcon('messageSquare', { size: 16 })}</span>
                                <span>Send via SMS</span>
                            </span>
                            <span style="font-size: var(--font-size-xs); color: var(--text-muted);">Text Message &rarr;</span>
                        </a>

                        <button type="button" class="nudge-channel-btn" id="btn-nudge-copy">
                            <span style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: var(--text-secondary); display: inline-flex;">${renderIcon('copy', { size: 16 })}</span>
                                <span>Copy Reminder Text</span>
                            </span>
                            <span style="font-size: var(--font-size-xs); color: var(--text-muted);">Clipboard</span>
                        </button>
                    </div>

                    <div style="background: var(--surface-tertiary, var(--surface-secondary)); border: 1px solid var(--border-subtle); border-radius: var(--radius-xs); padding: var(--space-2) var(--space-3); font-size: var(--font-size-2xs); color: var(--text-muted); font-family: var(--font-mono); white-space: pre-line; max-height: 120px; overflow-y: auto;">
                        ${Formatters.escapeHtml(message)}
                    </div>
                </div>
            `,
            confirmText: 'Done',
            confirmClass: 'btn-secondary',
            onMount: (overlay) => {
                const copyBtn = overlay.querySelector('#btn-nudge-copy');
                if (copyBtn) {
                    copyBtn.addEventListener('click', async () => {
                        try {
                            if (navigator.clipboard) {
                                await navigator.clipboard.writeText(message);
                            }
                            Toast.success('Nudge message copied to clipboard.');
                        } catch {
                            Toast.info('Message ready to copy.');
                        }
                    });
                }
            }
        });
    }

    /**
     * Share payment intent via Web Share API or WhatsApp.
     * @param {Object} params
     * @param {string} [params.groupName]
     * @param {string} params.fromName
     * @param {string} params.toName
     * @param {string} params.amountFormatted
     * @param {string} [params.payeeUpiId]
     * @param {string} [params.upiUrl]
     * @param {string} [params.workspaceUrl]
     */
    static async sharePaymentIntent({ groupName, fromName, toName, amountFormatted, payeeUpiId, upiUrl, workspaceUrl }) {
        const targetUrl = workspaceUrl || (typeof window !== 'undefined' ? window.location.href : '');
        const fullMessage = SettlementPlan.getNudgeMessage({ groupName, fromName, toName, amountFormatted, payeeUpiId, upiUrl, workspaceUrl });
        const shareTitle = `Smart Split Payment: ${fromName} to ${toName}`;

        if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
            try {
                await navigator.share({
                    title: shareTitle,
                    text: fullMessage,
                    url: targetUrl,
                });
                return;
            } catch (err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
            }
        }

        const whatsappUrl = `https://api.whatsapp.com/send?text=${encodeURIComponent(fullMessage)}`;
        if (typeof window !== 'undefined') {
            window.open(whatsappUrl, '_blank', 'noopener,noreferrer');
        }
    }

    /**
     * Extract optional UTR / payment reference string from settlement notes or reference_id.
     * @param {string} [notes]
     * @param {string} [referenceId]
     * @returns {string|null}
     */
    static extractUtr(notes, referenceId = null) {
        if (referenceId && typeof referenceId === 'string' && referenceId.trim().length > 0) {
            return referenceId.trim();
        }
        if (!notes || typeof notes !== 'string') return null;
        const match = notes.match(/UTR:\s*([^•\n\]]+)/i);
        if (match && match[1]) {
            const utr = match[1].trim();
            return utr.length > 0 ? utr : null;
        }
        return null;
    }

    /**
     * Find the currently authenticated / claimed member profile in this workspace.
     * @param {Array} members
     * @returns {Object|null}
     */
    static getMyClaimedMember(members = []) {
        const state = store.getState();
        const isAuthenticated = Boolean(state.isAuthenticated);
        const currentUser = state.currentUser;

        if (isAuthenticated && currentUser && Array.isArray(members)) {
            const found = members.find((m) => m.user_id !== null && m.user_id !== undefined && Number(m.user_id) === Number(currentUser.id));
            if (found) return found;
        }
        return null;
    }

    /**
     * Open the Role-Aware Settlement & Verification Modal.
     * @param {Object} options
     * @param {string} options.token
     * @param {Object} options.tx
     * @param {Array} [options.members]
     * @param {string} [options.currency] Default: 'INR'
     * @param {string} [options.workspaceName]
     * @param {Function} [options.onUpdate]
     */
    static openSettleModal({ token, tx, members = [], currency = 'INR', workspaceName = '', onUpdate = null }) {
        if (!tx) return;

        const allMembers = members.length > 0 ? members : (store.getState().members || []);
        const myClaimedMember = SettlementPlan.getMyClaimedMember(allMembers);

        const payerId = tx.from_member_id || tx.from_id;
        const payeeId = tx.to_member_id || tx.to_id;
        const payerName = tx.from_name;
        const payeeName = tx.to_name;

        const isPayer = myClaimedMember && (Number(myClaimedMember.id) === Number(payerId) || myClaimedMember.name.toLowerCase() === payerName.toLowerCase());
        const isPayee = myClaimedMember && (Number(myClaimedMember.id) === Number(payeeId) || myClaimedMember.name.toLowerCase() === payeeName.toLowerCase());

        const amountDecimal = (tx.amount_cents / 100).toFixed(2);
        const halfDecimal = (Math.floor(tx.amount_cents / 2) / 100).toFixed(2);

        const payeeMember = allMembers.find(m => Number(m.id) === Number(payeeId));
        const rawPayeeUpi = (tx.to_upi_id || payeeMember?.upi_id || '').trim();
        const initialHasValidUpi = Formatters.isValidUpiVpa(rawPayeeUpi);
        const payeeUpiId = initialHasValidUpi ? rawPayeeUpi : '';

        const initialUpiUrl = initialHasValidUpi ? Formatters.buildUpiPaymentUrl({
            payeeUpi: payeeUpiId,
            payeeName,
            amountDecimal,
            currency,
            transactionNote: 'SmartSplit Settlement'
        }) : '';
        const initialQrSvg = initialHasValidUpi ? QRCode.generateSvg(initialUpiUrl, { size: 140, margin: 2 }) : '';

        // Modal title and contextual messaging
        let modalTitle = `Record Transfer: ${Formatters.escapeHtml(payerName)} ➔ ${Formatters.escapeHtml(payeeName)}`;
        let submitButtonText = 'Record Transfer';
        let bannerNotice = '';

        if (isPayee) {
            modalTitle = `Confirm Receipt from ${Formatters.escapeHtml(payerName)}`;
            submitButtonText = 'Confirm Receipt (Instant Settle)';
            bannerNotice = `
                <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: var(--radius-xs); padding: var(--space-2) var(--space-3); font-size: var(--font-size-xs); color: var(--text-primary); display: flex; align-items: center; gap: 8px; margin-bottom: var(--space-3);">
                    <span style="color: var(--financial-credit); display: inline-flex;">${renderIcon('checkCircle', { size: 16 })}</span>
                    <span><strong>Direct Creditor Verification:</strong> As the recipient, confirming this receipt will immediately reconcile and clear the balance in the ledger.</span>
                </div>
            `;
        } else if (isPayer) {
            modalTitle = `I Paid ${Formatters.escapeHtml(payeeName)}`;
            submitButtonText = 'Submit Payment for Verification';
            bannerNotice = `
                <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: var(--radius-xs); padding: var(--space-2) var(--space-3); font-size: var(--font-size-xs); color: var(--text-primary); display: flex; align-items: center; gap: 8px; margin-bottom: var(--space-3);">
                    <span style="color: #d97706; display: inline-flex;">${renderIcon('clock', { size: 16 })}</span>
                    <span><strong>Creditor Verification:</strong> After recording, this payment will be marked as <em>Pending</em> until <strong>${Formatters.escapeHtml(payeeName)}</strong> confirms receipt.</span>
                </div>
            `;
        } else {
            bannerNotice = `
                <div style="background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-xs); padding: var(--space-2) var(--space-3); font-size: var(--font-size-xs); color: var(--text-secondary); display: flex; align-items: center; gap: 8px; margin-bottom: var(--space-3);">
                    <span style="color: var(--text-muted); display: inline-flex;">${renderIcon('users', { size: 16 })}</span>
                    <span>Recording payment between <strong>${Formatters.escapeHtml(payerName)}</strong> and <strong>${Formatters.escapeHtml(payeeName)}</strong>. Action will be attributed to you in the audit log.</span>
                </div>
            `;
        }

        Modal.open({
            title: modalTitle,
            size: 'md',
            content: `
                <div style="margin-bottom: var(--space-3);">
                    ${bannerNotice}

                    <!-- Transfer Summary Box -->
                    <div style="background: var(--surface-secondary); padding: var(--space-3) var(--space-4); border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: var(--space-3); display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: var(--font-size-2xs); text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.04em;">Transfer Flow</div>
                            <div style="font-size: var(--font-size-sm); font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 6px; margin-top: 2px;">
                                <span>${Formatters.escapeHtml(payerName)}</span>
                                <span style="color: var(--text-muted);">──►</span>
                                <span>${Formatters.escapeHtml(payeeName)}</span>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: var(--font-size-2xs); text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.04em;">Outstanding Debt</div>
                            <div style="font-size: var(--font-size-lg); font-weight: 700; font-family: var(--font-mono); color: var(--financial-credit);">
                                ${Formatters.formatCurrency(tx.amount_cents, currency)}
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method Selector -->
                    <div class="form-group" style="margin-bottom: var(--space-3);">
                        <label class="form-label" for="settle-method-select" style="margin-bottom: 3px;">Payment Method *</label>
                        <select id="settle-method-select" class="form-select" style="width: 100%;">
                            <option value="UPI" selected>UPI / QR Payment</option>
                            <option value="CASH">Cash in Hand</option>
                            <option value="BANK_TRANSFER">Bank Transfer (NEFT / IMPS / RTGS)</option>
                            <option value="OTHER">Other / Settle via Other App</option>
                        </select>
                    </div>

                    <!-- Dynamic UPI QR Box (Visible when UPI selected) -->
                    <div id="settle-upi-section" style="display: block;">
                        <div id="settle-qr-container" class="qr-container-card" style="display: ${initialHasValidUpi ? 'block' : 'none'};">
                            <div id="settle-qr-code-mount" class="qr-code-svg-box">
                                ${initialQrSvg}
                            </div>
                            <div class="qr-apps-badge-row">
                                <span>Scan with any UPI App</span>
                            </div>
                        </div>

                        <div id="settle-upi-unset-notice" style="display: ${initialHasValidUpi ? 'none' : 'block'}; background: var(--surface-secondary); border: 1px dashed var(--border-color); border-radius: var(--radius-sm); padding: var(--space-3); margin-bottom: var(--space-3); text-align: center;">
                            <div style="font-size: var(--font-size-xs); font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">No UPI ID Configured</div>
                            <div style="font-size: var(--font-size-2xs); color: var(--text-muted); line-height: 1.4;">
                                ${isPayee ? `
                                    You have not configured a Personal UPI ID yet. Enter a temporary VPA below or <a href="#/settings" style="color: var(--brand-primary); font-weight: 600;">add one in Settings</a> so group members can pay you automatically.
                                ` : `
                                    <strong>${Formatters.escapeHtml(payeeName)}</strong> has not set a UPI ID. Enter a valid UPI ID (VPA) below to generate a live QR code and payment link.
                                `}
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: var(--space-3);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
                                <label class="form-label" for="settle-upi-input" style="margin-bottom: 0;">
                                    Payee UPI ID (VPA)
                                    ${initialHasValidUpi && isPayee ? `<span class="badge badge-credit badge-mono" style="font-size: 0.6rem; padding: 1px 4px; margin-left: 4px;">Personal UPI</span>` : ''}
                                </label>
                                <div style="display: flex; gap: 4px;">
                                    ${initialHasValidUpi && isPayee ? `<a href="#/settings" class="btn btn-ghost btn-sm" style="font-size: var(--font-size-2xs); padding: 1px 6px; min-height: 20px;">Edit in Settings</a>` : ''}
                                    <button type="button" class="btn btn-ghost btn-sm" id="btn-copy-upi-id" style="font-size: var(--font-size-2xs); padding: 1px 6px; min-height: 20px;">Copy VPA</button>
                                </div>
                            </div>
                            <input type="text" id="settle-upi-input" class="form-input" value="${Formatters.escapeHtml(payeeUpiId)}" placeholder="e.g. mobile@upi, name@okaxis" style="font-family: var(--font-mono); font-size: var(--font-size-xs);">
                        </div>

                        <div class="upi-actions-row" style="margin-bottom: var(--space-3);">
                            <a id="settle-upi-deeplink" href="${initialUpiUrl || '#'}" class="btn btn-primary btn-sm ${initialHasValidUpi ? '' : 'disabled'}" style="flex: 1; text-decoration: none; text-align: center; display: inline-flex; align-items: center; justify-content: center; gap: 4px; ${initialHasValidUpi ? '' : 'pointer-events: none; opacity: 0.5;'}" ${initialHasValidUpi ? '' : 'aria-disabled="true"'}>
                                ${renderIcon('externalLink', { size: 13 })}
                                <span>Launch UPI App</span>
                            </a>
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-copy-upi-link" style="flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                ${renderIcon('copy', { size: 13 })}
                                <span>Copy Link</span>
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-whatsapp-settle" style="flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 4px;" title="Share payment details via WhatsApp">
                                ${renderIcon('whatsapp', { size: 13 })}
                                <span>WhatsApp</span>
                            </button>
                        </div>
                    </div>

                    <!-- Settlement Amount -->
                    <div class="form-group" style="margin-bottom: var(--space-3);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
                            <label class="form-label" for="settle-amount-input" style="margin-bottom: 0;">Payment Amount (₹) *</label>
                            <div style="display: flex; gap: 4px;">
                                <button type="button" class="btn btn-ghost btn-sm" id="btn-quick-half" style="font-size: var(--font-size-2xs); padding: 1px 6px; min-height: 20px;">50% (₹${halfDecimal})</button>
                                <button type="button" class="btn btn-ghost btn-sm" id="btn-quick-full" style="font-size: var(--font-size-2xs); padding: 1px 6px; min-height: 20px;">100% (₹${amountDecimal})</button>
                            </div>
                        </div>
                        <div class="input-currency-wrapper">
                            <span class="input-currency-symbol">₹</span>
                            <input type="text" inputmode="decimal" id="settle-amount-input" class="form-input input-currency" value="${amountDecimal}" required>
                        </div>
                    </div>

                    <!-- Payment Reference / UTR -->
                    <div class="form-group" style="margin-bottom: var(--space-3);">
                        <label class="form-label" for="settle-utr-input" style="margin-bottom: 2px;">Payment Reference / UTR Number (Optional)</label>
                        <input type="text" id="settle-utr-input" class="form-input" placeholder="e.g. UPI Ref 409281928391, Cash receipt, Cheque #019" style="font-family: var(--font-mono); font-size: var(--font-size-xs);">
                    </div>

                    <!-- Recorded By Member Attribution -->
                    <div class="form-group" style="margin-bottom: var(--space-3);">
                        <label class="form-label" for="settle-recorder-select" style="margin-bottom: 2px;">Recorded By (Audit Attribution) *</label>
                        ${myClaimedMember ? `
                            <div style="padding: var(--space-2) var(--space-3); background: var(--surface-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-xs); font-size: var(--font-size-sm); display: flex; align-items: center; gap: 6px;">
                                <span style="font-weight: 600; color: var(--text-primary);">${Formatters.escapeHtml(myClaimedMember.name)}</span>
                                <span class="badge badge-secondary badge-mono" style="font-size: 0.65rem;">You</span>
                            </div>
                            <input type="hidden" id="settle-recorder-select" value="${myClaimedMember.id}">
                        ` : `
                            <select id="settle-recorder-select" class="form-select" style="width: 100%;">
                                ${allMembers.map(m => `
                                    <option value="${m.id}" ${Number(m.id) === Number(payerId) ? 'selected' : ''}>
                                        ${Formatters.escapeHtml(m.name)}
                                    </option>
                                `).join('')}
                            </select>
                        `}
                    </div>
                </div>
            `,
            confirmText: submitButtonText,
            confirmClass: isPayee ? 'btn-success' : 'btn-primary',
            onMount: (overlay) => {
                const methodSelect = overlay.querySelector('#settle-method-select');
                const upiSection = overlay.querySelector('#settle-upi-section');
                const amtInput = overlay.querySelector('#settle-amount-input');
                const upiInput = overlay.querySelector('#settle-upi-input');
                const qrContainer = overlay.querySelector('#settle-qr-container');
                const unsetNotice = overlay.querySelector('#settle-upi-unset-notice');
                const qrMount = overlay.querySelector('#settle-qr-code-mount');
                const deeplinkBtn = overlay.querySelector('#settle-upi-deeplink');
                const copyVpaBtn = overlay.querySelector('#btn-copy-upi-id');
                const copyLinkBtn = overlay.querySelector('#btn-copy-upi-link');
                const whatsappBtn = overlay.querySelector('#btn-whatsapp-settle');
                const halfBtn = overlay.querySelector('#btn-quick-half');
                const fullBtn = overlay.querySelector('#btn-quick-full');

                if (methodSelect && upiSection) {
                    methodSelect.addEventListener('change', () => {
                        upiSection.style.display = methodSelect.value === 'UPI' ? 'block' : 'none';
                    });
                }

                const refreshQr = () => {
                    const rawAmt = amtInput?.value?.trim() || amountDecimal;
                    const amtParsed = parseFloat(rawAmt);
                    const validAmt = isNaN(amtParsed) || amtParsed <= 0 ? amountDecimal : amtParsed.toFixed(2);
                    const curUpi = (upiInput?.value || '').trim();
                    const isValid = Formatters.isValidUpiVpa(curUpi);

                    if (isValid) {
                        const newUrl = Formatters.buildUpiPaymentUrl({
                            payeeUpi: curUpi,
                            payeeName,
                            amountDecimal: validAmt,
                            currency,
                            transactionNote: 'SmartSplit Settlement'
                        });
                        if (deeplinkBtn) {
                            deeplinkBtn.href = newUrl;
                            deeplinkBtn.classList.remove('disabled');
                            deeplinkBtn.style.pointerEvents = '';
                            deeplinkBtn.style.opacity = '';
                            deeplinkBtn.removeAttribute('aria-disabled');
                        }
                        if (qrMount) {
                            qrMount.innerHTML = QRCode.generateSvg(newUrl, { size: 140, margin: 2 });
                        }
                        if (qrContainer) qrContainer.style.display = 'block';
                        if (unsetNotice) unsetNotice.style.display = 'none';
                    } else {
                        if (deeplinkBtn) {
                            deeplinkBtn.href = '#';
                            deeplinkBtn.classList.add('disabled');
                            deeplinkBtn.style.pointerEvents = 'none';
                            deeplinkBtn.style.opacity = '0.5';
                            deeplinkBtn.setAttribute('aria-disabled', 'true');
                        }
                        if (qrContainer) qrContainer.style.display = 'none';
                        if (unsetNotice) unsetNotice.style.display = 'block';
                    }
                };

                if (amtInput) amtInput.addEventListener('input', refreshQr);
                if (upiInput) upiInput.addEventListener('input', refreshQr);

                if (halfBtn) halfBtn.addEventListener('click', () => {
                    if (amtInput) {
                        amtInput.value = halfDecimal;
                        refreshQr();
                    }
                });

                if (fullBtn) fullBtn.addEventListener('click', () => {
                    if (amtInput) {
                        amtInput.value = amountDecimal;
                        refreshQr();
                    }
                });

                if (copyVpaBtn) {
                    copyVpaBtn.addEventListener('click', async () => {
                        const vpa = (upiInput?.value || '').trim();
                        if (!Formatters.isValidUpiVpa(vpa)) {
                            Toast.warning('Please enter a valid UPI ID (e.g. name@bank).');
                            return;
                        }
                        if (navigator.clipboard) await navigator.clipboard.writeText(vpa);
                        Toast.success('UPI ID copied to clipboard.');
                    });
                }

                if (copyLinkBtn) {
                    copyLinkBtn.addEventListener('click', async () => {
                        const curUpi = (upiInput?.value || '').trim();
                        if (!Formatters.isValidUpiVpa(curUpi)) {
                            Toast.warning('Please enter a valid UPI ID first.');
                            return;
                        }
                        const rawAmt = amtInput?.value?.trim() || amountDecimal;
                        const amtParsed = parseFloat(rawAmt);
                        const validAmt = isNaN(amtParsed) || amtParsed <= 0 ? amountDecimal : amtParsed.toFixed(2);
                        const url = Formatters.buildUpiPaymentUrl({
                            payeeUpi: curUpi,
                            payeeName,
                            amountDecimal: validAmt,
                            currency,
                            transactionNote: 'SmartSplit Settlement'
                        });
                        if (navigator.clipboard) await navigator.clipboard.writeText(url);
                        Toast.success('UPI payment link copied.');
                    });
                }

                if (whatsappBtn) {
                    whatsappBtn.addEventListener('click', async () => {
                        const rawAmt = parseFloat(amtInput?.value || amountDecimal);
                        const amtDecimal = isNaN(rawAmt) || rawAmt <= 0 ? amountDecimal : rawAmt.toFixed(2);
                        const amtCents = isNaN(rawAmt) || rawAmt <= 0 ? tx.amount_cents : Math.round(rawAmt * 100);
                        const curUpi = (upiInput?.value || '').trim();
                        const isValid = Formatters.isValidUpiVpa(curUpi);
                        const curUpiUrl = isValid ? Formatters.buildUpiPaymentUrl({
                            payeeUpi: curUpi,
                            payeeName,
                            amountDecimal: amtDecimal,
                            currency,
                            transactionNote: 'SmartSplit Settlement'
                        }) : '';

                        await SettlementPlan.sharePaymentIntent({
                            groupName: workspaceName,
                            fromName: payerName,
                            toName: payeeName,
                            amountFormatted: Formatters.formatCurrency(amtCents, currency),
                            payeeUpiId: isValid ? curUpi : '',
                            upiUrl: curUpiUrl,
                            workspaceUrl: window.location.href,
                        });
                    });
                }
            },
            onConfirm: async () => {
                const overlay = Modal.getMountPoint();
                const amtInput = overlay.querySelector('#settle-amount-input');
                const utrInput = overlay.querySelector('#settle-utr-input');
                const methodSelect = overlay.querySelector('#settle-method-select');
                const recorderSelect = overlay.querySelector('#settle-recorder-select');
                const rawAmt = parseFloat(amtInput?.value || '0');

                if (isNaN(rawAmt) || rawAmt <= 0) {
                    Toast.error('Please enter a valid settlement amount.');
                    throw new Error('Invalid settlement amount');
                }

                const amountCents = Math.round(rawAmt * 100);
                const utrVal = utrInput?.value?.trim() || '';
                const paymentMethod = methodSelect?.value || 'OTHER';
                const recordedByMemberId = recorderSelect ? Number(recorderSelect.value) : (myClaimedMember ? Number(myClaimedMember.id) : Number(payerId));

                let notes = `Settled via Smart Split (${currency})`;
                if (utrVal) {
                    const cleanUtr = utrVal.replace(/^UTR:\s*/i, '');
                    notes += ` • UTR: ${cleanUtr}`;
                }

                try {
                    const res = await api.createSettlement(token, {
                        payer_id: payerId,
                        payee_id: payeeId,
                        amount_cents: amountCents,
                        payment_method: paymentMethod,
                        reference_id: utrVal || null,
                        recorded_by_member_id: recordedByMemberId,
                        notes,
                    });

                    if (res?.data?.settlement?.status === 'CONFIRMED') {
                        Toast.success(`Confirmed transfer of ${Formatters.formatCurrency(amountCents, currency)}.`);
                    } else {
                        Toast.success(`Payment of ${Formatters.formatCurrency(amountCents, currency)} submitted for confirmation.`);
                    }

                    if (typeof onUpdate === 'function') onUpdate();
                } catch (err) {
                    Toast.error(err.message || 'Failed to record settlement.');
                    throw err;
                }
            }
        });
    }

    /**
     * Open confirmation modal for a pending settlement payment.
     * @param {Object} options
     */
    static openConfirmReceiptModal({ token, settlement, currency = 'INR', onUpdate = null }) {
        if (!settlement) return;

        Modal.open({
            title: 'Confirm Payment Receipt',
            size: 'sm',
            content: `
                <div style="display: flex; flex-direction: column; gap: var(--space-3);">
                    <p style="font-size: var(--font-size-sm); color: var(--text-primary); margin: 0;">
                        Are you sure you have received <strong>${Formatters.formatCurrency(settlement.amount_cents, currency)}</strong> from <strong>${Formatters.escapeHtml(settlement.payer.name)}</strong>?
                    </p>
                    <div style="background: var(--surface-secondary); padding: var(--space-2) var(--space-3); border-radius: var(--radius-xs); font-size: var(--font-size-xs); color: var(--text-secondary); border: 1px solid var(--border-subtle);">
                        <div><strong>Method:</strong> ${Formatters.escapeHtml(settlement.payment_method)}</div>
                        ${settlement.reference_id ? `<div><strong>Ref / UTR:</strong> ${Formatters.escapeHtml(settlement.reference_id)}</div>` : ''}
                        ${settlement.recorded_by ? `<div><strong>Recorded By:</strong> ${Formatters.escapeHtml(settlement.recorded_by.name)}</div>` : ''}
                    </div>
                </div>
            `,
            confirmText: 'Yes, Confirm Receipt',
            confirmClass: 'btn-success',
            onConfirm: async () => {
                try {
                    await api.confirmSettlement(token, settlement.id);
                    Toast.success('Settlement confirmed and reconciled.');
                    if (typeof onUpdate === 'function') onUpdate();
                } catch (err) {
                    Toast.error(err.message || 'Failed to confirm settlement.');
                    throw err;
                }
            }
        });
    }

    /**
     * Open dispute modal for a pending settlement payment.
     * @param {Object} options
     */
    static openDisputeModal({ token, settlement, currency = 'INR', onUpdate = null }) {
        if (!settlement) return;

        Modal.open({
            title: 'Dispute Payment Record',
            size: 'sm',
            content: `
                <div style="display: flex; flex-direction: column; gap: var(--space-3);">
                    <p style="font-size: var(--font-size-sm); color: var(--text-primary); margin: 0;">
                        Dispute the <strong>${Formatters.formatCurrency(settlement.amount_cents, currency)}</strong> payment reported by <strong>${Formatters.escapeHtml(settlement.payer.name)}</strong>:
                    </p>
                    <div class="form-group">
                        <label class="form-label" for="dispute-reason-input" style="margin-bottom: 2px;">Dispute Reason (Optional)</label>
                        <input type="text" id="dispute-reason-input" class="form-input" placeholder="e.g. Payment not received in bank, Wrong amount">
                    </div>
                </div>
            `,
            confirmText: 'Dispute Payment',
            confirmClass: 'btn-danger',
            onConfirm: async () => {
                const overlay = Modal.getMountPoint();
                const reasonInput = overlay.querySelector('#dispute-reason-input');
                const reason = reasonInput?.value?.trim() || '';

                try {
                    await api.disputeSettlement(token, settlement.id, reason);
                    Toast.info('Payment marked as disputed.');
                    if (typeof onUpdate === 'function') onUpdate();
                } catch (err) {
                    Toast.error(err.message || 'Failed to dispute settlement.');
                    throw err;
                }
            }
        });
    }

    /**
     * Open reversal modal for a confirmed settlement payment.
     * @param {Object} options
     */
    static openReverseModal({ token, settlement, currency = 'INR', onUpdate = null }) {
        if (!settlement) return;

        Modal.open({
            title: 'Reverse Settlement',
            size: 'sm',
            content: `
                <div style="display: flex; flex-direction: column; gap: var(--space-3);">
                    <p style="font-size: var(--font-size-sm); color: var(--text-primary); margin: 0;">
                        Reverse the confirmed settlement of <strong>${Formatters.formatCurrency(settlement.amount_cents, currency)}</strong> between <strong>${Formatters.escapeHtml(settlement.payer.name)}</strong> and <strong>${Formatters.escapeHtml(settlement.payee.name)}</strong>?
                    </p>
                    <div class="form-group">
                        <label class="form-label" for="reversal-reason-input" style="margin-bottom: 2px;">Reason for Reversal (Optional)</label>
                        <input type="text" id="reversal-reason-input" class="form-input" placeholder="e.g. Transaction refunded, Recorded by mistake">
                    </div>
                </div>
            `,
            confirmText: 'Reverse Settlement',
            confirmClass: 'btn-danger',
            onConfirm: async () => {
                const overlay = Modal.getMountPoint();
                const reasonInput = overlay.querySelector('#reversal-reason-input');
                const reason = reasonInput?.value?.trim() || '';

                try {
                    await api.reverseSettlement(token, settlement.id, reason);
                    Toast.success('Settlement reversed and balance restored.');
                    if (typeof onUpdate === 'function') onUpdate();
                } catch (err) {
                    Toast.error(err.message || 'Failed to reverse settlement.');
                    throw err;
                }
            }
        });
    }

    /**
     * Build distinct debtor and creditor summary nodes from simplified transactions.
     * @param {Array} transactions
     * @returns {{ debtors: Array, creditors: Array }}
     */
    static buildDebtNodes(transactions = []) {
        const debtorsMap = new Map();
        const creditorsMap = new Map();

        transactions.forEach((tx) => {
            const debtorId = tx.from_member_id || tx.from_id || tx.from_name;
            const creditorId = tx.to_member_id || tx.to_id || tx.to_name;
            const amountCents = tx.amount_cents || 0;

            if (!debtorsMap.has(debtorId)) {
                debtorsMap.set(debtorId, {
                    id: debtorId,
                    name: tx.from_name,
                    totalOutgoingCents: 0,
                    transfers: [],
                });
            }
            const dNode = debtorsMap.get(debtorId);
            dNode.totalOutgoingCents += amountCents;
            dNode.transfers.push(tx);

            if (!creditorsMap.has(creditorId)) {
                creditorsMap.set(creditorId, {
                    id: creditorId,
                    name: tx.to_name,
                    totalIncomingCents: 0,
                    transfers: [],
                });
            }
            const cNode = creditorsMap.get(creditorId);
            cNode.totalIncomingCents += amountCents;
            cNode.transfers.push(tx);
        });

        return {
            debtors: Array.from(debtorsMap.values()),
            creditors: Array.from(creditorsMap.values()),
        };
    }

    /**
     * Render the visual SVG Flow Diagram for cash-flow network.
     * @param {Object} options
     * @returns {string} SVG HTML string
     */
    static renderFlowDiagramSvg({ transactions = [], currency = 'INR', token = '' }) {
        if (!transactions || transactions.length === 0) {
            return `
                <div class="empty-state" style="padding: var(--space-6) var(--space-2);">
                    <div class="empty-state-title">No Transfers Due</div>
                    <div class="empty-state-text">No transfers required. Everyone is settled.</div>
                </div>
            `;
        }

        const { debtors, creditors } = SettlementPlan.buildDebtNodes(transactions);
        const nodeWidth = 200;
        const nodeHeight = 56;
        const nodeSpacing = 20;
        const topMargin = 60;
        const maxNodes = Math.max(debtors.length, creditors.length);
        const svgHeight = Math.max(220, topMargin + maxNodes * (nodeHeight + nodeSpacing) + 20);
        const svgWidth = 760;

        debtors.forEach((d, i) => {
            d.x = 24;
            d.y = topMargin + i * (nodeHeight + nodeSpacing);
        });

        creditors.forEach((c, i) => {
            c.x = svgWidth - nodeWidth - 24;
            c.y = topMargin + i * (nodeHeight + nodeSpacing);
        });

        return `
            <div class="flow-diagram-container" style="overflow-x: auto; padding: var(--space-2) 0;">
                <svg viewBox="0 0 760 ${svgHeight}" width="100%" height="${svgHeight}" class="debt-flow-svg" style="min-width: 540px; max-width: 100%; display: block;" role="img" aria-label="Debt Settlement Flowchart Diagram">
                    <defs>
                        <marker id="debt-arrow-marker" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                            <path d="M 0 1.5 L 8 5 L 0 8.5 z" fill="var(--brand-primary)" />
                        </marker>
                        <marker id="debt-arrow-marker-active" viewBox="0 0 10 10" refX="6" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path d="M 0 1.5 L 8 5 L 0 8.5 z" fill="var(--financial-credit)" />
                        </marker>
                    </defs>

                    <!-- Column Headers -->
                    <text x="24" y="32" font-size="11" font-weight="700" fill="var(--text-muted)" letter-spacing="0.05em" class="flow-col-header">DEBTORS (OUTGOING)</text>
                    <text x="380" y="32" text-anchor="middle" font-size="10" font-weight="700" fill="var(--text-muted)" letter-spacing="0.05em" class="flow-center-header">CLICK ARROW TO SETTLE</text>
                    <text x="736" y="32" text-anchor="end" font-size="11" font-weight="700" fill="var(--text-muted)" letter-spacing="0.05em" class="flow-col-header">CREDITORS (INCOMING)</text>

                    <!-- Connecting Flow Edges -->
                    <g class="flow-edges-layer">
                        ${transactions.map((tx, idx) => {
                            const dNode = debtors.find(d => (d.id === (tx.from_member_id || tx.from_id || tx.from_name)));
                            const cNode = creditors.find(c => (c.id === (tx.to_member_id || tx.to_id || tx.to_name)));
                            if (!dNode || !cNode) return '';

                            const startX = dNode.x + nodeWidth;
                            const startY = dNode.y + nodeHeight / 2;
                            const endX = cNode.x;
                            const endY = cNode.y + nodeHeight / 2;

                            const ctrl1X = startX + 70;
                            const ctrl1Y = startY;
                            const ctrl2X = endX - 70;
                            const ctrl2Y = endY;

                            const midX = (startX + endX) / 2;
                            const midY = (startY + endY) / 2;
                            const amtFormatted = Formatters.formatCurrency(tx.amount_cents, currency);

                            return `
                            <g class="flow-edge" data-tx-idx="${idx}" tabindex="0" role="button" aria-label="Transfer ${amtFormatted} from ${Formatters.escapeHtml(tx.from_name)} to ${Formatters.escapeHtml(tx.to_name)}. Click to settle payment.">
                                <path d="M ${startX} ${startY} C ${ctrl1X} ${ctrl1Y}, ${ctrl2X} ${ctrl2Y}, ${endX} ${endY}" fill="none" stroke="transparent" stroke-width="20" class="flow-hit-path" />
                                <path d="M ${startX} ${startY} C ${ctrl1X} ${ctrl1Y}, ${ctrl2X} ${ctrl2Y}, ${endX} ${endY}" fill="none" stroke="var(--brand-primary)" stroke-width="2" stroke-opacity="0.7" marker-end="url(#debt-arrow-marker)" class="flow-visible-path" />
                                <g transform="translate(${midX}, ${midY})">
                                    <rect x="-46" y="-12" width="92" height="24" rx="12" fill="var(--surface-primary)" stroke="var(--border-color)" stroke-width="1.5" class="flow-badge-bg" />
                                    <text x="0" y="4" text-anchor="middle" font-size="11" font-weight="700" font-family="var(--font-mono)" fill="var(--text-primary)" class="flow-badge-text">${amtFormatted}</text>
                                </g>
                            </g>
                            `;
                        }).join('')}
                    </g>

                    <!-- Debtor Nodes -->
                    <g class="flow-debtors-layer">
                        ${debtors.map((d) => {
                            const avatar = Formatters.getMemberAvatar(token, { id: d.id, name: d.name });
                            const avatarText = avatar ? avatar.emoji : Formatters.getInitials(d.name);
                            return `
                            <g transform="translate(${d.x}, ${d.y})" class="flow-node flow-debtor-node">
                                <rect width="${nodeWidth}" height="${nodeHeight}" rx="8" fill="var(--surface-primary)" stroke="var(--border-color)" stroke-width="1.5" />
                                <circle cx="28" cy="28" r="16" fill="var(--surface-secondary)" />
                                <text x="28" y="33" text-anchor="middle" font-size="${avatar ? '15' : '11'}" font-weight="700" fill="var(--financial-debt)">${avatarText}</text>
                                <text x="52" y="24" font-size="13" font-weight="600" fill="var(--text-primary)" class="flow-node-title">${Formatters.escapeHtml(d.name.length > 16 ? d.name.slice(0, 15) + '…' : d.name)}<title>${Formatters.escapeHtml(d.name)}</title></text>
                                <text x="52" y="42" font-size="11" font-weight="700" font-family="var(--font-mono)" fill="var(--financial-debt)">Pays ${Formatters.formatCurrency(d.totalOutgoingCents, currency)}</text>
                            </g>
                        `}).join('')}
                    </g>

                    <!-- Creditor Nodes -->
                    <g class="flow-creditors-layer">
                        ${creditors.map((c) => {
                            const avatar = Formatters.getMemberAvatar(token, { id: c.id, name: c.name });
                            const avatarText = avatar ? avatar.emoji : Formatters.getInitials(c.name);
                            return `
                            <g transform="translate(${c.x}, ${c.y})" class="flow-node flow-creditor-node">
                                <rect width="${nodeWidth}" height="${nodeHeight}" rx="8" fill="var(--surface-primary)" stroke="var(--border-color)" stroke-width="1.5" />
                                <circle cx="28" cy="28" r="16" fill="var(--surface-secondary)" />
                                <text x="28" y="33" text-anchor="middle" font-size="${avatar ? '15' : '11'}" font-weight="700" fill="var(--financial-credit)">${avatarText}</text>
                                <text x="52" y="24" font-size="13" font-weight="600" fill="var(--text-primary)" class="flow-node-title">${Formatters.escapeHtml(c.name.length > 16 ? c.name.slice(0, 15) + '…' : c.name)}<title>${Formatters.escapeHtml(c.name)}</title></text>
                                <text x="52" y="42" font-size="11" font-weight="700" font-family="var(--font-mono)" fill="var(--financial-credit)">Receives ${Formatters.formatCurrency(c.totalIncomingCents, currency)}</text>
                            </g>
                        `}).join('')}
                    </g>
                </svg>
            </div>
        `;
    }

    /**
     * Render the simplified debt settlement cards and recorded settlement actions.
     * @param {HTMLElement} container
     * @param {Object} options
     * @param {string} options.token Group invite token
     * @param {Object} options.plan Settlement plan data from /settlement-plan API
     * @param {Array} [options.settlements] Recorded settlements from /settlements API
     * @param {Array} [options.members] Workspace members roster
     * @param {string} [options.currency] Default: 'INR'
     * @param {string} [options.groupName] Group / Workspace name
     * @param {Function} [options.onUpdate] Callback after recording or deleting a settlement
     */
    static render(container, { token, plan = {}, settlements = [], members = [], currency = 'INR', groupName = '', onUpdate = null }) {
        if (!container) return;

        const allMembers = members.length > 0 ? members : (store.getState().members || []);
        const myClaimedMember = SettlementPlan.getMyClaimedMember(allMembers);
        const transactions = plan.transactions || [];
        const workspaceName = groupName || (typeof document !== 'undefined' ? document.querySelector('.exec-title')?.textContent?.trim() : '') || 'Smart Split Workspace';
        const currentView = SettlementPlan.getActiveView(token);

        const html = `
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title-text">
                        <span>Settlement Router</span>
                    </div>
                    <span class="badge ${transactions.length === 0 ? 'badge-credit' : 'badge-debt'} badge-mono">
                        ${transactions.length === 0 ? 'Zero Debt' : `${transactions.length} ${transactions.length === 1 ? 'Transfer' : 'Transfers'} Required`}
                    </span>
                </div>

                <div class="panel-body">
                    ${transactions.length === 0 ? `
                        <div class="empty-state" style="padding: var(--space-6) var(--space-2);">
                            <div class="empty-state-title">All accounts settled</div>
                            <div class="empty-state-text">No outstanding transfers required across workspace members.</div>
                        </div>
                    ` : `
                        <!-- View Toggle Toolbar -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3); gap: var(--space-2); flex-wrap: wrap;">
                            <div class="settlement-view-tabs" role="tablist" aria-label="Settlement presentation format">
                                <button type="button" class="settlement-view-tab-btn ${currentView === 'cards' ? 'active' : ''}" data-view="cards" role="tab" aria-selected="${currentView === 'cards' ? 'true' : 'false'}" id="tab-settlement-cards" tabindex="${currentView === 'cards' ? '0' : '-1'}">
                                    ${renderIcon('creditCard', { size: 13 })}
                                    <span>Card View</span>
                                </button>
                                <button type="button" class="settlement-view-tab-btn ${currentView === 'diagram' ? 'active' : ''}" data-view="diagram" role="tab" aria-selected="${currentView === 'diagram' ? 'true' : 'false'}" id="tab-settlement-diagram" tabindex="${currentView === 'diagram' ? '0' : '-1'}">
                                    ${renderIcon('gitCommit', { size: 13 })}
                                    <span>Flow Diagram View</span>
                                </button>
                            </div>
                            <div style="font-size: var(--font-size-2xs); color: var(--text-muted); font-family: var(--font-mono);">
                                Optimal Min-Cash-Flow Network
                            </div>
                        </div>

                        <!-- Card View Container -->
                        <div id="settlement-cards-view" class="settlement-view-content" style="display: ${currentView === 'cards' ? 'block' : 'none'};">
                            <div class="settlement-list" id="settlement-transactions-container">
                                ${transactions.map((tx, idx) => {
                                    const payerId = tx.from_member_id || tx.from_id;
                                    const payeeId = tx.to_member_id || tx.to_id;
                                    const isPayer = myClaimedMember && (Number(myClaimedMember.id) === Number(payerId) || myClaimedMember.name.toLowerCase() === tx.from_name.toLowerCase());
                                    const isPayee = myClaimedMember && (Number(myClaimedMember.id) === Number(payeeId) || myClaimedMember.name.toLowerCase() === tx.to_name.toLowerCase());

                                    let buttonText = 'Settle / Pay';
                                    let buttonIcon = 'checkCircle';
                                    let buttonClass = 'btn-success';

                                    if (isPayee) {
                                        buttonText = 'Mark Received';
                                        buttonIcon = 'checkCircle';
                                        buttonClass = 'btn-success';
                                    } else if (isPayer) {
                                        buttonText = 'I Paid';
                                        buttonIcon = 'send';
                                        buttonClass = 'btn-primary';
                                    } else {
                                        buttonText = 'Record Transfer';
                                        buttonIcon = 'check';
                                        buttonClass = 'btn-secondary';
                                    }

                                    return `
                                    <div class="settlement-card">
                                        <div class="settlement-flow">
                                            <div class="settlement-party">
                                                ${Formatters.renderMemberAvatar(tx.from_name, token, { size: 22 })}
                                                <span class="settlement-party-name">${Formatters.escapeHtml(tx.from_name)}</span>
                                            </div>
                                            <span class="settlement-arrow">──►</span>
                                            <div class="settlement-party">
                                                ${Formatters.renderMemberAvatar(tx.to_name, token, { size: 22 })}
                                                <span class="settlement-party-name">${Formatters.escapeHtml(tx.to_name)}</span>
                                            </div>
                                        </div>

                                        <div class="settlement-actions">
                                            <span class="settlement-amount">${Formatters.formatCurrency(tx.amount_cents, currency)}</span>
                                            <div class="settlement-btn-group">
                                                <button type="button" class="btn btn-secondary btn-sm btn-share-settlement" data-tx-idx="${idx}" title="Share via WhatsApp" aria-label="Share via WhatsApp" style="display: inline-flex; align-items: center; gap: 4px;">
                                                    ${renderIcon('whatsapp', { size: 13 })}
                                                    <span>Share via WhatsApp</span>
                                                </button>
                                                <button type="button" class="btn ${buttonClass} btn-sm mark-paid-btn" data-tx-idx="${idx}" style="display: inline-flex; align-items: center; gap: 4px;">
                                                    ${renderIcon(buttonIcon, { size: 13 })}
                                                    <span>${buttonText}</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>

                        <!-- Flow Diagram View Container -->
                        <div id="settlement-diagram-view" class="settlement-view-content" style="display: ${currentView === 'diagram' ? 'block' : 'none'};">
                            ${SettlementPlan.renderFlowDiagramSvg({ transactions, currency, token })}
                        </div>
                    `}

                    ${settlements && settlements.length > 0 ? `
                        <div style="margin-top: var(--space-4); border-top: 1px solid var(--border-color); padding-top: var(--space-3);">
                            <div style="font-size: var(--font-size-xs); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: var(--space-2);">
                                Payment Audit History (${settlements.length})
                            </div>
                            <div style="display: flex; flex-direction: column; gap: var(--space-2);">
                                ${settlements.map((s) => {
                                    const payerName = s.payer ? s.payer.name : (s.payer_name || 'Member');
                                    const payeeName = s.payee ? s.payee.name : (s.payee_name || 'Member');
                                    const payerId = s.payer ? s.payer.id : (s.payer_member_id || 0);
                                    const payeeId = s.payee ? s.payee.id : (s.payee_member_id || 0);
                                    const utr = SettlementPlan.extractUtr(s.notes, s.reference_id);
                                    const status = s.status || 'CONFIRMED';
                                    const method = s.payment_method || 'OTHER';

                                    const isPayer = myClaimedMember && (Number(myClaimedMember.id) === Number(payerId) || myClaimedMember.name.toLowerCase() === payerName.toLowerCase());
                                    const isPayee = myClaimedMember && (Number(myClaimedMember.id) === Number(payeeId) || myClaimedMember.name.toLowerCase() === payeeName.toLowerCase());

                                    // Attribution label
                                    let recordedText = 'Recorded before attribution tracking';
                                    if (s.recorded_by && s.recorded_by.name) {
                                        recordedText = `Recorded by ${s.recorded_by.name}`;
                                    }

                                    // Status Badge rendering
                                    let statusBadge = '';
                                    if (status === 'PENDING') {
                                        statusBadge = `<span class="badge badge-warning badge-mono" style="font-size: 0.65rem; padding: 1px 6px;">⏳ Pending Verification</span>`;
                                    } else if (status === 'CONFIRMED') {
                                        statusBadge = `<span class="badge badge-settled badge-mono" style="font-size: 0.65rem; padding: 1px 6px;">✓ Confirmed</span>`;
                                    } else if (status === 'DISPUTED') {
                                        statusBadge = `<span class="badge badge-debt badge-mono" style="font-size: 0.65rem; padding: 1px 6px;" title="${Formatters.escapeHtml(s.dispute_reason || 'Disputed')}">⚠️ Disputed</span>`;
                                    } else if (status === 'REVERSED') {
                                        statusBadge = `<span class="badge badge-secondary badge-mono" style="font-size: 0.65rem; padding: 1px 6px;" title="${Formatters.escapeHtml(s.reversal_reason || 'Reversed')}">↩ Reversed</span>`;
                                    }

                                    return `
                                    <div style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-2) var(--space-3); background: var(--surface-secondary); border-radius: var(--radius-xs); font-size: var(--font-size-xs); border: 1px solid var(--border-subtle); flex-wrap: wrap; gap: var(--space-2);">
                                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                            ${statusBadge}
                                            ${Formatters.renderMemberAvatar(payerName, token, { size: 18 })}
                                            <span><strong>${Formatters.escapeHtml(payerName)}</strong> ➔ <strong>${Formatters.escapeHtml(payeeName)}</strong></span>
                                            <span style="color: var(--text-muted); font-size: var(--font-size-2xs); font-family: var(--font-mono);">(${Formatters.formatDate(s.settled_date || s.settlement_date || s.created_at)})</span>
                                            <span style="color: var(--text-muted); font-size: var(--font-size-2xs);">• ${Formatters.escapeHtml(recordedText)}</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: var(--space-2); margin-left: auto;">
                                            ${utr ? `<span class="badge badge-settled badge-mono" style="font-size: 0.65rem; padding: 1px 6px;" title="Reference / UTR">UTR: ${Formatters.escapeHtml(utr)}</span>` : ''}
                                            <strong class="tnum" style="color: ${status === 'DISPUTED' || status === 'REVERSED' ? 'var(--text-muted)' : 'var(--financial-credit)'};">${Formatters.formatCurrency(s.amount_cents, currency)}</strong>
                                            
                                            <!-- Contextual Action Buttons for Pending Settlements -->
                                            ${status === 'PENDING' ? `
                                                ${isPayee ? `
                                                    <button type="button" class="btn btn-sm btn-success btn-confirm-receipt" data-settlement-id="${s.id}" style="font-size: 0.7rem; padding: 2px 8px; min-height: 24px;">
                                                        Confirm
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-ghost btn-dispute-payment" data-settlement-id="${s.id}" style="font-size: 0.7rem; padding: 2px 8px; min-height: 24px; color: var(--financial-debt);">
                                                        Dispute
                                                    </button>
                                                ` : `
                                                    <button type="button" class="btn btn-sm btn-ghost btn-dispute-payment" data-settlement-id="${s.id}" style="font-size: 0.7rem; padding: 2px 6px; min-height: 24px; color: var(--text-muted);" title="Cancel pending payment">
                                                        Cancel
                                                    </button>
                                                `}
                                            ` : ''}

                                            <!-- Reversal Action for Confirmed Settlements -->
                                            ${status === 'CONFIRMED' ? `
                                                <button type="button" class="btn-undo-settlement" data-settlement-id="${s.id}" title="Reverse Settlement" aria-label="Reverse settlement payment" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1rem; padding: 0 4px; line-height: 1; display: inline-flex; align-items: center;">
                                                    ${renderIcon('rotateCcw', { size: 12 })}
                                                </button>
                                            ` : ''}
                                        </div>
                                    </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;

        container.innerHTML = html;

        // Attach View Toggle Listeners with Persistent State & Keyboard Accessibility
        const viewTabsContainer = container.querySelector('.settlement-view-tabs');
        const viewButtons = container.querySelectorAll('.settlement-view-tab-btn');
        const cardsView = container.querySelector('#settlement-cards-view');
        const diagramView = container.querySelector('#settlement-diagram-view');

        const switchView = (targetView) => {
            if (targetView !== 'cards' && targetView !== 'diagram') return;
            SettlementPlan.setActiveView(token, targetView);
            viewButtons.forEach((b) => {
                const isActive = b.dataset.view === targetView;
                b.classList.toggle('active', isActive);
                b.setAttribute('aria-selected', isActive ? 'true' : 'false');
                b.setAttribute('tabindex', isActive ? '0' : '-1');
            });

            if (cardsView && diagramView) {
                cardsView.style.display = targetView === 'cards' ? 'block' : 'none';
                diagramView.style.display = targetView === 'diagram' ? 'block' : 'none';
            }
        };

        viewButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                switchView(btn.dataset.view);
            });
        });

        if (viewTabsContainer) {
            viewTabsContainer.addEventListener('keydown', (e) => {
                const btnList = Array.from(viewButtons);
                const currentIndex = btnList.findIndex((b) => b.classList.contains('active'));
                if (currentIndex === -1) return;

                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    const nextIndex = (currentIndex + 1) % btnList.length;
                    switchView(btnList[nextIndex].dataset.view);
                    btnList[nextIndex].focus();
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prevIndex = (currentIndex - 1 + btnList.length) % btnList.length;
                    switchView(btnList[prevIndex].dataset.view);
                    btnList[prevIndex].focus();
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    switchView(btnList[0].dataset.view);
                    btnList[0].focus();
                } else if (e.key === 'End') {
                    e.preventDefault();
                    switchView(btnList[btnList.length - 1].dataset.view);
                    btnList[btnList.length - 1].focus();
                }
            });
        }

        // Attach "Mark as Paid / Settle" click listeners on cards
        const markPaidButtons = container.querySelectorAll('.mark-paid-btn');
        markPaidButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const idx = Number(btn.dataset.txIdx);
                const tx = transactions[idx];
                if (!tx) return;

                SettlementPlan.openSettleModal({
                    token,
                    tx,
                    members: allMembers,
                    currency,
                    workspaceName,
                    onUpdate,
                });
            });
        });

        // Attach click & keyboard listeners to Interactive SVG Flow Diagram Paths
        const diagramPaths = container.querySelectorAll('.flow-path-group');
        diagramPaths.forEach((group) => {
            const handleTrigger = () => {
                const idx = Number(group.dataset.txIdx);
                const tx = transactions[idx];
                if (!tx) return;

                SettlementPlan.openSettleModal({
                    token,
                    tx,
                    members: allMembers,
                    currency,
                    workspaceName,
                    onUpdate,
                });
            };

            group.addEventListener('click', handleTrigger);
            group.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleTrigger();
                }
            });
        });

        // Attach "Share via WhatsApp / Nudge" click listeners on settlement cards
        const shareButtons = container.querySelectorAll('.btn-share-settlement');
        shareButtons.forEach((btn) => {
            btn.addEventListener('click', async () => {
                const idx = Number(btn.dataset.txIdx);
                const tx = transactions[idx];
                if (!tx) return;

                const payeeMember = allMembers.find(m => Number(m.id) === Number(tx.to_member_id || tx.to_id));
                const payeeUpi = (tx.to_upi_id || payeeMember?.upi_id || '').trim();
                const hasValidUpi = Formatters.isValidUpiVpa(payeeUpi);
                const amountDecimal = (tx.amount_cents / 100).toFixed(2);
                const upiUrl = hasValidUpi ? Formatters.buildUpiPaymentUrl({
                    payeeUpi,
                    payeeName: tx.to_name,
                    amountDecimal,
                    currency,
                    transactionNote: 'SmartSplit Settlement'
                }) : '';

                SettlementPlan.openNudgeModal({
                    groupName: workspaceName,
                    fromName: tx.from_name,
                    toName: tx.to_name,
                    amountFormatted: Formatters.formatCurrency(tx.amount_cents, currency),
                    payeeUpiId: hasValidUpi ? payeeUpi : '',
                    upiUrl,
                    workspaceUrl: window.location.href,
                });
            });
        });

        // Attach Confirm Receipt click listeners
        const confirmButtons = container.querySelectorAll('.btn-confirm-receipt');
        confirmButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const sId = Number(btn.dataset.settlementId);
                const s = settlements.find(item => Number(item.id) === sId);
                if (!s) return;

                SettlementPlan.openConfirmReceiptModal({
                    token,
                    settlement: s,
                    currency,
                    onUpdate,
                });
            });
        });

        // Attach Dispute Payment click listeners
        const disputeButtons = container.querySelectorAll('.btn-dispute-payment');
        disputeButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const sId = Number(btn.dataset.settlementId);
                const s = settlements.find(item => Number(item.id) === sId);
                if (!s) return;

                SettlementPlan.openDisputeModal({
                    token,
                    settlement: s,
                    currency,
                    onUpdate,
                });
            });
        });

        // Attach Settlement Reversal / Undo listeners
        const undoButtons = container.querySelectorAll('.btn-undo-settlement');
        undoButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const sId = Number(btn.dataset.settlementId);
                const s = settlements.find(item => Number(item.id) === sId);
                if (!s) return;

                SettlementPlan.openReverseModal({
                    token,
                    settlement: s,
                    currency,
                    onUpdate,
                });
            });
        });
    }
}
