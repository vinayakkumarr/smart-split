/**
 * Smart Split – Display Formatting & XSS Sanitization Utilities
 */

/**
 * Format integer paise/cents into localized currency string.
 * @param {number} cents
 * @param {string} currencyCode Default: 'INR'
 * @returns {string} E.g., "₹10.50", "₹1,500.00"
 */
export const CURRENCY_SYMBOLS = {
    INR: '₹',
    USD: '$',
    EUR: '€',
    GBP: '£',
    CAD: 'CA$',
    AUD: 'AU$',
    SGD: 'SG$',
    AED: 'AED ',
    JPY: '¥',
    CHF: 'CHF ',
    CNY: '¥',
    NZD: 'NZ$',
};

/**
 * Get currency symbol from currency code.
 * @param {string} currencyCode
 * @returns {string}
 */
export function getCurrencySymbol(currencyCode = 'INR') {
    const code = (currencyCode || 'INR').toUpperCase();
    return CURRENCY_SYMBOLS[code] || (code + ' ');
}

/**
 * Format integer paise/cents into localized currency string.
 * @param {number} cents
 * @param {string} currencyCode Default: 'INR'
 * @returns {string} E.g., "₹10.50", "₹1,500.00"
 */
export function formatCurrency(cents, currencyCode = 'INR') {
    const safeCents = typeof cents === 'number' && !isNaN(cents) ? cents : 0;
    const isNegative = safeCents < 0;
    const absDecimal = Math.abs(safeCents) / 100;

    const symbol = getCurrencySymbol(currencyCode);
    
    let numFormat = 'comma_decimal';
    try {
        if (typeof localStorage !== 'undefined') {
            numFormat = localStorage.getItem('smartsplit_pref_number_format') || 'comma_decimal';
        }
    } catch {}

    let formattedNumber;
    if (numFormat === 'dot_decimal') {
        formattedNumber = absDecimal.toLocaleString('de-DE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    } else {
        formattedNumber = absDecimal.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    return `${isNegative ? '-' : ''}${symbol}${formattedNumber}`;
}

/**
 * Format integer paise/cents into signed localized currency string.
 * @param {number} cents
 * @param {string} currencyCode Default: 'INR'
 * @returns {string} E.g., "+₹1,024.66", "-₹512.33", "₹0.00"
 */
export function formatCurrencySigned(cents, currencyCode = 'INR') {
    const safeCents = typeof cents === 'number' && !isNaN(cents) ? cents : 0;
    if (safeCents > 0) {
        return `+${formatCurrency(safeCents, currencyCode)}`;
    }
    return formatCurrency(safeCents, currencyCode);
}

/**
 * Format date string into user-preferred date format.
 * @param {string} dateStr YYYY-MM-DD or ISO timestamp
 * @returns {string} E.g., "21/09/2026", "09/21/2026", "2026-09-21"
 */
export function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return dateStr;

    let dateFormat = 'DD/MM/YYYY';
    try {
        if (typeof localStorage !== 'undefined') {
            dateFormat = localStorage.getItem('smartsplit_pref_date_format') || 'DD/MM/YYYY';
        }
    } catch {}

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();

    if (dateFormat === 'MM/DD/YYYY') {
        return `${month}/${day}/${year}`;
    }
    if (dateFormat === 'YYYY-MM-DD') {
        return `${year}-${month}-${day}`;
    }
    return `${day}/${month}/${year}`;
}

/**
 * Format timestamp into human-readable relative time.
 * @param {string} dateStr
 * @returns {string} E.g., "Just now", "5m ago", "2h ago", "Yesterday"
 */
export function formatRelativeTime(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return dateStr;
    const now = new Date();
    const diffSeconds = Math.max(0, Math.floor((now.getTime() - date.getTime()) / 1000));

    if (diffSeconds < 60) return 'Just now';
    if (diffSeconds < 3600) return `${Math.floor(diffSeconds / 60)}m ago`;
    if (diffSeconds < 86400) return `${Math.floor(diffSeconds / 3600)}h ago`;
    if (diffSeconds < 172800) return 'Yesterday';
    if (diffSeconds < 604800) return `${Math.floor(diffSeconds / 86400)}d ago`;

    return formatDate(dateStr);
}

/**
 * Extract 1-2 letter initials from a person's name.
 * @param {string} name
 * @returns {string} E.g., "Alice Smith" -> "AS", "Bob" -> "B"
 */
export function getInitials(name) {
    if (!name || typeof name !== 'string') return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length === 1) {
        return parts[0].substring(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/**
 * Escape unsafe HTML characters to prevent XSS attacks.
 * @param {string} unsafe
 * @returns {string}
 */
export function escapeHtml(unsafe) {
    if (unsafe === null || unsafe === undefined) return '';
    return String(unsafe)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Render text with clickable hashtag badges while safely escaping other HTML.
 * Replaces #word with <span class="badge badge-tag" data-tag="word">#word</span>.
 * @param {string} text
 * @returns {string}
 */
export function renderWithHashtags(text) {
    if (!text || typeof text !== 'string') return '';
    const safeText = escapeHtml(text);
    return safeText.replace(/(#([a-zA-Z0-9_\-]+))/g, (match, fullTag, tagWord) => {
        return `<span class="badge badge-tag" data-tag="${escapeHtml(tagWord)}">${escapeHtml(fullTag)}</span>`;
    });
}

/**
 * Curated list of 20 distinct, recognizable, and personality-rich emoji avatars.
 */
export const CURATED_MEMBER_EMOJIS = [
    '🦊', '🦉', '🦦', '🐙',
    '🌿', '🍄', '🪴', '🐚',
    '🥐', '🫐', '🍜', '🥨',
    '🧭', '🏕️', '🚲', '🎨',
    '🪩', '📚', '🧩', '🪐'
];

/**
 * 10 muted accent color palettes for member avatars.
 * Non-conflicting with financial green/red semantics.
 */
export const AVATAR_PALETTES = [
    { id: 'slate', name: 'Slate', bg: '#f1f5f9', border: '#cbd5e1', text: '#334155' },
    { id: 'sage', name: 'Sage', bg: '#eef4f0', border: '#c5dccb', text: '#2e563e' },
    { id: 'terracotta', name: 'Terracotta', bg: '#fbf0eb', border: '#f2cfc1', text: '#9c4127' },
    { id: 'ochre', name: 'Ochre', bg: '#fcf6ec', border: '#f3dcb5', text: '#8c6017' },
    { id: 'plum', name: 'Plum', bg: '#f8eff5', border: '#e6cbdc', text: '#6e385e' },
    { id: 'olive', name: 'Olive', bg: '#f3f6ee', border: '#d9e5be', text: '#50632b' },
    { id: 'mauve', name: 'Mauve', bg: '#f6f2f9', border: '#dfd3e8', text: '#5c436d' },
    { id: 'sand', name: 'Sand', bg: '#f7f4ef', border: '#e2d8ca', text: '#68543e' },
    { id: 'forest', name: 'Forest', bg: '#ecf3f1', border: '#c4dbd6', text: '#28534e' },
    { id: 'rust', name: 'Rust', bg: '#faeae3', border: '#f0c6b6', text: '#8e3518' },
];

/**
 * Retrieve a customized member avatar configuration from localStorage.
 * @param {string} token Group invite token
 * @param {number|string|Object} memberOrId
 * @returns {Object|null} { emoji, paletteId, bg, border, text } or null
 */
export function getMemberAvatar(token, memberOrId) {
    if (!token || !memberOrId) return null;
    try {
        if (typeof window === 'undefined' || !window.localStorage) return null;
        const key = `smartsplit_avatars_${token}`;
        const raw = window.localStorage.getItem(key);
        if (!raw) return null;
        const data = JSON.parse(raw);
        if (!data || typeof data !== 'object') return null;

        let memberIdKey = null;
        let memberNameKey = null;

        if (typeof memberOrId === 'object') {
            if (memberOrId.id !== undefined && memberOrId.id !== null) memberIdKey = String(memberOrId.id);
            if (memberOrId.member_id !== undefined && memberOrId.member_id !== null) memberIdKey = String(memberOrId.member_id);
            if (memberOrId.name) memberNameKey = String(memberOrId.name);
            if (memberOrId.member_name) memberNameKey = String(memberOrId.member_name);
        } else {
            memberIdKey = String(memberOrId);
            memberNameKey = String(memberOrId);
        }

        const record = (memberIdKey && data[memberIdKey]) || (memberNameKey && data[memberNameKey]) || null;
        if (!record || !record.emoji) return null;

        const palette = AVATAR_PALETTES.find(p => p.id === record.paletteId) || AVATAR_PALETTES[0];
        return {
            emoji: record.emoji,
            paletteId: palette.id,
            bg: palette.bg,
            border: palette.border,
            text: palette.text,
        };
    } catch (e) {
        return null;
    }
}

/**
 * Save custom avatar preference for a workspace member in localStorage.
 * @param {string} token Group invite token
 * @param {number|string} memberId
 * @param {Object} avatar { emoji: string, paletteId: string }
 */
export function setMemberAvatar(token, memberId, { emoji, paletteId }) {
    if (!token || !memberId) return;
    try {
        if (typeof window === 'undefined' || !window.localStorage) return;
        const key = `smartsplit_avatars_${token}`;
        const raw = window.localStorage.getItem(key);
        const data = raw ? JSON.parse(raw) : {};
        data[String(memberId)] = {
            emoji,
            paletteId: paletteId || 'slate',
        };
        window.localStorage.setItem(key, JSON.stringify(data));
    } catch (e) {
        console.error('Failed to set member avatar', e);
    }
}

/**
 * Clear customized avatar for a workspace member, resetting to default initials.
 * @param {string} token Group invite token
 * @param {number|string} memberId
 */
export function clearMemberAvatar(token, memberId) {
    if (!token || !memberId) return;
    try {
        if (typeof window === 'undefined' || !window.localStorage) return;
        const key = `smartsplit_avatars_${token}`;
        const raw = window.localStorage.getItem(key);
        if (!raw) return;
        const data = JSON.parse(raw);
        delete data[String(memberId)];
        window.localStorage.setItem(key, JSON.stringify(data));
    } catch (e) {
        console.error('Failed to clear member avatar', e);
    }
}

/**
 * Get deterministic palette for a member name.
 * @param {string} name
 * @returns {Object} { id, name, bg, border, text }
 */
export function getDeterministicPalette(name) {
    if (!name || typeof name !== 'string') return AVATAR_PALETTES[0];
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = (hash << 5) - hash + name.charCodeAt(i);
        hash |= 0;
    }
    const idx = Math.abs(hash) % AVATAR_PALETTES.length;
    return AVATAR_PALETTES[idx];
}

/**
 * Centralized rendering helper for workspace member avatars (monogram initials or custom avatar).
 * @param {Object|string} member Member object or member name string
 * @param {string} token Workspace token
 * @param {Object} [options]
 * @param {number} [options.size] Dimension in px (e.g. 18, 22, 28)
 * @param {string} [options.extraClass] Additional CSS class
 * @param {string} [options.style] Additional inline styles
 * @returns {string} HTML string
 */
export function renderMemberAvatar(member, token, options = {}) {
    if (!member) return '';
    const memberName = typeof member === 'string' ? member : (member.name || member.member_name || '?');
    const avatar = getMemberAvatar(token, member);

    const sizePx = options.size || 20;
    const size = `${sizePx}px`;
    const fontPx = Math.max(9, Math.floor(sizePx * 0.46));
    const sizeStyle = `width: ${size}; height: ${size}; min-width: ${size}; font-size: ${fontPx}px;`;
    const extraClass = options.extraClass ? ` ${options.extraClass}` : '';
    const customStyle = options.style ? ` ${options.style}` : '';

    if (avatar && avatar.emoji) {
        return `<span class="member-custom-avatar custom-emoji-avatar${extraClass}" style="background-color: ${avatar.bg}; border: 1px solid ${avatar.border}; color: ${avatar.text}; ${sizeStyle}${customStyle}" title="${escapeHtml(memberName)}">${escapeHtml(avatar.emoji)}</span>`;
    }

    const palette = getDeterministicPalette(memberName);
    const initials = getInitials(memberName);
    return `<span class="member-custom-avatar default-initials${extraClass}" style="background-color: ${palette.bg}; border: 1px solid ${palette.border}; color: ${palette.text}; font-weight: 700; ${sizeStyle}${customStyle}" title="${escapeHtml(memberName)}">${escapeHtml(initials)}</span>`;
}

/**
 * Conservative syntactic validation for UPI Virtual Payment Address (VPA).
 * Matches standard format: handle@bank (e.g., rahul@okaxis, 9876543210@paytm, user.name@okhdfcbank).
 * Constrained to maximum 80 characters to strictly align with members.upi_id schema storage capacity.
 */
export const UPI_VPA_REGEX = /^[a-zA-Z0-9._-]{2,77}@[a-zA-Z]{2,64}$/;

/**
 * Validate whether a string is syntactically a valid UPI VPA.
 * Note: Performs syntactic format and length checking (5 to 80 chars); does not claim bank-side existence verification.
 * @param {string} vpa
 * @returns {boolean}
 */
export function isValidUpiVpa(vpa) {
    if (!vpa || typeof vpa !== 'string') return false;
    const trimmed = vpa.trim();
    if (trimmed.length < 5 || trimmed.length > 80) return false;
    return UPI_VPA_REGEX.test(trimmed);
}

/**
 * Construct an NPCI-compliant canonical UPI payment URI.
 * Strictly validates that the amount is a valid, positive, finite monetary quantity.
 * @param {Object} params
 * @param {string} params.payeeUpi Validated Payee UPI VPA
 * @param {string} params.payeeName Payee display name
 * @param {string|number} params.amountDecimal Amount in decimal format (e.g. "1250.00" or 12.5)
 * @param {string} [params.currency='INR'] ISO currency code
 * @param {string} [params.transactionRef=null] Settlement transaction reference for reconciliation
 * @param {string} [params.transactionNote='SmartSplit Settlement'] Payment note
 * @returns {string|null} Full upi://pay URI or null if parameters are invalid
 */
export function buildUpiPaymentUrl({
    payeeUpi,
    payeeName,
    amountDecimal,
    currency = 'INR',
    transactionRef = null,
    transactionNote = 'SmartSplit Settlement'
}) {
    if (!isValidUpiVpa(payeeUpi)) {
        return null;
    }

    // Strict finite positive numeric amount validation (UPI-02)
    let numVal;
    if (typeof amountDecimal === 'number') {
        if (!Number.isFinite(amountDecimal) || amountDecimal <= 0) {
            return null;
        }
        numVal = amountDecimal;
    } else if (typeof amountDecimal === 'string') {
        const trimmedAmt = amountDecimal.trim();
        // Reject empty, non-numeric, partially numeric (e.g. "1abc"), NaN, Infinity, negative
        if (!/^\d+(\.\d+)?$/.test(trimmedAmt)) {
            return null;
        }
        numVal = Number(trimmedAmt);
        if (!Number.isFinite(numVal) || numVal <= 0) {
            return null;
        }
    } else {
        return null;
    }

    const cleanAmt = numVal.toFixed(2);
    const cleanUpi = encodeURIComponent(payeeUpi.trim());
    const cleanName = encodeURIComponent((payeeName || 'Member').trim());
    const cleanCu = encodeURIComponent((currency || 'INR').trim());
    const cleanNote = encodeURIComponent((transactionNote || 'SmartSplit Settlement').trim());

    let uri = `upi://pay?pa=${cleanUpi}&pn=${cleanName}&am=${cleanAmt}&cu=${cleanCu}&tn=${cleanNote}`;
    if (transactionRef) {
        const cleanRef = encodeURIComponent(String(transactionRef).trim().replace(/[^a-zA-Z0-9_-]/g, ''));
        if (cleanRef) {
            uri += `&tr=${cleanRef}`;
        }
    }
    return uri;
}



