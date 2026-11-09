import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { loginAs } from '../fixtures/auth';

/**
 * WCAG 2.2 AA at run time on ten core pages (B3, Welle 10). The static gate 103
 * (W11: scripts/quality/check_a11y_static.py + check_token_contrast.py) checks the
 * templates; axe checks what the browser really renders: contrast of actual elements,
 * names of controls, label/error wiring, landmarks.
 *
 * CI runs it only on release tags, against the Docker Compose stack (job "Docker Build & Push",
 * step "Accessibility (axe)"), report-only until the first run is clean. Locally:
 *   E2E_BASE_URL=http://127.0.0.1:8000 npx playwright test tests/E2e/specs/a11y-axe.spec.ts
 * Report: var/a11y/axe-<page>.json, summary var/a11y/summary.json.
 */

const PAGES: ReadonlyArray<{ key: string; path: string; anonymous?: boolean }> = [
    { key: 'login', path: '/de/login', anonymous: true },
    { key: 'my-day', path: '/de/my-day' },
    { key: 'dashboard', path: '/de/dashboard' },
    { key: 'risks', path: '/de/risk' },
    { key: 'assets', path: '/de/asset' },
    { key: 'incidents', path: '/de/incident' },
    { key: 'soa', path: '/de/soa' },
    { key: 'documents', path: '/de/document' },
    { key: 'compliance', path: '/de/compliance' },
    { key: 'audit-log', path: '/de/admin/audit-log' },
];

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const OUT = resolve(__dirname, '..', '..', '..', 'var', 'a11y');
const SUMMARY = resolve(OUT, 'summary.json');

test.describe('axe WCAG 2.2 AA on core pages', () => {
    test.beforeAll(() => mkdirSync(OUT, { recursive: true }));

    for (const page of PAGES) {
        test(page.key, async ({ page: browserPage }) => {
            if (!page.anonymous) {
                await loginAs(browserPage, 'admin');
            }
            const response = await browserPage.goto(page.path, { waitUntil: 'networkidle' });
            expect(response?.status() ?? 0, `${page.path} answers`).toBeLessThan(400);

            const result = await new AxeBuilder({ page: browserPage }).withTags(TAGS).analyze();
            writeFileSync(resolve(OUT, `axe-${page.key}.json`), JSON.stringify(result.violations, null, 2));
            // A failed test restarts the worker, so the summary is merged on disk, not kept in memory.
            const summary = existsSync(SUMMARY) ? JSON.parse(readFileSync(SUMMARY, 'utf8')) : {};
            summary[page.key] = {
                violations: result.violations.length,
                nodes: result.violations.reduce((n, v) => n + v.nodes.length, 0),
                rules: result.violations.map((v) => `${v.impact ?? '?'}: ${v.id}`),
            };
            writeFileSync(SUMMARY, JSON.stringify(summary, null, 2));

            expect(
                result.violations.map((v) => `${v.id} (${v.impact}) × ${v.nodes.length}: ${v.help}`),
                `axe violations on ${page.path}`,
            ).toEqual([]);
        });
    }
});
