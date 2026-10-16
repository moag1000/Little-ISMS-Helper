import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import postcss from 'postcss';

/**
 * fairy-aurora-components.css once carried whole sections twice (P-9 builder
 * family, border-* utilities, btn-close, form-floating…). A repeated
 * selector in the same @media context that re-declares a property is dead
 * code at best and a silent override at worst. Disjoint split rules are only
 * allowed where merging would change the cascade (see ALLOWED_SPLITS).
 */
const FILE = path.resolve(__dirname, '../../../assets/styles/fairy-aurora-components.css');
// .lead: size/line-height stay before .h1–.h6/.card-text, colour after the
// Aurora-contrast utilities — merging would change `.card-text.lead`.
const ALLOWED_SPLITS = new Set([' || .lead']);

function groups() {
    const root = postcss.parse(fs.readFileSync(FILE, 'utf8'));
    const context = (node) => {
        const parts = [];
        for (let p = node.parent; p && p.type !== 'root'; p = p.parent) {
            if (p.type === 'atrule') parts.unshift(`@${p.name} ${p.params}`);
        }
        return parts.join(' > ');
    };
    const map = new Map();
    root.walkRules((rule) => {
        if (rule.parent.type === 'atrule' && /keyframes/.test(rule.parent.name)) return;
        const key = `${context(rule)} || ${rule.selector.split(',').map((s) => s.trim().replace(/\s+/g, ' ')).join(', ')}`;
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(rule);
    });
    return [...map].filter(([, rules]) => rules.length > 1);
}

describe('fairy-aurora-components.css', () => {
    it('declares no property twice for the same selector and context', () => {
        const overlaps = [];
        for (const [key, rules] of groups()) {
            const seen = new Map();
            for (const rule of rules) {
                rule.walkDecls((d) => {
                    if (d.parent !== rule) return;
                    if (seen.has(d.prop)) overlaps.push(`${key} → ${d.prop} (L${seen.get(d.prop)} and L${d.source.start.line})`);
                    seen.set(d.prop, d.source.start.line);
                });
            }
        }
        expect(overlaps).toEqual([]);
    });

    it('splits a selector over several rules only where the cascade needs it', () => {
        const splits = groups().map(([key]) => key).filter((key) => !ALLOWED_SPLITS.has(key));
        expect(splits).toEqual([]);
    });
});
