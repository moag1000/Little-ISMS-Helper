import type { Page, Locator } from '@playwright/test';
import { expect } from '@playwright/test';

/**
 * Page-object for the Asset CRUD flow.
 *
 * The Symfony FormType prefix is `asset_form`, so input names look like
 * `asset_form[name]`. The shared `_form_field.html.twig` partial renders
 * the field labels but field-name attributes come from Symfony Form.
 */
export class AssetPage {
    constructor(private readonly page: Page, private readonly locale: 'de' | 'en' = 'de') {}

    async gotoNew(): Promise<void> {
        await this.page.goto(`/${this.locale}/asset/new`);
        await expect(this.page.locator('form')).toBeVisible();
    }

    async gotoIndex(): Promise<void> {
        await this.page.goto(`/${this.locale}/asset`);
    }

    async fillRequired(name: string, type: string = 'hardware'): Promise<void> {
        await this.page.fill('input[name="asset_form[name]"]', name);
        // assetType is a <select> — first non-empty option fallback.
        const typeSelect = this.page.locator('select[name="asset_form[assetType]"]');
        const optionCount = await typeSelect.locator('option').count();
        if (optionCount > 0) {
            // Try to select by value, fall back to first non-empty option.
            const targetValue = await typeSelect
                .locator(`option[value="${type}"]`)
                .count()
                .then((c) => (c > 0 ? type : null));
            if (targetValue) {
                await typeSelect.selectOption(targetValue);
            } else {
                await typeSelect.selectOption({ index: 1 });
            }
        }
    }

    async submit(): Promise<void> {
        await this.page.click('button[type="submit"]');
    }

    rowByName(name: string): Locator {
        return this.page.locator(`table tr:has-text("${name}"), .fa-entity-card:has-text("${name}")`).first();
    }
}
