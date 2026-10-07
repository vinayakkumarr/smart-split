/**
 * Smart Split V2 – Member Management & Roster Panel
 * Principles: Compact Roster, Progressive Identity Linking, Clean Avatars
 */

import { api } from '../api.js';
import { store } from '../state.js';
import { Modal } from './Modal.js';
import { Toast } from './Toast.js';
import {
    escapeHtml,
    getInitials,
    CURATED_MEMBER_EMOJIS,
    AVATAR_PALETTES,
    getMemberAvatar,
    setMemberAvatar,
    clearMemberAvatar,
    renderMemberAvatar,
} from '../utils/formatters.js';
import { renderIcon } from '../utils/icons.js';

export class MemberList {
    /**
     * Render the member roster panel with progressive identity badges and quick claim/unlink actions.
     * @param {HTMLElement} container
     * @param {Array<Object>} members
     * @param {string} token Group invite token
     * @param {Function} [onMemberAdded] Callback after member is added, claimed, unlinked, or avatar is updated
     */
    static render(container, members = [], token = '', onMemberAdded = null) {
        // Progressive Auth State Context
        const state = (typeof store !== 'undefined' && store.getState) ? store.getState() : {};
        const currentUser = state.currentUser;
        const isAuthenticated = Boolean(state.isAuthenticated);
        const currentGroup = state.currentGroup;
        const ownerUserId = (currentGroup?.owner_user_id !== null && currentGroup?.owner_user_id !== undefined)
            ? Number(currentGroup.owner_user_id)
            : null;

        // Creator Member ID: from currentGroup.creator_member_id or lowest member ID in roster
        const creatorMemberId = (currentGroup?.creator_member_id !== null && currentGroup?.creator_member_id !== undefined)
            ? Number(currentGroup.creator_member_id)
            : (members.length > 0 ? Number(members[0].id) : null);

        const myClaimedMember = (isAuthenticated && currentUser)
            ? members.find(m => m.user_id !== null && m.user_id !== undefined && Number(m.user_id) === Number(currentUser.id))
            : null;
        const userHasClaimedAny = Boolean(myClaimedMember);

        const memberListHtml = members.map(m => {
            const isCurrentUser = Boolean(isAuthenticated && currentUser && m.user_id !== null && m.user_id !== undefined && Number(m.user_id) === Number(currentUser.id));
            const isLinked = m.user_id !== null && m.user_id !== undefined;
            
            // Ownership / Creator Determination
            const isOwner = Boolean(ownerUserId !== null && isLinked && Number(m.user_id) === ownerUserId);
            const isCreator = isOwner || (ownerUserId === null && creatorMemberId !== null && Number(m.id) === creatorMemberId);

            let badgesHtml = '';
            if (isOwner) {
                badgesHtml += `<span class="badge badge-mono member-badge-owner" title="Workspace Owner">${renderIcon('crown', { size: 10 })} Owner</span>`;
            } else if (isCreator) {
                badgesHtml += `<span class="badge badge-mono member-badge-creator" title="Workspace Organizer">${renderIcon('crown', { size: 10 })} Organizer</span>`;
            }

            if (isCurrentUser) {
                badgesHtml += `
                    <span class="badge badge-primary badge-mono member-badge-you" title="Your Linked Account Profile">${renderIcon('user', { size: 10 })} You</span>
                    <button type="button" class="btn-unlink-member" data-member-id="${m.id}" data-member-name="${escapeHtml(m.name)}" title="Unlink member slot from your account">Unlink</button>
                `;
            } else if (isLinked) {
                badgesHtml += `<span class="badge badge-settled badge-mono member-badge-verified" title="Verified Linked User">${renderIcon('shieldCheck', { size: 10 })} Linked</span>`;
            } else {
                if (isAuthenticated && !userHasClaimedAny) {
                    badgesHtml += `<button type="button" class="btn btn-outline btn-xs btn-quick-claim" data-member-id="${m.id}" data-member-name="${escapeHtml(m.name)}" title="Claim this profile as yourself">${renderIcon('sparkles', { size: 10 })} Claim</button>`;
                }
            }

            return `
                <div class="member-chip member-chip-customizable ${isCurrentUser ? 'member-chip-current-user' : ''}" data-member-id="${m.id}" data-member-name="${escapeHtml(m.name)}" title="Click to customize member avatar">
                    ${renderMemberAvatar(m, token, { size: 18, extraClass: 'member-chip-avatar' })}
                    <span class="member-name-text">${escapeHtml(m.name)}</span>
                    ${badgesHtml}
                    <span class="member-chip-edit-icon" title="Customize avatar">${renderIcon('edit2', { size: 11 })}</span>
                </div>
            `;
        }).join('');

        container.innerHTML = `
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title-text">
                        <span>Member Roster</span>
                        <span class="badge badge-settled badge-mono">${members.length}</span>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="btn-add-member">
                        ${renderIcon('userPlus', { size: 13 })}
                        <span>Add Member</span>
                    </button>
                </div>

                <div class="panel-body">
                    <div class="member-chip-list">
                        ${memberListHtml || '<span style="color: var(--text-muted); font-size: var(--font-size-xs);">No members in this workspace yet.</span>'}
                    </div>
                </div>
            </div>
        `;

        const addMemberBtn = container.querySelector('#btn-add-member');
        if (addMemberBtn) {
            addMemberBtn.addEventListener('click', () => {
                MemberList.openAddMemberModal(token, onMemberAdded);
            });
        }

        // Quick Claim 1-Click Action Handler
        const quickClaimBtns = container.querySelectorAll('.btn-quick-claim');
        quickClaimBtns.forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const memberId = Number(btn.dataset.memberId);
                const memberName = btn.dataset.memberName || 'profile';
                try {
                    btn.disabled = true;
                    btn.textContent = 'Claiming...';
                    await api.claimMember(token, memberId);
                    Toast.success(`Linked to ${memberName} successfully!`);
                    if (typeof onMemberAdded === 'function') {
                        Promise.resolve().then(() => onMemberAdded()).catch(() => {});
                    } else if (typeof window !== 'undefined' && window.SmartSplit?.refreshGroupData) {
                        Promise.resolve().then(() => window.SmartSplit.refreshGroupData(token)).catch(() => {});
                    }
                } catch (err) {
                    btn.disabled = false;
                    btn.innerHTML = `${renderIcon('sparkles', { size: 10 })} Claim<!-- ⚡ Claim -->`;
                    Toast.error(err.message || 'Failed to claim member profile.');
                }
            });
        });

        // Unlink Member Action Handler
        const unlinkBtns = container.querySelectorAll('.btn-unlink-member');
        unlinkBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const memberId = Number(btn.dataset.memberId);
                const memberName = btn.dataset.memberName || 'member';
                Modal.open({
                    title: `Unlink from ${escapeHtml(memberName)}?`,
                    size: 'sm',
                    content: `
                        <p style="font-size: var(--font-size-sm); color: var(--text-secondary); margin-bottom: var(--space-2);">
                            Are you sure you want to unlink your account from <strong>${escapeHtml(memberName)}</strong>?
                        </p>
                        <p style="font-size: var(--font-size-xs); color: var(--text-muted);">
                            Your account will no longer be attached to this member slot, but past expenses and balances remain untouched.
                        </p>
                    `,
                    confirmText: 'Unlink Profile',
                    confirmClass: 'btn-danger',
                    onConfirm: async () => {
                        try {
                            await api.unlinkMember(token, memberId);
                            Toast.info(`Unlinked from ${memberName}.`);
                            if (typeof onMemberAdded === 'function') {
                                await onMemberAdded();
                            } else if (typeof window !== 'undefined' && window.SmartSplit?.refreshGroupData) {
                                await window.SmartSplit.refreshGroupData(token);
                            }
                        } catch (err) {
                            Toast.error(err.message || 'Failed to unlink member.');
                        }
                    }
                });
            });
        });

        // Avatar customization handler on chip click
        const chips = container.querySelectorAll('.member-chip-customizable');
        chips.forEach(chip => {
            chip.addEventListener('click', (e) => {
                // If a button inside was clicked, don't open avatar modal
                if (e.target.closest('button')) return;

                const memberId = Number(chip.dataset.memberId);
                const member = members.find(m => Number(m.id) === memberId) || { id: memberId, name: chip.dataset.memberName };
                MemberList.openAvatarModal(token, member, onMemberAdded);
            });
        });
    }

    /**
     * Open modal dialog to customize a member's avatar emoji and accent color palette.
     * @param {string} token Group invite token
     * @param {Object} member Member object { id, name }
     * @param {Function} [onSaved] Callback on successful save
     */
    static openAvatarModal(token, member, onSaved = null) {
        if (!member) return;

        const state = (typeof store !== 'undefined' && store.getState) ? store.getState() : {};
        const currentUser = state.currentUser;
        const isAuthenticated = Boolean(state.isAuthenticated);
        const isCurrentUser = Boolean(isAuthenticated && currentUser && member.user_id !== null && member.user_id !== undefined && Number(member.user_id) === Number(currentUser.id));
        const isLinkedOther = Boolean(member.user_id !== null && member.user_id !== undefined && !isCurrentUser);

        const existingAvatar = getMemberAvatar(token, member);
        let currentEmoji = existingAvatar?.emoji || null;
        let currentPaletteId = existingAvatar?.paletteId || 'slate';

        const getPaletteObj = (pId) => AVATAR_PALETTES.find(p => p.id === pId) || AVATAR_PALETTES[0];

        const renderPreviewHtml = (emoji, paletteId) => {
            const palette = getPaletteObj(paletteId);
            if (emoji) {
                return `
                    <div class="avatar-preview-display" style="background-color: ${palette.bg}; border: 2px solid ${palette.border}; color: ${palette.text};">
                        ${escapeHtml(emoji)}
                    </div>
                `;
            }
            return `
                <div class="avatar-preview-display" style="background-color: ${palette.bg}; border: 2px solid ${palette.border}; color: ${palette.text}; font-family: var(--font-mono); font-weight: 700; font-size: 20px;">
                    ${escapeHtml(getInitials(member.name))}
                </div>
            `;
        };

        const modalContent = `
            <div style="margin-bottom: var(--space-3);">
                <!-- Live Avatar Preview Box -->
                <div class="avatar-preview-box">
                    <div id="avatar-live-preview">
                        ${renderPreviewHtml(currentEmoji, currentPaletteId)}
                    </div>
                    <div style="font-weight: 700; font-size: var(--font-size-sm); color: var(--text-primary); margin-top: 2px;">
                        ${escapeHtml(member.name)}
                    </div>
                    <div style="font-size: var(--font-size-2xs); color: var(--text-muted); margin-top: 2px;">
                        Live Avatar Preview
                    </div>
                </div>

                <!-- Curated Emoji Grid -->
                <div class="avatar-section-title">
                    <span>Curated Emoji Personality</span>
                    <span style="font-size: var(--font-size-2xs); color: var(--text-muted);">${CURATED_MEMBER_EMOJIS.length} Distinct Choices</span>
                </div>
                <div class="avatar-emoji-grid" id="avatar-emoji-grid">
                    ${CURATED_MEMBER_EMOJIS.map(e => `
                        <button type="button" class="avatar-emoji-btn ${currentEmoji === e ? 'is-active' : ''}" data-emoji="${e}" title="${e}">
                            ${e}
                        </button>
                    `).join('')}
                </div>

                <!-- Member avatar color swatches -->
                <div class="avatar-section-title">
                    <span>Accent Tone Palette</span>
                    <span style="font-size: var(--font-size-2xs); color: var(--text-muted);">Muted Swiss Tones</span>
                </div>
                <div class="avatar-palettes-grid" id="avatar-palettes-grid">
                    ${AVATAR_PALETTES.map(p => `
                        <button type="button" class="avatar-palette-swatch ${currentPaletteId === p.id ? 'is-active' : ''}" data-palette-id="${p.id}" title="${p.name}">
                            <div class="avatar-palette-color-preview" style="background-color: ${p.bg}; border-color: ${p.border};"></div>
                            <span class="avatar-palette-name">${p.name}</span>
                        </button>
                    `).join('')}
                </div>

                <!-- Rename Member Section -->
                <div style="margin-bottom: var(--space-3); padding-bottom: var(--space-3); border-bottom: 1px solid var(--border-color);">
                    <label for="input-edit-member-name" class="avatar-section-title" style="display: block; margin-bottom: 6px;">
                        Member Display Name
                    </label>
                    <div style="display: flex; gap: var(--space-2); align-items: center;">
                        <input
                            type="text"
                            id="input-edit-member-name"
                            class="form-input"
                            value="${escapeHtml(member.name)}"
                            placeholder="Member name"
                            style="flex: 1; font-size: var(--font-size-xs); height: 32px; padding: 4px 10px;"
                        >
                        <button type="button" class="btn btn-secondary btn-sm" id="btn-save-member-name" style="font-size: var(--font-size-xs); height: 32px; white-space: nowrap;">
                            Rename
                        </button>
                    </div>
                </div>

                <!-- Member UPI VPA Section -->
                ${isCurrentUser ? `
                    <div style="margin-bottom: var(--space-3); padding: var(--space-2) var(--space-3); background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-color);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                            <span style="font-weight: 700; font-size: var(--font-size-xs); color: var(--text-primary); display: inline-flex; align-items: center; gap: 4px;">
                                ${renderIcon('shieldCheck', { size: 12, style: 'color: var(--brand-primary);' })}
                                <span>Personal Account UPI</span>
                            </span>
                            <a href="#/settings" class="btn btn-outline btn-xs" id="btn-member-manage-profile-upi" style="font-size: 0.7rem; padding: 2px 6px;">Manage in Settings</a>
                        </div>
                        <div style="font-family: var(--font-mono); font-size: var(--font-size-xs); color: var(--text-primary); font-weight: 600;">
                            ${escapeHtml(member.upi_id || currentUser?.upi_id || 'Not configured in Settings')}
                        </div>
                        <div style="font-size: var(--font-size-2xs); color: var(--text-muted); margin-top: 2px;">
                            Active across all your linked workspace memberships.
                        </div>
                    </div>
                ` : isLinkedOther ? `
                    <div style="margin-bottom: var(--space-3); padding: var(--space-2) var(--space-3); background: var(--surface-secondary); border-radius: var(--radius-xs); border: 1px solid var(--border-color);">
                        <div style="font-weight: 700; font-size: var(--font-size-xs); color: var(--text-primary); margin-bottom: 2px; display: inline-flex; align-items: center; gap: 4px;">
                            ${renderIcon('shieldCheck', { size: 12, style: 'color: var(--brand-primary);' })}
                            <span>Linked User Account</span>
                        </div>
                        <div style="font-size: var(--font-size-2xs); color: var(--text-muted);">
                            Payment identity is managed directly by this member's user account.
                        </div>
                    </div>
                ` : `
                    <div style="margin-bottom: var(--space-3); padding-bottom: var(--space-3); border-bottom: 1px solid var(--border-color);">
                        <label for="input-edit-member-upi" class="avatar-section-title" style="display: block; margin-bottom: 4px;">
                            Guest Member UPI ID / VPA (Optional)
                        </label>
                        <div style="font-size: var(--font-size-2xs); color: var(--text-muted); margin-bottom: 6px;">
                            Used to generate instant QR codes and 1-tap payment deep-links for debt settlements.
                        </div>
                        <div style="display: flex; gap: var(--space-2); align-items: center;">
                            <input
                                type="text"
                                id="input-edit-member-upi"
                                class="form-input"
                                value="${escapeHtml(member.upi_id || '')}"
                                placeholder="e.g. name@okaxis, mobile@paytm"
                                style="flex: 1; font-size: var(--font-size-xs); height: 32px; padding: 4px 10px; font-family: var(--font-mono);"
                            >
                            <button type="button" class="btn btn-secondary btn-sm" id="btn-save-member-upi" style="font-size: var(--font-size-xs); height: 32px; white-space: nowrap;">
                                Save UPI
                            </button>
                        </div>
                    </div>
                `}

                <!-- Reset and Remove Member Options -->
                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: var(--space-2); border-top: 1px solid var(--border-color); flex-wrap: wrap; gap: var(--space-2);">
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-reset-avatar" style="color: var(--text-muted); font-size: var(--font-size-xs); display: inline-flex; align-items: center; gap: 4px;">
                        ${renderIcon('refreshCw', { size: 12 })} <span>Reset to Initials</span>
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-delete-member" style="color: var(--financial-debt); font-size: var(--font-size-xs); display: inline-flex; align-items: center; gap: 4px;">
                        ${renderIcon('trash2', { size: 12 })} <span>Remove Member</span>
                    </button>
                </div>
            </div>
        `;

        Modal.open({
            title: `Member Settings — ${escapeHtml(member.name)}`,
            content: modalContent,
            size: 'md',
            confirmText: 'Save Avatar',
            confirmClass: 'btn-primary',
            onMount: (overlay) => {
                const previewEl = overlay.querySelector('#avatar-live-preview');
                const emojiGrid = overlay.querySelector('#avatar-emoji-grid');
                const palettesGrid = overlay.querySelector('#avatar-palettes-grid');
                const resetBtn = overlay.querySelector('#btn-reset-avatar');
                const deleteBtn = overlay.querySelector('#btn-delete-member');
                const saveNameBtn = overlay.querySelector('#btn-save-member-name');
                const nameInput = overlay.querySelector('#input-edit-member-name');
                const saveUpiBtn = overlay.querySelector('#btn-save-member-upi');
                const upiInput = overlay.querySelector('#input-edit-member-upi');

                const updatePreview = () => {
                    if (previewEl) {
                        previewEl.innerHTML = renderPreviewHtml(currentEmoji, currentPaletteId);
                    }
                };

                // Rename member handler
                if (saveNameBtn && nameInput) {
                    saveNameBtn.addEventListener('click', async () => {
                        const newName = nameInput.value.trim();
                        if (!newName) {
                            Toast.error('Member name cannot be empty.');
                            return;
                        }
                        if (newName === member.name) {
                            Toast.info('Name is unchanged.');
                            return;
                        }
                        try {
                            saveNameBtn.disabled = true;
                            saveNameBtn.textContent = 'Saving...';
                            await api.updateMember(token, member.id, { name: newName });
                            Toast.success(`Member renamed to "${newName}".`);
                            member.name = newName;
                            saveNameBtn.disabled = false;
                            saveNameBtn.textContent = 'Rename';
                            updatePreview();
                            if (typeof onSaved === 'function') {
                                await onSaved();
                            }
                        } catch (err) {
                            saveNameBtn.disabled = false;
                            saveNameBtn.textContent = 'Rename';
                            Toast.error(err.message || 'Failed to rename member.');
                        }
                    });
                }

                // Save member UPI ID handler
                if (saveUpiBtn && upiInput) {
                    saveUpiBtn.addEventListener('click', async () => {
                        const newUpi = upiInput.value.trim();
                        try {
                            saveUpiBtn.disabled = true;
                            saveUpiBtn.textContent = 'Saving...';
                            const res = await api.updateMember(token, member.id, { upi_id: newUpi || null });
                            member.upi_id = res?.data?.member?.upi_id ?? (newUpi || null);
                            Toast.success(newUpi ? 'UPI ID saved successfully.' : 'UPI ID removed.');
                            saveUpiBtn.disabled = false;
                            saveUpiBtn.textContent = 'Save UPI';
                            if (typeof onSaved === 'function') {
                                await onSaved();
                            }
                        } catch (err) {
                            saveUpiBtn.disabled = false;
                            saveUpiBtn.textContent = 'Save UPI';
                            Toast.error(err.message || 'Failed to save UPI ID.');
                        }
                    });
                }

                // Remove member handler
                if (deleteBtn) {
                    deleteBtn.addEventListener('click', () => {
                        Modal.open({
                            title: `Remove ${escapeHtml(member.name)}?`,
                            size: 'sm',
                            content: `
                                <p style="font-size: var(--font-size-sm); color: var(--text-secondary); margin-bottom: var(--space-2); line-height: 1.5;">
                                    Are you sure you want to remove <strong>${escapeHtml(member.name)}</strong> from this workspace?
                                </p>
                                <p style="font-size: var(--font-size-xs); color: var(--text-muted); line-height: 1.4;">
                                    Members with active non-zero balances cannot be removed until all outstanding debts are settled.
                                </p>
                            `,
                            confirmText: 'Remove Member',
                            confirmClass: 'btn-danger',
                            onConfirm: async () => {
                                try {
                                    await api.deleteMember(token, member.id);
                                    Toast.success(`Member "${member.name}" removed successfully.`);
                                    Modal.close();
                                    if (typeof onSaved === 'function') {
                                        await onSaved();
                                    }
                                } catch (err) {
                                    Toast.error(err.message || 'Cannot remove member.');
                                }
                            }
                        });
                    });
                }

                if (emojiGrid) {
                    emojiGrid.addEventListener('click', (e) => {
                        const btn = e.target.closest('.avatar-emoji-btn');
                        if (!btn) return;
                        const emoji = btn.dataset.emoji;
                        currentEmoji = emoji;

                        emojiGrid.querySelectorAll('.avatar-emoji-btn').forEach(b => {
                            b.classList.toggle('is-active', b === btn);
                        });
                        updatePreview();
                    });
                }

                if (palettesGrid) {
                    palettesGrid.addEventListener('click', (e) => {
                        const btn = e.target.closest('.avatar-palette-swatch');
                        if (!btn) return;
                        const paletteId = btn.dataset.paletteId;
                        currentPaletteId = paletteId;

                        palettesGrid.querySelectorAll('.avatar-palette-swatch').forEach(b => {
                            b.classList.toggle('is-active', b === btn);
                        });
                        updatePreview();
                    });
                }

                if (resetBtn) {
                    resetBtn.addEventListener('click', () => {
                        currentEmoji = null;
                        currentPaletteId = 'slate';
                        emojiGrid?.querySelectorAll('.avatar-emoji-btn').forEach(b => b.classList.remove('is-active'));
                        palettesGrid?.querySelectorAll('.avatar-palette-swatch').forEach(b => {
                            b.classList.toggle('is-active', b.dataset.paletteId === 'slate');
                        });
                        updatePreview();
                    });
                }
            },
            onConfirm: async () => {
                if (currentEmoji) {
                    setMemberAvatar(token, member.id || member.name, {
                        emoji: currentEmoji,
                        paletteId: currentPaletteId,
                    });
                } else {
                    clearMemberAvatar(token, member.id || member.name);
                }

                Toast.success(`Avatar updated for ${escapeHtml(member.name)}.`);
                if (typeof onSaved === 'function') {
                    Promise.resolve().then(() => onSaved()).catch(() => {});
                }
            }
        });
    }

    /**
     * Open modal dialog to add a new member.
     * @param {string} token
     * @param {Function} [onMemberAdded]
     */
    static openAddMemberModal(token, onMemberAdded = null) {
        let selectedEmoji = null;
        let selectedPaletteId = 'slate';

        const content = `
            <form id="modal-add-member-form">
                <div class="form-group" style="margin-bottom: var(--space-3);">
                    <label class="form-label" for="new-member-name-input">Full Name / Member Name *</label>
                    <input 
                        type="text" 
                        id="new-member-name-input" 
                        class="form-input" 
                        placeholder="e.g. Bob, Sneha, Vikram" 
                        required 
                        maxlength="60"
                        autofocus
                    >
                </div>

                <div class="avatar-section-title">
                    <span>Choose Avatar Emoji (Optional)</span>
                </div>
                <div class="avatar-emoji-grid" id="add-member-emoji-grid" style="grid-template-columns: repeat(10, 1fr); gap: 4px; margin-bottom: var(--space-3);">
                    ${CURATED_MEMBER_EMOJIS.map(e => `
                        <button type="button" class="avatar-emoji-btn" data-emoji="${e}" style="font-size: 16px; min-height: 28px;">
                            ${e}
                        </button>
                    `).join('')}
                </div>
            </form>
        `;

        Modal.open({
            title: 'Add Member to Workspace',
            content,
            size: 'sm',
            confirmText: 'Add Member',
            confirmClass: 'btn-primary',
            onMount: (overlay) => {
                const input = overlay.querySelector('#new-member-name-input');
                if (input) setTimeout(() => input.focus(), 50);

                const emojiGrid = overlay.querySelector('#add-member-emoji-grid');
                if (emojiGrid) {
                    emojiGrid.addEventListener('click', (e) => {
                        const btn = e.target.closest('.avatar-emoji-btn');
                        if (!btn) return;
                        if (btn.classList.contains('is-active')) {
                            btn.classList.remove('is-active');
                            selectedEmoji = null;
                        } else {
                            emojiGrid.querySelectorAll('.avatar-emoji-btn').forEach(b => b.classList.remove('is-active'));
                            btn.classList.add('is-active');
                            selectedEmoji = btn.dataset.emoji;
                        }
                    });
                }

                const form = overlay.querySelector('#modal-add-member-form');
                if (form) {
                    form.addEventListener('submit', (e) => {
                        e.preventDefault();
                        overlay.querySelector('.modal-btn-confirm')?.click();
                    });
                }
            },
            onConfirm: async () => {
                const overlay = document.getElementById('modal-overlay');
                const input = overlay?.querySelector('#new-member-name-input');
                const name = input?.value.trim();

                if (!name) {
                    Toast.error('Please enter a valid member name.');
                    throw new Error('Name empty');
                }

                try {
                    const res = await api.addMember(token, name);
                    const newMember = res.data.member;

                    if (selectedEmoji) {
                        setMemberAvatar(token, newMember.id, {
                            emoji: selectedEmoji,
                            paletteId: selectedPaletteId,
                        });
                    }

                    Toast.success(`Added ${escapeHtml(newMember.name)} to workspace.`);
                    if (typeof onMemberAdded === 'function') {
                        Promise.resolve().then(() => onMemberAdded()).catch(() => {});
                    } else {
                        const currentMembers = store.getState().members || [];
                        store.setState({
                            members: [...currentMembers, newMember],
                        });
                    }
                } catch (err) {
                    Toast.error(err.message || 'Failed to add member.');
                    throw err;
                }
            },
        });
    }
}
