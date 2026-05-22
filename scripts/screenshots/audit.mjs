#!/usr/bin/env node
// Persona-driven browser-console audit for Little ISMS Helper.
//
// Walks each persona's screen-catalog (same YAML as capture.mjs) and records:
//   - console.error / console.warn / console.log calls (JS-side)
//   - uncaught page errors (pageerror)
//   - failed network requests (requestfailed)
//   - HTTP responses with 4xx / 5xx status (excluding favicon/expected 404s)
//
// Output:
//   var/audit/console/<persona>/findings.json   (raw, machine-readable)
//   var/audit/console/<persona>/summary.md      (human-readable digest)
//   var/audit/console/index.md                  (cross-persona summary)
//
// Usage:
//   SCREENSHOT_BASE_URL=http://127.0.0.1:8000 \
//   SCREENSHOT_USER=screenshots@local.test \
//   SCREENSHOT_PASS='...' \
//   node scripts/screenshots/audit.mjs [--persona=isb-practitioner] [--screen=dashboard]

import { chromium } from 'playwright';
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import yaml from 'js-yaml';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = resolve(__dirname, '..', '..');
const CONFIG_PATH = resolve(__dirname, 'personas.yaml');
const OUTPUT_ROOT = resolve(PROJECT_ROOT, 'var', 'audit', 'console');
const STORAGE_STATE = resolve(PROJECT_ROOT, 'var', 'screenshots', '.auth-state.json');

const BASE_URL = process.env.SCREENSHOT_BASE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SCREENSHOT_USER || 'screenshots@local.test';
const PASS = process.env.SCREENSHOT_PASS || 'Screenshots-Aurora-2026!';
const HEADLESS = process.env.SCREENSHOT_HEADED !== '1';

// HTTP-status patterns we ignore (expected, not actionable).
const IGNORE_URLS = [
    /\/favicon\.ico$/,
    /\.well-known\/.+/,
    // Symfony WDT polls — known dev-only, no impact on real users.
    /\/_wdt\//,
    /\/_profiler\//,
];

// Console-message text we ignore (vendor noise we can't fix here).
const IGNORE_CONSOLE = [
    /^\[Vue warn\]/,  // not used here, future-proofing
    /Failed to load resource:.*sw\.js/,  // service worker dev noise
];

const args = Object.fromEntries(
    process.argv.slice(2)
        .filter(a => a.startsWith('--'))
        .map(a => {
            const [k, v = 'true'] = a.replace(/^--/, '').split('=');
            return [k, v];
        }),
);

function loadConfig() {
    return yaml.load(readFileSync(CONFIG_PATH, 'utf8'));
}

function ensureDir(path) {
    if (!existsSync(path)) mkdirSync(path, { recursive: true });
}

function shouldIgnoreUrl(url) {
    return IGNORE_URLS.some(re => re.test(url));
}

function shouldIgnoreConsole(text) {
    return IGNORE_CONSOLE.some(re => re.test(text));
}

async function login(context) {
    const page = await context.newPage();
    const loginUrl = new URL('/de/login', BASE_URL).toString();
    await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="_username"]', USER);
    await page.fill('input[name="_password"]', PASS);
    const [postResponse] = await Promise.all([
        page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/login')),
        page.click('button[type="submit"]'),
    ]);
    const postStatus = postResponse.status();
    const redirectTo = postResponse.headers().location || '';
    if (postStatus !== 302 || redirectTo.includes('/login')) {
        throw new Error(`Login failed (POST ${postStatus} → ${redirectTo}). Run: php bin/console app:create-screenshot-user`);
    }
    await page.waitForURL(u => !u.toString().includes('/login'), { timeout: 10_000 }).catch(() => {});
    await context.storageState({ path: STORAGE_STATE });
    await page.close();
    console.log(`  → logged in as ${USER}`);
}

async function auditOne(browser, persona, screen, viewport) {
    const context = await browser.newContext({
        viewport,
        storageState: STORAGE_STATE,
        locale: 'de-DE',
    });
    const page = await context.newPage();

    const findings = {
        persona,
        screen: screen.name,
        path: screen.path,
        url: '',
        httpStatus: null,
        consoleErrors: [],
        consoleWarnings: [],
        consoleLogs: [],
        pageErrors: [],
        failedRequests: [],
        badStatusResponses: [],
        durationMs: 0,
    };

    page.on('console', (msg) => {
        const text = msg.text();
        if (shouldIgnoreConsole(text)) return;
        const entry = {
            text,
            type: msg.type(),
            location: msg.location(),
        };
        if (msg.type() === 'error') findings.consoleErrors.push(entry);
        else if (msg.type() === 'warning') findings.consoleWarnings.push(entry);
        // skip info/log/debug to keep noise low — toggle if needed
    });

    page.on('pageerror', (err) => {
        findings.pageErrors.push({
            message: err.message,
            stack: err.stack?.split('\n').slice(0, 5).join('\n'),
        });
    });

    page.on('requestfailed', (req) => {
        const url = req.url();
        if (shouldIgnoreUrl(url)) return;
        findings.failedRequests.push({
            url,
            method: req.method(),
            failure: req.failure()?.errorText ?? 'unknown',
            resourceType: req.resourceType(),
        });
    });

    page.on('response', (resp) => {
        const status = resp.status();
        if (status < 400) return;
        const url = resp.url();
        if (shouldIgnoreUrl(url)) return;
        findings.badStatusResponses.push({
            url,
            status,
            method: resp.request().method(),
            resourceType: resp.request().resourceType(),
        });
    });

    const url = new URL(screen.path, BASE_URL).toString();
    findings.url = url;
    const t0 = Date.now();

    try {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45_000 });
        findings.httpStatus = resp ? resp.status() : null;
        await page.waitForSelector(screen.wait || 'body', { timeout: 10_000 }).catch(() => {});
        await page.waitForTimeout(800);  // let async charts/turbo + Stimulus init settle
    } catch (err) {
        findings.pageErrors.push({ message: `Navigation: ${err.message}` });
    } finally {
        findings.durationMs = Date.now() - t0;
        await context.close();
    }

    // Console line summary
    const errC = findings.consoleErrors.length + findings.pageErrors.length;
    const warnC = findings.consoleWarnings.length;
    const badC = findings.failedRequests.length + findings.badStatusResponses.length;
    const verdict = errC > 0 ? '✗' : (warnC + badC > 0 ? '~' : '✓');
    console.log(
        `  ${verdict} ${persona}/${screen.name} (${findings.httpStatus ?? '?'}, ${findings.durationMs}ms)  ` +
        `err:${errC} warn:${warnC} bad:${badC}`
    );

    return findings;
}

function filterTasks(config) {
    const wantedPersona = args.persona;
    const wantedScreen = args.screen;
    return Object.entries(config.personas)
        .filter(([k]) => !wantedPersona || k === wantedPersona)
        .flatMap(([personaKey, personaDef]) =>
            personaDef.screens
                .filter(s => !wantedScreen || s.name === wantedScreen)
                .map(screen => ({ personaKey, personaDef, screen }))
        );
}

function writePersonaReport(personaKey, personaDef, findings) {
    const personaDir = resolve(OUTPUT_ROOT, personaKey);
    ensureDir(personaDir);

    // Raw JSON
    writeFileSync(
        resolve(personaDir, 'findings.json'),
        JSON.stringify({ persona: personaKey, label: personaDef.label, findings }, null, 2),
    );

    // Markdown digest
    const md = [];
    md.push(`# Console-Audit: ${personaDef.label} (\`${personaKey}\`)`);
    md.push(``);
    md.push(`Generated: ${new Date().toISOString()}`);
    md.push(``);

    const errFindings = findings.filter(f => f.consoleErrors.length + f.pageErrors.length > 0);
    const warnFindings = findings.filter(f => f.consoleWarnings.length > 0);
    const badNetFindings = findings.filter(f => f.failedRequests.length + f.badStatusResponses.length > 0);

    md.push(`## Summary`);
    md.push(``);
    md.push(`- Screens audited: **${findings.length}**`);
    md.push(`- Screens with JS errors: **${errFindings.length}**`);
    md.push(`- Screens with warnings: **${warnFindings.length}**`);
    md.push(`- Screens with failed network requests / 4xx-5xx: **${badNetFindings.length}**`);
    md.push(``);

    if (errFindings.length > 0) {
        md.push(`## JS errors (HIGH severity)`);
        md.push(``);
        for (const f of errFindings) {
            md.push(`### \`${f.screen}\` — ${f.path}`);
            md.push(``);
            for (const err of f.pageErrors) {
                md.push(`- **PageError:** ${err.message}`);
                if (err.stack) md.push(`  \`\`\`\n  ${err.stack}\n  \`\`\``);
            }
            for (const e of f.consoleErrors) {
                md.push(`- **console.error:** ${e.text}`);
                if (e.location?.url) md.push(`  - at \`${e.location.url}:${e.location.lineNumber}\``);
            }
            md.push(``);
        }
    }

    if (badNetFindings.length > 0) {
        md.push(`## Failed / bad-status network requests`);
        md.push(``);
        for (const f of badNetFindings) {
            md.push(`### \`${f.screen}\` — ${f.path}`);
            md.push(``);
            for (const r of f.failedRequests) {
                md.push(`- **${r.method}** \`${r.url}\` — failed: ${r.failure} (${r.resourceType})`);
            }
            for (const r of f.badStatusResponses) {
                md.push(`- **${r.method}** \`${r.url}\` — HTTP **${r.status}** (${r.resourceType})`);
            }
            md.push(``);
        }
    }

    if (warnFindings.length > 0) {
        md.push(`## Warnings (LOW priority)`);
        md.push(``);
        for (const f of warnFindings) {
            md.push(`### \`${f.screen}\``);
            for (const w of f.consoleWarnings) md.push(`- ${w.text}`);
            md.push(``);
        }
    }

    if (errFindings.length === 0 && warnFindings.length === 0 && badNetFindings.length === 0) {
        md.push(`✓ **No issues found.**`);
        md.push(``);
    }

    writeFileSync(resolve(personaDir, 'summary.md'), md.join('\n'));
}

function writeIndex(allReports) {
    const md = [];
    md.push(`# Console Audit — Cross-Persona Summary`);
    md.push(``);
    md.push(`Generated: ${new Date().toISOString()}`);
    md.push(`Base URL: ${BASE_URL}`);
    md.push(``);
    md.push(`| Persona | Screens | Errors | Warnings | Bad Network |`);
    md.push(`|---|---:|---:|---:|---:|`);
    let totals = { screens: 0, errors: 0, warnings: 0, bad: 0 };
    for (const r of allReports) {
        const errC = r.findings.reduce((a, f) => a + f.consoleErrors.length + f.pageErrors.length, 0);
        const warnC = r.findings.reduce((a, f) => a + f.consoleWarnings.length, 0);
        const badC = r.findings.reduce((a, f) => a + f.failedRequests.length + f.badStatusResponses.length, 0);
        totals.screens += r.findings.length;
        totals.errors += errC;
        totals.warnings += warnC;
        totals.bad += badC;
        md.push(`| [${r.personaDef.label}](${r.personaKey}/summary.md) | ${r.findings.length} | ${errC} | ${warnC} | ${badC} |`);
    }
    md.push(`| **Total** | **${totals.screens}** | **${totals.errors}** | **${totals.warnings}** | **${totals.bad}** |`);
    md.push(``);
    md.push(`See per-persona \`<persona>/summary.md\` for actionable findings.`);
    md.push(`Raw JSON in \`<persona>/findings.json\` for tooling.`);
    writeFileSync(resolve(OUTPUT_ROOT, 'index.md'), md.join('\n'));
}

async function main() {
    const config = loadConfig();
    const viewport = config.config.viewport;
    const tasks = filterTasks(config);
    if (tasks.length === 0) {
        console.error('No matching screens. Check --persona / --screen filters.');
        process.exit(1);
    }
    console.log(`Console-auditing ${tasks.length} screens (base: ${BASE_URL}, user: ${USER}).`);
    ensureDir(OUTPUT_ROOT);
    ensureDir(dirname(STORAGE_STATE));

    const browser = await chromium.launch({ headless: HEADLESS });
    const authContext = await browser.newContext({ viewport, locale: 'de-DE' });
    try {
        await login(authContext);
    } finally {
        await authContext.close();
    }

    const byPersona = tasks.reduce((acc, t) => {
        (acc[t.personaKey] ??= { def: t.personaDef, tasks: [] }).tasks.push(t);
        return acc;
    }, {});

    const allReports = [];
    for (const [personaKey, { def, tasks: pTasks }] of Object.entries(byPersona)) {
        console.log(`\n— ${personaKey} (${def.label})`);
        const findings = [];
        for (const t of pTasks) {
            findings.push(await auditOne(browser, personaKey, t.screen, viewport));
        }
        writePersonaReport(personaKey, def, findings);
        allReports.push({ personaKey, personaDef: def, findings });
    }
    writeIndex(allReports);

    await browser.close();
    console.log(`\nDone. Reports: ${OUTPUT_ROOT}/index.md`);
}

main().catch(err => {
    console.error(err);
    process.exit(1);
});
