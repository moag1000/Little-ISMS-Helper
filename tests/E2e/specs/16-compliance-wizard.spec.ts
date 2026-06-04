import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { assertNoRawKeys } from '../fixtures/i18n';

/**
 * Compliance-Wizard coverage.
 *
 * Guards the bugs fixed in PR #850 / #855:
 *   - the wizard rendered raw i18n keys / 500s on prod
 *   - `manual` checks always scored 0 + showed a permanent gap, ignoring
 *     tenant data; #850 added the manual sign-off toggle (ROLE_MANAGER)
 *
 * The screenshot-user is SUPER_ADMIN+MANAGER+AUDITOR+DPO, so it can reach the
 * wizard (ROLE_AUDITOR) and use the sign-off toggle (ROLE_MANAGER).
 */
test.describe('Compliance Wizard', () => {
    test('index renders without 5xx or untranslated keys', async ({ page }) => {
        await loginAs(page, 'manager');
        const resp = await page.goto('/de/compliance-wizard');
        expect(resp?.status() ?? 200).toBeLessThan(500);
        await assertNoRawKeys(page, 'compliance-wizard index');
    });

    test('ISO 27001 assessment overview renders cleanly', async ({ page }) => {
        await loginAs(page, 'manager');
        const resp = await page.goto('/de/compliance-wizard/iso27001/assess');
        expect(resp?.status() ?? 200).toBeLessThan(500);
        await assertNoRawKeys(page, 'iso27001 assess');
    });

    test('ISO 27001 context category renders cleanly', async ({ page }) => {
        await loginAs(page, 'manager');
        const resp = await page.goto('/de/compliance-wizard/iso27001/category/context');
        // Either the category renders (200) or redirects to assess when the
        // wizard's required module is inactive — both are non-5xx, no raw keys.
        expect(resp?.status() ?? 200).toBeLessThan(500);
        await assertNoRawKeys(page, 'iso27001 category/context');
    });

    test('manual clause can be marked addressed and reverted', async ({ page }) => {
        await loginAs(page, 'manager');
        await page.goto('/de/compliance-wizard/iso27001/category/leadership');
        await assertNoRawKeys(page, 'iso27001 category/leadership');

        // The manual sign-off toggle (#850). Skip gracefully if the assessment
        // redirected (module off) — the i18n/health assertions above still ran.
        const markDone = page.locator('form[action*="confirm_manual"] button[type="submit"]').first();
        if (await markDone.count() === 0) {
            test.skip(true, 'no manual clause rendered (wizard module inactive in this tenant)');
            return;
        }

        await markDone.click();
        await page.waitForLoadState('domcontentloaded');
        await assertNoRawKeys(page, 'after mark-addressed');

        // After confirming, a revert ("Erledigt") toggle for the same clause exists.
        const revert = page.locator('form[action*="confirm_manual"] button[type="submit"]');
        await expect(revert.first()).toBeVisible();

        // Revert the first confirmed one so the test is idempotent across runs.
        const revertConfirmed = page.locator('form[action*="confirm_manual"] input[name="confirmed"][value="0"]').first();
        if (await revertConfirmed.count() > 0) {
            await revertConfirmed.locator('xpath=ancestor::form').locator('button[type="submit"]').click();
            await page.waitForLoadState('domcontentloaded');
        }
    });

    test('effectiveness monitor renders without 5xx or untranslated keys', async ({ page }) => {
        await loginAs(page, 'auditor');
        const resp = await page.goto('/de/effectiveness-monitor');
        expect(resp?.status() ?? 200).toBeLessThan(500);
        await assertNoRawKeys(page, 'effectiveness-monitor');
    });
});
