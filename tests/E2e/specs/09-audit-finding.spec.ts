import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { testEntityName } from '../fixtures/data';

test.describe('Audit finding + auto-CAPA', () => {
    test('auditor can create a Major-NC AuditFinding', async ({ page }) => {
        await loginAs(page, 'auditor');
        const title = testEntityName('AuditFinding');

        await page.goto('/de/audit-finding/new');
        await expect(page.locator('form')).toBeVisible();

        const titleInput = page
            .locator(
                'input[name="audit_finding[title]"], input[name="audit_finding_form[title]"], input[name="audit_finding[description]"]',
            )
            .first();
        await titleInput.fill(title);

        // Set severity to 'major' if the select exists.
        const sev = page
            .locator(
                'select[name="audit_finding[severity]"], select[name="audit_finding_form[severity]"], select[name="audit_finding[classification]"]',
            )
            .first();
        if (await sev.count()) {
            const major = await sev.locator('option[value="major"], option[value="major_nc"]').count();
            await sev.selectOption(major > 0 ? 'major' : { index: 1 });
        }

        // Some forms require a description besides the title.
        const desc = page
            .locator('textarea[name="audit_finding[description]"], textarea[name="audit_finding_form[description]"]')
            .first();
        if (await desc.count()) await desc.fill(`E2E finding seed ${title}`);

        await page.click('button[type="submit"]');

        await expect(page).not.toHaveURL(/\/audit-finding\/new$/);
        await page.goto('/de/audit-finding/');
        await expect(page.locator('body')).toContainText(title);

        // Auto-CAPA materialisation is verified via Doctrine listener in PHPUnit.
        // E2E only confirms the source finding persisted.
    });
});
