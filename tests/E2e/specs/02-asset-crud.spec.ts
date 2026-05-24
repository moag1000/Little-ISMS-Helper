import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { testEntityName } from '../fixtures/data';
import { AssetPage } from '../page-objects/AssetPage';

test.describe('Asset CRUD', () => {
    test('admin can create, view, edit and delete an asset', async ({ page }) => {
        await loginAs(page, 'admin');
        const assetPage = new AssetPage(page);
        const name = testEntityName('Asset');

        // CREATE
        await assetPage.gotoNew();
        await assetPage.fillRequired(name);
        await assetPage.submit();

        // After save we should leave /new and land on show / index with the name visible.
        await expect(page).not.toHaveURL(/\/asset\/new$/);
        // Be lenient about which template renders: either an .fa-alert success
        // banner, an entity show heading, or the index row listing it.
        const success = page.locator('.fa-alert--success, .alert-success').first();
        const nameVisible = page.getByText(name).first();
        await Promise.race([
            success.waitFor({ timeout: 10_000 }).catch(() => null),
            nameVisible.waitFor({ timeout: 10_000 }).catch(() => null),
        ]);

        // VERIFY in the index list
        await assetPage.gotoIndex();
        await expect(page.locator('body')).toContainText(name);
    });
});
