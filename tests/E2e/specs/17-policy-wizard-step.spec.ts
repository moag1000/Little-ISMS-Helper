import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../fixtures/auth';
import { assertNoRawKeys } from '../fixtures/i18n';

/**
 * Policy-Wizard step coverage.
 *
 * Guards the orphan-i18n regression class behind PR #851: wizard steps render
 * dynamically-built translation keys (e.g. the operational_baselines
 * "Access, identity & logging" section) that can silently ship without their
 * yaml entries — showing raw keys like
 * `policy_wizard.step.operational_baselines.access_review_cadence_label` in prod.
 *
 * We drive a REAL run (start → pick a standard → walk forward), asserting no
 * untranslated keys on every step we can reach. Deep steps are gated behind
 * bespoke required widgets; reaching operational_baselines additionally asserts
 * its German section renders. (operational_baselines i18n is also verified at
 * merge time via the kernel translator — see PR #851.)
 */

async function fillRequired(page: Page): Promise<void> {
    const inputs = page.locator('form[action*="/step/"] input[required]:not([type="hidden"]):not([type="checkbox"]):not([type="radio"])');
    for (let i = 0; i < await inputs.count(); i++) {
        const el = inputs.nth(i);
        if (!(await el.isVisible().catch(() => false))) continue;
        if ((await el.inputValue().catch(() => '')) !== '') continue;
        const type = (await el.getAttribute('type')) ?? 'text';
        const value = type === 'number' ? (await el.getAttribute('min')) || '1'
            : type === 'email' ? 'e2e@test.local'
            : type === 'date' ? '2026-01-01'
            : 'E2E';
        await el.fill(value).catch(() => {});
    }
    const selects = page.locator('form[action*="/step/"] select[required]');
    for (let i = 0; i < await selects.count(); i++) {
        const el = selects.nth(i);
        if (!(await el.isVisible().catch(() => false))) continue;
        const opts = await el.locator('option').evaluateAll(
            (os) => os.map((o) => (o as HTMLOptionElement).value).filter((v) => v !== ''),
        );
        if (opts.length > 0) await el.selectOption(opts[0]).catch(() => {});
    }
}

function forwardSubmit(page: Page) {
    return page.locator('form[action*="/step/"] button[type="submit"]')
        .filter({ hasNotText: 'Abbrechen' })
        .filter({ hasNotText: 'Zurück' })
        .last();
}

test.describe('Policy Wizard', () => {
    test('landing page renders without untranslated keys', async ({ page }) => {
        await loginAs(page, 'manager');
        const resp = await page.goto('/de/policy-wizard');
        expect(resp?.status() ?? 200).toBeLessThan(500);
        await assertNoRawKeys(page, 'policy-wizard index');
    });

    test('a real run walks its steps with no untranslated keys', async ({ page }) => {
        test.setTimeout(90_000);
        await loginAs(page, 'manager');
        await page.goto('/de/policy-wizard');

        const startBtn = page.locator('form[action*="policy-wizard/start"] button[type="submit"]').first();
        if (await startBtn.count() === 0) {
            test.skip(true, 'no policy-wizard start form on landing page');
            return;
        }
        await startBtn.click();
        await page.waitForURL(/\/policy-wizard\/run\/\d+\/step\//, { timeout: 15_000 });

        // Welcome: pick a standard so the run has scope.
        const isoStandard = page.locator('input[type="checkbox"][value="iso27001"]');
        if (await isoStandard.count() > 0) {
            await isoStandard.first().check().catch(() => {});
        }

        const visited: string[] = [];
        for (let i = 0; i < 8; i++) {
            const step = /\/step\/([a-z_]+)/.exec(page.url())?.[1] ?? '?';
            visited.push(step);

            // Every reached step must be free of untranslated keys (the #851 guard).
            await assertNoRawKeys(page, `policy-wizard step "${step}"`);

            if (step === 'operational_baselines') {
                await expect(page.locator('body')).toContainText('Zugang, Identität');
                await expect(page.locator('body')).toContainText('MFA-Geltungsbereich');
                break;
            }

            await fillRequired(page);
            const fwd = forwardSubmit(page);
            if (await fwd.count() === 0) break;
            const before = page.url();
            await fwd.click().catch(() => {});
            await page.waitForLoadState('domcontentloaded');
            await page.waitForTimeout(400);
            if (page.url() === before) break; // gated step we cannot auto-complete
        }

        // Non-vacuous: we actually started a run and rendered real wizard steps.
        expect(visited.length, `walked: ${visited.join(' → ')}`).toBeGreaterThanOrEqual(2);
        expect(visited[0]).toBe('welcome');
    });
});
