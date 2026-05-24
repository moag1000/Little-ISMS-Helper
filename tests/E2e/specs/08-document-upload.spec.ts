import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { testEntityName } from '../fixtures/data';

test.describe('Document upload', () => {
    test('admin can open the document form and submit metadata', async ({ page }) => {
        await loginAs(page, 'admin');
        const title = testEntityName('Document');

        await page.goto('/de/document/new');
        const form = page.locator('form').first();
        await expect(form).toBeVisible();

        // The Document form has many variant FormType prefixes (document,
        // document_form, policy_document). Pick the first matching title input.
        const titleInput = page
            .locator(
                'input[name="document[title]"], input[name="document_form[title]"], input[name="document[name]"]',
            )
            .first();
        await titleInput.fill(title);

        // File upload is the canonical "happy path" — but the form often
        // accepts metadata-only saves for placeholder document rows. Try the
        // file input if present (best-effort, non-blocking).
        const fileInput = page.locator('input[type="file"]').first();
        if (await fileInput.count()) {
            // Upload an in-memory tiny PDF stub.
            const buffer = Buffer.from('%PDF-1.4\n%E2%E3%CF%D3\n%EOF\n');
            await fileInput.setInputFiles({
                name: 'e2e-doc.pdf',
                mimeType: 'application/pdf',
                buffer,
            });
        }

        await page.click('button[type="submit"]');

        // Document index must show the title (or the show page renders).
        await expect(page).not.toHaveURL(/\/document\/new$/);
        await page.goto('/de/document');
        await expect(page.locator('body')).toContainText(title);
    });
});
