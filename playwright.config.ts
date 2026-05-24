import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for Little ISMS Helper E2E test suite.
 *
 * Tests live in `tests/E2e/specs/` and use shared fixtures from
 * `tests/E2e/fixtures/` and page-objects from `tests/E2e/page-objects/`.
 * The PascalCase folder name follows the existing PHPUnit `tests/E2e/`
 * convention in this repo (one shared E2E namespace, two engines).
 *
 * Local run:
 *   1. Start Symfony server: `symfony serve -d`
 *   2. Seed user:           `php bin/console app:create-screenshot-user`
 *   3. Run tests:           `npm run e2e`
 *
 * CI: see .github/workflows/e2e.yml
 */

const isCI = !!process.env.CI;
const BASE_URL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8000';

export default defineConfig({
    testDir: './tests/E2e/specs',
    testMatch: /.*\.spec\.ts$/,
    outputDir: './var/playwright-results',

    // Run all tests in a file in sequence to keep DB-state predictable.
    // Files themselves still run in parallel across workers.
    fullyParallel: false,
    workers: isCI ? 1 : 2,

    // Hard fail on test.only in CI — keeps PRs from skipping the suite.
    forbidOnly: isCI,

    retries: isCI ? 1 : 0,

    // Default per-action timeout. Most pages render < 5 s; allow headroom.
    timeout: 60_000,
    expect: {
        timeout: 10_000,
    },

    reporter: isCI
        ? [['list'], ['html', { outputFolder: 'var/playwright-report', open: 'never' }], ['github']]
        : [['list'], ['html', { outputFolder: 'var/playwright-report', open: 'never' }]],

    use: {
        baseURL: BASE_URL,
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        // Browser context defaults.
        viewport: { width: 1440, height: 900 },
        // Capture artefacts on failure (cheap when tests pass).
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        trace: 'on-first-retry',
        // Match the dev/test server CSRF setup.
        ignoreHTTPSErrors: true,
        // Helpful test-stability defaults.
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],

    // In CI we boot symfony-server in the workflow before invoking playwright,
    // so this hook is only used for local convenience. Disable via env if you
    // already have a server running on a different port.
    webServer: process.env.E2E_NO_WEBSERVER
        ? undefined
        : {
              command: 'symfony serve --no-tls --port=8000',
              url: BASE_URL,
              reuseExistingServer: !isCI,
              timeout: 120_000,
              stdout: 'ignore',
              stderr: 'pipe',
          },
});
