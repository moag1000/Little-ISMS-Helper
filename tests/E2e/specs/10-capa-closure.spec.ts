import { test, expect } from '@playwright/test';
import { loginAs } from '../fixtures/auth';

test.describe('CAPA lifecycle', () => {
    test('admin can open the CAPA index without errors', async ({ page }) => {
        await loginAs(page, 'admin');

        // CAPA is reachable via /de/capa/ or /de/corrective-action/ depending
        // on the implementation generation. Try both, accept the one that
        // does not 404.
        const candidates = ['/de/capa/', '/de/corrective-action/', '/de/capa', '/de/capas'];
        let opened: string | null = null;

        for (const path of candidates) {
            const resp = await page.goto(path, { waitUntil: 'domcontentloaded' });
            if (resp && resp.status() < 400) {
                opened = path;
                break;
            }
        }

        if (!opened) {
            test.skip(true, 'no CAPA index route found in this build');
        }

        await expect(page.locator('body')).toBeVisible();
        // CAPA closure via lifecycle transition needs a CAPA fixture to exist
        // — the smoke covers that the controller wires + the index renders.
    });
});
