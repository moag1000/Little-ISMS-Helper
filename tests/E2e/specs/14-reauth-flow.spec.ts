import { test, expect } from '@playwright/test';
import { loginAs, logout } from '../fixtures/auth';

test.describe('Re-auth flow', () => {
    test('after logout the protected dashboard redirects to /login', async ({ page }) => {
        await loginAs(page, 'admin');
        await logout(page);

        // Access a protected page; expect a redirect-to-login.
        await page.goto('/de/dashboard', { waitUntil: 'domcontentloaded' });
        await expect(page).toHaveURL(/\/login/);
    });

    test('admin can re-login on the same context', async ({ page, context }) => {
        await loginAs(page, 'admin');
        // Tear the session cookies down without going through the logout flow
        // (mimics an expired session / RememberMe-only state).
        await context.clearCookies();

        await page.goto('/de/dashboard', { waitUntil: 'domcontentloaded' });
        await expect(page).toHaveURL(/\/login/);

        await loginAs(page, 'admin');
        await expect(page).not.toHaveURL(/\/login/);
    });
});
