#!/usr/bin/env node
// Persona-driven screenshot capture for Little ISMS Helper.
//
// Usage:
//   SCREENSHOT_BASE_URL=http://localhost:8000 \
//   SCREENSHOT_USER=screenshots@local.test \
//   SCREENSHOT_PASS='...' \
//   node scripts/screenshots/capture.mjs [--persona=isb-practitioner] [--theme=light] [--screen=dashboard]
//
// Output: var/screenshots/<persona>/<theme>/<screen>.png

import { chromium } from 'playwright';
import { readFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import yaml from 'js-yaml';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = resolve(__dirname, '..', '..');
const CONFIG_PATH = resolve(__dirname, 'personas.yaml');
const OUTPUT_ROOT = resolve(PROJECT_ROOT, 'var', 'screenshots');
const STORAGE_STATE = resolve(PROJECT_ROOT, 'var', 'screenshots', '.auth-state.json');

const BASE_URL = process.env.SCREENSHOT_BASE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SCREENSHOT_USER || 'screenshots@local.test';
const PASS = process.env.SCREENSHOT_PASS || 'Screenshots-Aurora-2026!';
const HEADLESS = process.env.SCREENSHOT_HEADED !== '1';

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

async function login(context) {
    const page = await context.newPage();
    const loginUrl = new URL('/de/login', BASE_URL).toString();
    await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="_username"]', USER);
    await page.fill('input[name="_password"]', PASS);
    // Wait for the POST response so we can check the actual auth result
    // (Turbo/CSRF JS sometimes leaves the URL bar on /login even though the
    //  server returned a 302 to /dashboard).
    const [postResponse] = await Promise.all([
        page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/login')),
        page.click('button[type="submit"]'),
    ]);
    const postStatus = postResponse.status();
    const redirectTo = postResponse.headers().location || '';
    if (postStatus !== 302 || redirectTo.includes('/login')) {
        const errEl = await page.locator('.fa-alert--danger, .alert-danger, .invalid-feedback').first().textContent().catch(() => null);
        throw new Error(`Login failed (POST ${postStatus} → ${redirectTo || 'n/a'}). Error text: ${errEl ?? 'n/a'}. Check SCREENSHOT_USER/SCREENSHOT_PASS and run: php bin/console app:create-screenshot-user`);
    }
    // Wait for browser to follow the 302 (Turbo or normal nav) before doing anything else.
    await page.waitForURL(u => !u.toString().includes('/login'), { timeout: 10_000 }).catch(() => {});
    if (page.url().includes('/login') || page.url().includes('/mfa')) {
        throw new Error(`Post-login navigation failed: still on ${page.url()}. MFA may be required for this user.`);
    }
    await context.storageState({ path: STORAGE_STATE });
    await page.close();
    console.log(`  → logged in as ${USER}, session stored at ${STORAGE_STATE}`);
}

async function captureOne(browser, persona, theme, screen, viewport) {
    const context = await browser.newContext({
        viewport,
        storageState: STORAGE_STATE,
        locale: 'de-DE',
    });
    await context.addInitScript((t) => {
        try { localStorage.setItem('fa-theme', t); } catch (e) { /* no-op */ }
    }, theme);

    const page = await context.newPage();
    const url = new URL(screen.path, BASE_URL).toString();
    const outDir = resolve(OUTPUT_ROOT, persona, theme);
    ensureDir(outDir);
    const outFile = resolve(outDir, `${screen.name}.png`);

    let status = '?';
    try {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20_000 });
        status = resp ? resp.status() : 'no-response';
        await page.waitForSelector(screen.wait || 'body', { timeout: 10_000 }).catch(() => {});
        // give async charts/turbo a moment
        await page.waitForTimeout(800);
        // Hide Symfony Web Debug Toolbar + any dev-overlay before screenshot.
        await page.addStyleTag({
            content: `
                .sf-toolbar, .sf-minitoolbar,
                [id^="sfwdt"], [id^="sfMiniToolbar"], [id^="sfToolbarMainContent"],
                #sfwdt, #sfMiniToolbar, #sfToolbarMainContent,
                .sf-display-none { display: none !important; }
            `,
        }).catch(() => {});
        await page.screenshot({
            path: outFile,
            fullPage: screen.full_page !== false,
        });
        console.log(`  ✓ [${theme}] ${persona}/${screen.name} (${status}) → ${url}`);
    } catch (err) {
        console.log(`  ✗ [${theme}] ${persona}/${screen.name} → ${err.message}`);
    } finally {
        await context.close();
    }
}

function filterPersonas(config) {
    const wantedPersona = args.persona;
    const wantedTheme = args.theme;
    const wantedScreen = args.screen;

    const personas = Object.entries(config.personas)
        .filter(([k]) => !wantedPersona || k === wantedPersona);
    const themes = config.config.themes.filter(t => !wantedTheme || t === wantedTheme);

    return personas.flatMap(([personaKey, personaDef]) =>
        themes.flatMap(theme =>
            personaDef.screens
                .filter(s => !wantedScreen || s.name === wantedScreen)
                .map(screen => ({ personaKey, personaDef, theme, screen })),
        ),
    );
}

async function main() {
    const config = loadConfig();
    const viewport = config.config.viewport;
    const tasks = filterPersonas(config);
    if (tasks.length === 0) {
        console.error('No matching screens. Check --persona / --theme / --screen filters.');
        process.exit(1);
    }

    console.log(`Capturing ${tasks.length} screens (base: ${BASE_URL}, user: ${USER}).`);
    ensureDir(dirname(STORAGE_STATE));

    const browser = await chromium.launch({ headless: HEADLESS });
    const authContext = await browser.newContext({ viewport, locale: 'de-DE' });
    try {
        await login(authContext);
    } finally {
        await authContext.close();
    }

    const grouped = tasks.reduce((acc, t) => {
        (acc[t.personaKey] ??= []).push(t);
        return acc;
    }, {});

    for (const [personaKey, personaTasks] of Object.entries(grouped)) {
        console.log(`\n— ${personaKey} (${personaTasks[0].personaDef.label})`);
        for (const t of personaTasks) {
            await captureOne(browser, t.personaKey, t.theme, t.screen, viewport);
        }
    }

    await browser.close();
    console.log(`\nDone. Output: ${OUTPUT_ROOT}`);
}

main().catch(err => {
    console.error(err);
    process.exit(1);
});
