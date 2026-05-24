import type { Page } from '@playwright/test';

export class DataBreachPage {
    constructor(private readonly page: Page, private readonly locale: 'de' | 'en' = 'de') {}

    async gotoNew(): Promise<void> {
        await this.page.goto(`/${this.locale}/data-breach/new`);
    }

    async fillMinimum(title: string): Promise<void> {
        const titleInput = this.page
            .locator(
                'input[name="data_breach[title]"], input[name="data_breach_form[title]"], input[name="databreach[title]"]',
            )
            .first();
        await titleInput.fill(title);

        const desc = this.page
            .locator(
                'textarea[name="data_breach[description]"], textarea[name="data_breach_form[description]"]',
            )
            .first();
        if (await desc.count()) await desc.fill(`E2E breach seed ${title}`);

        // Severity if present
        const sev = this.page
            .locator('select[name="data_breach[severity]"], select[name="data_breach_form[severity]"]')
            .first();
        if (await sev.count()) await sev.selectOption({ index: 1 }).catch(() => {});
    }

    async submit(): Promise<void> {
        await this.page.click('button[type="submit"]');
    }
}
