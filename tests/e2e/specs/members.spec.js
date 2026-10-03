import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Member Roster & Avatar Customization', () => {
  test('Adds multiple members and customizes avatar emoji and palette', async ({
    landingPage,
    memberSection,
  }) => {
    // 1. Setup fresh workspace
    await landingPage.goto('/');
    const wsName = `Avatar Test ${Date.now()}`;
    await landingPage.createWorkspace(wsName, 'Kavita');

    // 2. Add Member with Emoji
    await memberSection.addMember('Dev', '🚀');
    const memberNames = await memberSection.getMemberNames();
    expect(memberNames).toContain('Kavita');
    expect(memberNames).toContain('Dev');

    // 3. Customize Avatar for Kavita using curated emoji '🦊'
    await memberSection.customizeAvatar('Kavita', '🦊', 'amber');

    // 4. Verify updated avatar chip rendered
    const kavitaChip = memberSection.memberChips.filter({ hasText: 'Kavita' });
    await expect(kavitaChip).toBeVisible();
    await expect(kavitaChip).toContainText('🦊');
  });

  test('Validates empty member name in Add Member modal', async ({
    landingPage,
    memberSection,
  }) => {
    await landingPage.goto('/');
    await landingPage.createWorkspace(`Validation WS ${Date.now()}`, 'Organizer');

    // Open add member modal
    await memberSection.addMemberBtn.click();
    await expect(memberSection.modalDialog).toBeVisible();

    // Clear input and try to confirm
    await memberSection.newMemberNameInput.fill('');
    await memberSection.confirmModal();

    // Modal stays open because input is invalid
    await expect(memberSection.modalDialog).toBeVisible();
    await memberSection.closeModal();
  });
});
