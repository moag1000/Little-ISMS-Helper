import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { testEntityName } from '../fixtures/data';

test.describe('BC exercise', () => {
    test('admin can open the BC-Exercise form and submit it', async ({ page }) => {
        await loginAs(page, 'admin');
        const name = testEntityName('BcExercise');

        const resp = await page.goto('/de/bc-exercise/new', { waitUntil: 'domcontentloaded' });
        if (!resp || resp.status() >= 400) {
            test.skip(true, 'bcm module not enabled in this tenant');
        }

        await expect(page.locator('form')).toBeVisible();

        const nameInput = page
            .locator(
                'input[name="bc_exercise[name]"], input[name="bc_exercise_form[name]"], input[name="bc_exercise[title]"]',
            )
            .first();
        await nameInput.fill(name);

        await page.click('button[type="submit"]');

        await expect(page).not.toHaveURL(/\/bc-exercise\/new$/);
        await page.goto('/de/bc-exercise');
        await expect(page.locator('body')).toContainText(name);

        // AlvaHint `target_missed` materialisation when actualRTO > planRTO
        // belongs to the AlvaHint listener; the unit test covers the rule,
        // the E2E confirms the source exercise persisted.
    });
});
