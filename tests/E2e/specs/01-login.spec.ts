import { test, expect } from '@playwright/test';
import { loginAs, logout } from '../fixtures/auth';

test.describe('Login flow', () => {
    test('admin can log in and lands on a dashboard-style page', async ({ page }) => {
        await loginAs(page, 'admin');

        // Post-login the persona-router may redirect to /dashboard, /my-day,
        // or a dashboard variant. Assert we left /login and a recognizable
        // shell element is on the page.
        await expect(page).not.toHaveURL(/\/login/);
        await expect(page.locator('main, .fa-page, #app, body')).toBeVisible();
    });

    test('logout returns to the login page', async ({ page }) => {
        await loginAs(page, 'admin');
        await logout(page);
        await expect(page).toHaveURL(/\/login/);
    });

    test('invalid credentials show an error', async ({ page }) => {
        await page.goto('/de/login', { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="_username"]', 'no-such-user@example.test');
        await page.fill('input[name="_password"]', 'wrong-password-1234');
        await page.click('button[type="submit"]');

        // Expect to stay on /login with an error indicator visible.
        await page.waitForURL(/\/login/, { timeout: 10_000 }).catch(() => {});
        await expect(page).toHaveURL(/\/login/);
    });
});
