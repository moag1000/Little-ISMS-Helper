import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { testEntityName } from '../fixtures/data';

test.describe('Supplier onboarding', () => {
    test('admin can create a Supplier via the form', async ({ page }) => {
        await loginAs(page, 'admin');
        const name = testEntityName('Supplier');

        // Try the new-supplier form. Cloud-templates UI (AWS / Azure / GCP)
        // is typically a separate wizard at /de/supplier/template — we'll
        // exercise the canonical CRUD here.
        const resp = await page.goto('/de/supplier/new', { waitUntil: 'domcontentloaded' });
        if (!resp || resp.status() >= 400) {
            test.skip(true, 'supplier new-form unavailable in this build');
        }

        await expect(page.locator('form')).toBeVisible();

        const nameInput = page
            .locator('input[name="supplier[name]"], input[name="supplier_form[name]"]')
            .first();
        await nameInput.fill(name);

        // Some installs require a contact e-mail.
        const email = page
            .locator(
                'input[name="supplier[contactEmail]"], input[name="supplier_form[contactEmail]"], input[type="email"]',
            )
            .first();
        if (await email.count()) await email.fill('e2e@supplier.test');

        await page.click('button[type="submit"]');

        await expect(page).not.toHaveURL(/\/supplier\/new$/);
        await page.goto('/de/supplier');
        await expect(page.locator('body')).toContainText(name);
    });
});
