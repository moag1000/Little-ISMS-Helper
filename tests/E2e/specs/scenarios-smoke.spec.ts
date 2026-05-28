import { test, expect, type Page } from '@playwright/test';
import { readdirSync, readFileSync, mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { randomBytes } from 'node:crypto';
import yaml from 'js-yaml';
import { loginAs } from '../fixtures/auth';

/**
 * L2 Form-Fill Smoke: loads every YAML scenario under
 * `tests/E2e/coverage/scenarios/`, fills the declared form fields,
 * submits, and asserts the post-submit state matches the `expect`
 * block (status / url-pattern / text-contains / flash-contains).
 *
 * L1 (`route-smoke.spec.ts`) catches GET-side 5xx; L2 catches the
 * POST-side bugs golden-path specs miss because they test 1-2 forms
 * each and miss the long tail (Document-Type, Asset-Type, …).
 *
 * Opt-in via env: `BROWSER_COVERAGE_SCENARIOS=1` (or running through
 * `scripts/browser-coverage/run-scenarios.sh`). Spec is otherwise
 * skipped so regular `npm run e2e` is unaffected.
 *
 * Output: `var/browser-coverage/scenario-results/<persona>.json`
 *         + HTML aggregation via `generate-scenario-report.mjs`.
 */

const PROJECT_ROOT = resolve(__dirname, '..', '..', '..');
const SCENARIOS_DIR = resolve(PROJECT_ROOT, 'tests/E2e/coverage/scenarios');
const PERSONA_YAML = resolve(PROJECT_ROOT, 'tests/E2e/coverage/persona-routes.yaml');
const RESULTS_DIR = resolve(PROJECT_ROOT, 'var/browser-coverage/scenario-results');

interface ScenarioFile {
    scenarios?: Scenario[];
}

interface Scenario {
    name: string;
    label?: string;
    route: string;
    category?: string;
    personas?: string[];
    requires_module?: string;
    fill?: Record<string, string>;
    before_submit?: Array<{ selector: string; action: 'click' | 'check' | 'uncheck' }>;
    submit?: string;
    expect?: {
        status_lt?: number;
        url_pattern?: string;
        text_contains?: string;
        flash_contains?: string;
        no_console_errors?: boolean;
    };
}

interface ScenarioResult {
    persona: string;
    scenario: string;
    label: string;
    route: string;
    category: string;
    finalUrl: string;
    finalStatus: number | null;
    durationMs: number;
    consoleErrors: string[];
    pageErrors: string[];
    ok: boolean;
    skipReason?: string;
    failReason?: string;
}

const PERSONA = process.env.BROWSER_COVERAGE_PERSONA || 'full-sweep';
const ENABLED = !!process.env.BROWSER_COVERAGE_SCENARIOS;

function loadScenarios(): Scenario[] {
    if (!existsSync(SCENARIOS_DIR)) return [];
    const files = readdirSync(SCENARIOS_DIR)
        .filter((f) => f.endsWith('.yaml') && !f.startsWith('_'));
    const scenarios: Scenario[] = [];
    for (const f of files) {
        const payload = yaml.load(readFileSync(resolve(SCENARIOS_DIR, f), 'utf8')) as ScenarioFile;
        for (const s of payload.scenarios ?? []) {
            scenarios.push(s);
        }
    }
    return scenarios;
}

function filterForPersona(scenarios: Scenario[], persona: string): Scenario[] {
    return scenarios.filter((s) => !s.personas || s.personas.includes(persona) || persona === 'full-sweep');
}

function interpolate(value: string, ctx: { suffix: string }): string {
    return value.replace(/\$\{random\.suffix\}/g, ctx.suffix);
}

async function checkModuleActive(page: Page, moduleKey: string): Promise<boolean> {
    try {
        const res = await page.request.get('/de/admin/modules');
        if (res.status() !== 200) return true;
        const body = await res.text();
        return new RegExp(`data-module-key="${moduleKey}"[^>]*data-active="(?:true|1)"`).test(body);
    } catch {
        return true;
    }
}

async function runScenario(page: Page, scenario: Scenario): Promise<ScenarioResult> {
    const consoleErrors: string[] = [];
    const pageErrors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', (err) => pageErrors.push(err.message));

    const t0 = Date.now();
    const baseResult: ScenarioResult = {
        persona: PERSONA,
        scenario: scenario.name,
        label: scenario.label ?? scenario.name,
        route: scenario.route,
        category: scenario.category ?? 'form',
        finalUrl: '',
        finalStatus: null,
        durationMs: 0,
        consoleErrors,
        pageErrors,
        ok: false,
    };

    try {
        const navResp = await page.goto(scenario.route, { waitUntil: 'domcontentloaded', timeout: 20_000 });
        baseResult.finalStatus = navResp?.status() ?? null;
        baseResult.finalUrl = page.url();

        if (baseResult.finalStatus !== null && baseResult.finalStatus >= 400) {
            baseResult.failReason = `Form GET returned ${baseResult.finalStatus}`;
            return finishResult(baseResult, t0);
        }

        const ctx = { suffix: randomBytes(4).toString('hex') };
        for (const [selector, raw] of Object.entries(scenario.fill ?? {})) {
            const value = interpolate(raw, ctx);
            const locator = page.locator(selector).first();
            const tag = await locator.evaluate((el) => el.tagName).catch(() => 'NONE');
            if (tag === 'NONE') {
                baseResult.failReason = `Selector not found: ${selector}`;
                return finishResult(baseResult, t0);
            }

            // fa-form-layout sections hide fields by default; the strict
            // Playwright fill() / selectOption() waits for visible and
            // times out. Try the visible-aware API first, fall back to a
            // direct DOM-value-set so required fields in collapsed
            // sections can still be filled by L2 smokes.
            try {
                if (tag === 'SELECT') {
                    await locator.selectOption(value, { timeout: 5_000 });
                } else {
                    await locator.fill(value, { timeout: 5_000 });
                }
            } catch {
                await locator.evaluate((el, v) => {
                    if (
                        el instanceof HTMLInputElement
                        || el instanceof HTMLTextAreaElement
                        || el instanceof HTMLSelectElement
                    ) {
                        el.value = v;
                    }
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }, value);
            }
        }

        for (const step of scenario.before_submit ?? []) {
            if (step.action === 'click') {
                await page.locator(step.selector).first().click({ timeout: 5_000 });
            } else if (step.action === 'check') {
                await page.locator(step.selector).first().check({ timeout: 5_000 });
            } else if (step.action === 'uncheck') {
                await page.locator(step.selector).first().uncheck({ timeout: 5_000 });
            }
        }

        const submitSelector = scenario.submit && scenario.submit !== 'auto'
            ? scenario.submit
            : 'button[type="submit"]';

        // The base template renders an inline re-auth modal whose hidden
        // form contains its own submit button. `.first()` would grab that
        // (invisible) button and time out. Filter for visible so we hit
        // the real form-action submit.
        const submitLocator = page.locator(submitSelector).filter({ visible: true }).first();

        const [response] = await Promise.all([
            page.waitForResponse(
                (r) => r.request().method() === 'POST' || r.request().method() === 'PUT',
                { timeout: 20_000 },
            ).catch(() => null),
            submitLocator.click({ timeout: 5_000 }),
        ]);

        baseResult.finalStatus = response?.status() ?? baseResult.finalStatus;

        await page.waitForLoadState('domcontentloaded', { timeout: 10_000 }).catch(() => {});
        baseResult.finalUrl = page.url();

        const expectations = scenario.expect ?? {};
        const failures: string[] = [];

        if (expectations.status_lt && baseResult.finalStatus !== null && baseResult.finalStatus >= expectations.status_lt) {
            failures.push(`status ${baseResult.finalStatus} >= ${expectations.status_lt}`);
        }
        if (expectations.url_pattern) {
            const re = new RegExp(expectations.url_pattern);
            const path = new URL(baseResult.finalUrl, 'http://x').pathname;
            if (!re.test(path)) failures.push(`url "${path}" !~ /${expectations.url_pattern}/`);
        }
        if (expectations.text_contains) {
            const body = await page.content();
            if (!body.includes(expectations.text_contains)) {
                failures.push(`text_contains "${expectations.text_contains}" not in body`);
            }
        }
        if (expectations.flash_contains) {
            const flashes = await page.locator('.fa-toast, .toast, .alert, .fa-alert').allTextContents();
            if (!flashes.some((f) => f.includes(expectations.flash_contains!))) {
                failures.push(`flash_contains "${expectations.flash_contains}" not found`);
            }
        }
        const checkConsole = expectations.no_console_errors !== false;
        if (checkConsole && consoleErrors.length > 0) {
            failures.push(`${consoleErrors.length} console error(s)`);
        }
        if (pageErrors.length > 0) {
            failures.push(`${pageErrors.length} page error(s)`);
        }

        baseResult.ok = failures.length === 0;
        if (failures.length > 0) baseResult.failReason = failures.join('; ');
    } catch (err) {
        baseResult.failReason = `Exception: ${(err as Error).message}`;
    }

    return finishResult(baseResult, t0);
}

function finishResult(r: ScenarioResult, t0: number): ScenarioResult {
    r.durationMs = Date.now() - t0;
    return r;
}

test.describe(`L2 Scenarios — ${PERSONA}`, () => {
    if (!ENABLED) {
        test('skipped — set BROWSER_COVERAGE_SCENARIOS=1 to enable', () => {
            test.skip(true, 'L2 scenarios disabled.');
        });
        return;
    }

    const scenarios = filterForPersona(loadScenarios(), PERSONA);

    if (scenarios.length === 0) {
        test('no scenarios matched persona', () => {
            test.skip(true, `No scenarios for persona "${PERSONA}".`);
        });
        return;
    }

    const results: ScenarioResult[] = [];

    test.beforeAll(() => {
        mkdirSync(RESULTS_DIR, { recursive: true });
    });

    test.afterAll(() => {
        const summary = {
            persona: PERSONA,
            ran_at: new Date().toISOString(),
            total: results.length,
            ok: results.filter((r) => r.ok).length,
            failed: results.filter((r) => !r.ok && !r.skipReason).length,
            skipped: results.filter((r) => r.skipReason).length,
            results,
        };
        writeFileSync(resolve(RESULTS_DIR, `${PERSONA}.json`), JSON.stringify(summary, null, 2));
        console.log(`\n[L2] ${PERSONA}: ${summary.ok}/${summary.total} ok, ${summary.failed} failed, ${summary.skipped} skipped`);
    });

    for (const scenario of scenarios) {
        test(`${scenario.category ?? 'form'} | ${scenario.name}`, async ({ page }) => {
            await loginAs(page);

            if (scenario.requires_module) {
                const active = await checkModuleActive(page, scenario.requires_module);
                if (!active) {
                    results.push({
                        persona: PERSONA, scenario: scenario.name, label: scenario.label ?? scenario.name,
                        route: scenario.route, category: scenario.category ?? 'form',
                        finalUrl: '', finalStatus: null, durationMs: 0,
                        consoleErrors: [], pageErrors: [],
                        ok: false, skipReason: `module "${scenario.requires_module}" inactive`,
                    });
                    test.skip(true, `module ${scenario.requires_module} inactive`);
                    return;
                }
            }

            const result = await runScenario(page, scenario);
            results.push(result);

            expect.soft(result.finalStatus, `final status for ${scenario.name}`).not.toBeGreaterThanOrEqual(500);
            expect.soft(result.pageErrors, `page errors for ${scenario.name}`).toEqual([]);
            expect.soft(result.ok, `scenario ${scenario.name} ok (reason: ${result.failReason ?? '-'})`).toBeTruthy();
        });
    }
});
