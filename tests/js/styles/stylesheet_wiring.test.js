import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Pages load the stylesheets as parallel <link> tags from
 * templates/_aurora_styles.html.twig; assets/styles.css mirrors the same list as
 * @imports. A sheet missing from the Twig list is never loaded in the app
 * (location-map.css shipped like that), and a different order changes the cascade.
 */
const ROOT = path.resolve(__dirname, '../../..');
const STYLES = path.join(ROOT, 'assets/styles');

const linked = [...fs.readFileSync(path.join(ROOT, 'templates/_aurora_styles.html.twig'), 'utf8')
    .matchAll(/'styles\/([\w-]+\.css)'/g)].map((m) => m[1]);
const imported = [...fs.readFileSync(path.join(ROOT, 'assets/styles.css'), 'utf8')
    .matchAll(/@import\s+['"]\.\/styles\/([\w-]+\.css)['"]/g)].map((m) => m[1]);
const onDisk = fs.readdirSync(STYLES).filter((f) => f.endsWith('.css')).sort();

describe('stylesheet wiring', () => {
    it('links every stylesheet in assets/styles/ exactly once', () => {
        expect([...linked].sort()).toEqual(onDisk);
    });

    it('keeps assets/styles.css in the same order as the <link> list', () => {
        expect(imported).toEqual(linked);
    });
});
