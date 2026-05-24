import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { testEntityName } from '../fixtures/data';
import { RiskPage } from '../page-objects/RiskPage';

test.describe('Risk creation', () => {
    test('admin can create a Risk with severity + probability', async ({ page }) => {
        await loginAs(page, 'admin');
        const riskPage = new RiskPage(page);
        const title = testEntityName('Risk');

        await riskPage.gotoNew();
        await riskPage.fillMinimum(title);
        await riskPage.submit();

        // After save, expect to leave /new and find the risk title somewhere.
        await expect(page).not.toHaveURL(/\/risk\/new$/);
        await expect(page.locator('body')).toContainText(title);
    });
});
