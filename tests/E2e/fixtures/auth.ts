import type { Page, BrowserContext } from '@playwright/test';
import { expect } from '@playwright/test';

/**
 * Auth fixture for E2E tests.
 *
 * Uses the screenshot-user provisioned via `php bin/console app:create-screenshot-user`.
 * That user is a SUPER_ADMIN + MANAGER + AUDITOR + DPO in tenant `screenshots`,
 * which means a single fixture covers every happy-path persona in the suite.
 *
 * For now the role parameter is informational only — all roles resolve to the
 * same screenshot-user. Once tenant isolation is wired (see tests/e2e/README.md
 * §Test-data lifecycle) we will pivot to per-role accounts and per-test tenants.
 */

export type E2eRole = 'admin' | 'manager' | 'auditor' | 'dpo' | 'user';

const DEFAULT_USER = process.env.E2E_USER || 'screenshots@local.test';
const DEFAULT_PASS = process.env.E2E_PASS || 'Screenshots-Aurora-2026!';

export interface LoginOptions {
    /** Persona role hint (advisory — see file header). */
    role?: E2eRole;
    /** Override credentials for this call. */
    email?: string;
    password?: string;
    /** Locale prefix on the login URL. */
    locale?: 'de' | 'en';
}

/**
 * Log in to Little ISMS Helper and assert we landed on a post-login page.
 *
 * Mirrors the proven login flow from `scripts/screenshots/capture.mjs` —
 * waits for the POST response so Turbo redirects don't race the assertion.
 */
export async function loginAs(page: Page, role: E2eRole = 'admin', opts: LoginOptions = {}): Promise<void> {
    const locale = opts.locale ?? 'de';
    const email = opts.email ?? DEFAULT_USER;
    const password = opts.password ?? DEFAULT_PASS;

    await page.goto(`/${locale}/login`, { waitUntil: 'domcontentloaded' });

    await page.fill('input[name="_username"]', email);
    await page.fill('input[name="_password"]', password);

    const [postResponse] = await Promise.all([
        page.waitForResponse(
            (r) => r.request().method() === 'POST' && r.url().includes('/login'),
            { timeout: 15_000 },
        ),
        page.click('button[type="submit"]'),
    ]);

    const status = postResponse.status();
    const redirectTo = postResponse.headers()['location'] || '';

    if (status !== 302 || redirectTo.includes('/login')) {
        const errEl = await page
            .locator('.fa-alert--danger, .alert-danger, .invalid-feedback')
            .first()
            .textContent()
            .catch(() => null);
        throw new Error(
            `loginAs(${role}) failed: POST returned ${status} → ${redirectTo || 'n/a'}. ` +
                `Error text: ${errEl ?? 'n/a'}. ` +
                `Ensure user exists: php bin/console app:create-screenshot-user`,
        );
    }

    // Follow the 302; tolerate post-login redirects (dashboard / my-day / persona-router).
    await page.waitForURL((u) => !u.toString().includes('/login'), { timeout: 15_000 }).catch(() => {});

    const currentUrl = page.url();
    if (currentUrl.includes('/login') || currentUrl.includes('/mfa')) {
        throw new Error(
            `loginAs(${role}): post-login navigation failed (stuck on ${currentUrl}). ` +
                `MFA may be enforced for this user.`,
        );
    }
}

/**
 * Persist storageState (cookies + localStorage) so subsequent tests can skip
 * the login round-trip. Use sparingly — sharing auth state across tests
 * couples them. Per-test login is cheap (< 1 s) and worth the isolation.
 */
export async function saveAuthState(context: BrowserContext, path: string): Promise<void> {
    await context.storageState({ path });
}

/**
 * Convenience: log out via the standard `/logout` endpoint.
 *
 * Most tests don't need this — closing the BrowserContext at end-of-test
 * already discards cookies. Use only when a test needs to verify the
 * logout-flow itself.
 */
export async function logout(page: Page, locale: 'de' | 'en' = 'de'): Promise<void> {
    await page.goto(`/${locale}/logout`);
    await expect(page).toHaveURL(/\/login/, { timeout: 10_000 });
}
