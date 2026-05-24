import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { testEntityName } from '../fixtures/data';
import { IncidentPage } from '../page-objects/IncidentPage';

test.describe('Incident workflow', () => {
    test('admin can report a high-severity Incident', async ({ page }) => {
        await loginAs(page, 'admin');
        const incident = new IncidentPage(page);
        const title = testEntityName('Incident');

        await incident.gotoNew();
        await incident.fillMinimum(title, 'high');
        await incident.submit();

        // After save, expect the incident title to be discoverable.
        await expect(page).not.toHaveURL(/\/incident\/new$/);
        await page.goto('/de/incident');
        await expect(page.locator('body')).toContainText(title);

        // CAPA hint / auto-progression may not surface in the index — the
        // canonical assertion is "incident persisted and index renders".
        // A dedicated auto-CAPA test belongs in PHPUnit (event listener
        // is unit-testable; UI side-effect is observable only on show).
    });
});
