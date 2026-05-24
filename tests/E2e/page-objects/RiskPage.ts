import type { Page } from '@playwright/test';
import { expect } from '@playwright/test';

/**
 * Page-object for the Risk CRUD + Risk-Treatment flow.
 */
export class RiskPage {
    constructor(private readonly page: Page, private readonly locale: 'de' | 'en' = 'de') {}

    async gotoNew(): Promise<void> {
        await this.page.goto(`/${this.locale}/risk/new`);
        await expect(this.page.locator('form')).toBeVisible();
    }

    async gotoIndex(): Promise<void> {
        await this.page.goto(`/${this.locale}/risk`);
    }

    /**
     * Fill the minimum-set of risk fields. FormType prefix is `risk` (varies
     * by FormType, normally inferred from class name: `Risk` → `risk`).
     */
    async fillMinimum(title: string, description: string = 'E2E risk seed description'): Promise<void> {
        const titleInput = this.page.locator(
            'input[name="risk[title]"], input[name="risk_form[title]"], input[name="risk[name]"]',
        ).first();
        await titleInput.fill(title);

        const descInput = this.page.locator(
            'textarea[name="risk[description]"], textarea[name="risk_form[description]"]',
        ).first();
        if (await descInput.count()) {
            await descInput.fill(description);
        }

        // Severity / impact / probability — selects typically named `severity`,
        // `impact`, or `inherentLikelihood` depending on FormType variant.
        const severitySelectors = [
            'select[name="risk[severity]"]',
            'select[name="risk_form[severity]"]',
            'select[name="risk[impact]"]',
        ];
        for (const sel of severitySelectors) {
            const el = this.page.locator(sel);
            if (await el.count()) {
                await el.selectOption({ index: 1 }).catch(() => {});
                break;
            }
        }

        const probabilitySelectors = [
            'select[name="risk[probability]"]',
            'select[name="risk[likelihood]"]',
            'select[name="risk[inherentLikelihood]"]',
        ];
        for (const sel of probabilitySelectors) {
            const el = this.page.locator(sel);
            if (await el.count()) {
                await el.selectOption({ index: 1 }).catch(() => {});
                break;
            }
        }
    }

    async submit(): Promise<void> {
        await this.page.click('button[type="submit"]');
    }
}
