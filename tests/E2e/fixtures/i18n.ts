import type { Page } from '@playwright/test';
import { expect } from '@playwright/test';
import { readdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Untranslated-key guard for E2E specs.
 *
 * A leaked translation key renders as visible text shaped like
 * `policy_wizard.step.operational_baselines.access_review_cadence_label` — a
 * known translation-DOMAIN prefix followed by ≥2 dotted lowercase segments.
 * The domain set is derived from `translations/<domain>.de.yaml` filenames so
 * the guard tracks the real catalogue.
 *
 * Catches the orphan-i18n regression class (e.g. PR #851 — a template rendered
 * keys whose yaml entries were missing) on any page a spec visits.
 */

const PROJECT_ROOT = resolve(__dirname, '..', '..', '..');
const TRANSLATIONS_DIR = resolve(PROJECT_ROOT, 'translations');

function loadTranslationDomains(): string[] {
    try {
        return Array.from(
            new Set(
                readdirSync(TRANSLATIONS_DIR)
                    .map((f) => /^([a-z0-9_]+)\.(de|en)\.yaml$/.exec(f))
                    .filter((m): m is RegExpExecArray => m !== null)
                    .map((m) => m[1]),
            ),
        );
    } catch {
        return [];
    }
}

const TRANSLATION_DOMAINS = loadTranslationDomains();
const RAW_KEY_RE = TRANSLATION_DOMAINS.length > 0
    ? new RegExp(`\\b(?:${TRANSLATION_DOMAINS.join('|')})(?:\\.[a-z0-9_]+){2,}\\b`, 'g')
    : null;

/** Return the visible untranslated i18n keys on the current page (de-duped). */
export async function findRawKeys(page: Page): Promise<string[]> {
    if (RAW_KEY_RE === null) return [];
    let text = '';
    try {
        text = await page.locator('body').innerText({ timeout: 2000 });
    } catch {
        return [];
    }
    const hits = text.match(RAW_KEY_RE);
    return hits ? Array.from(new Set(hits)) : [];
}

/** Assert the current page shows no untranslated translation keys. */
export async function assertNoRawKeys(page: Page, context = ''): Promise<void> {
    const raw = await findRawKeys(page);
    expect(raw, `untranslated i18n keys on ${context || page.url()}`).toEqual([]);
}
