import type { Page } from '@playwright/test';

export class IncidentPage {
    constructor(private readonly page: Page, private readonly locale: 'de' | 'en' = 'de') {}

    async gotoNew(): Promise<void> {
        await this.page.goto(`/${this.locale}/incident/new`);
    }

    async fillMinimum(title: string, severity: string = 'high'): Promise<void> {
        const titleInput = this.page
            .locator('input[name="incident[title]"], input[name="incident_form[title]"]')
            .first();
        await titleInput.fill(title);

        const desc = this.page
            .locator('textarea[name="incident[description]"], textarea[name="incident_form[description]"]')
            .first();
        if (await desc.count()) await desc.fill(`E2E incident seed ${title}`);

        const sevSelect = this.page
            .locator('select[name="incident[severity]"], select[name="incident_form[severity]"]')
            .first();
        if (await sevSelect.count()) {
            const opt = await sevSelect.locator(`option[value="${severity}"]`).count();
            await sevSelect.selectOption(opt > 0 ? severity : { index: 1 });
        }
    }

    async submit(): Promise<void> {
        await this.page.click('button[type="submit"]');
    }
}
