import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { testEntityName } from '../fixtures/data';
import { DataBreachPage } from '../page-objects/DataBreachPage';

test.describe('GDPR Data Breach 72h flow', () => {
    test('admin can create a DataBreach and see it listed', async ({ page }) => {
        await loginAs(page, 'admin');
        const breach = new DataBreachPage(page);
        const title = testEntityName('DataBreach');

        await breach.gotoNew();

        // Module-gate: /data-breach is privacy-module-only. If the tenant
        // does not have GDPR enabled, the controller redirects to a settings
        // page. Bail-out cleanly so the suite stays green for tenants that
        // opt out of the privacy module.
        const url = page.url();
        if (url.includes('module') || url.includes('settings')) {
            test.skip(true, 'privacy module not enabled in this tenant');
        }

        await breach.fillMinimum(title);
        await breach.submit();

        // Verify the breach lands in the index — countdown visualization
        // (.fa-countdown / .breach-72h-timer) is tenant-feature-dependent
        // and tested as a unit assertion elsewhere.
        await page.goto('/de/data-breach');
        await expect(page.locator('body')).toContainText(title);
    });
});
