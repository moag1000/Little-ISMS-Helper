/**
 * FairyAurora v3.0 — Chart.js Theme-Modul (Plan § 16)
 *
 * Zentrale Source-of-Truth für alle Chart.js-Instanzen. Liest Aurora-Tokens
 * aus CSS-Custom-Properties (respektiert Light/Dark/System-Mode automatisch).
 *
 * Usage in Stimulus-Controllers:
 *   import { readTokens, applyAuroraDefaults, paletteFor, patternFor } from '../chart-theme.js';
 *   applyAuroraDefaults();
 *   const colors = paletteFor(5);   // Array mit 5 Hex-Werten aus aktueller Palette
 *
 * Dark-Mode-Switch: Aurora-Init-Script wechselt html[data-theme], CSS-Vars
 * werden neu resolved. Für Chart.js: chart.update('none') nach Mode-Switch,
 * kein Re-Render nötig.
 */

const TOKEN_NAMES = [
    '--primary', '--accent', '--success', '--warning', '--danger',
    '--fairy-aura', '--fg', '--fg-2', '--fg-3', '--border', '--surface',
    '--surface-2', '--primary-glow',
];

const CACHE = { _version: null };

/**
 * Liest die aktuellen Aurora-Tokens aus :root via getComputedStyle.
 * Liefert ein Map { '--primary': '#0284c7', ... } zurück.
 */
export function readTokens() {
    const style = getComputedStyle(document.documentElement);
    const out = {};
    for (const name of TOKEN_NAMES) {
        const v = style.getPropertyValue(name).trim();
        if (v) out[name] = v;
    }
    return out;
}

/**
 * 5-Slot-Series-Palette nach Plan § 16.
 * count=1..5 → gibt n Farben zurück.
 * Bei count > 5 → rotiert mit abnehmender Alpha (100→70→50 %).
 */
export function paletteFor(count = 5) {
    const t = readTokens();
    const base = [
        t['--primary'],
        t['--accent'],
        t['--success'],
        t['--warning'],
        t['--fairy-aura'],
    ];
    if (count <= 5) return base.slice(0, count);
    const extra = [];
    for (let i = 5; i < count; i++) {
        const slot = base[i % 5];
        const alpha = i < 10 ? 0.7 : 0.5;
        extra.push(rgbaFromHex(slot, alpha));
    }
    return [...base, ...extra];
}

/**
 * Dash-Pattern pro Series für Color-Deficit-Safety (Plan § 16).
 * Nur aktiv bei CSS-Class `.chart-pattern-safe` oder prefers-contrast: more.
 */
export function patternFor(index) {
    const patterns = [
        [],                // solid
        [6, 3],            // dash
        [2, 2],            // dot
        [8, 3, 2, 3],      // dash-dot
        [10, 5],           // long-dash
    ];
    return patterns[index % patterns.length];
}

/**
 * Setzt Chart.js-Global-Defaults auf Aurora-Style.
 * Ruft auf vor jeder `new Chart(...)`-Instanziierung.
 */
export function applyAuroraDefaults(Chart) {
    if (!Chart || !Chart.defaults) return;
    const t = readTokens();

    Chart.defaults.font.family = 'Inter, -apple-system, BlinkMacSystemFont, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = t['--fg-2'] || '#4c4a73';
    Chart.defaults.borderColor = t['--border'] || '#dfe3f0';
    // NOTE: Do NOT set Chart.defaults.backgroundColor = 'transparent' here.
    // That global default overrides arc-element colors in pie/doughnut charts,
    // making segments invisible. Dataset-level backgroundColor values are used instead.

    // Grid
    if (Chart.defaults.scales) {
        Object.values(Chart.defaults.scales).forEach(scale => {
            if (!scale.grid) scale.grid = {};
            scale.grid.color = t['--border'];
            scale.grid.borderDash = [2, 4];
            scale.grid.drawBorder = false;

            if (!scale.ticks) scale.ticks = {};
            scale.ticks.color = t['--fg-3'];
            scale.ticks.font = {
                family: 'JetBrains Mono, ui-monospace, monospace',
                size: 10,
            };
        });
    }

    // Tooltip
    if (Chart.defaults.plugins?.tooltip) {
        Object.assign(Chart.defaults.plugins.tooltip, {
            backgroundColor: t['--surface-2'] || '#eef0f9',
            titleColor: t['--fg'] || '#1e1b4b',
            bodyColor: t['--fg-2'] || '#4c4a73',
            borderColor: t['--border'] || '#dfe3f0',
            borderWidth: 1,
            cornerRadius: 6,
            padding: 10,
            titleFont: { family: 'Inter, sans-serif', size: 12, weight: '600' },
            bodyFont:  { family: 'Inter, sans-serif', size: 12, weight: '400' },
        });
    }

    // Legend
    if (Chart.defaults.plugins?.legend) {
        Object.assign(Chart.defaults.plugins.legend, {
            labels: {
                color: t['--fg-2'] || '#4c4a73',
                font: { family: 'Inter, sans-serif', size: 12 },
                usePointStyle: true,
                pointStyle: 'circle',
                padding: 12,
            },
        });
    }

    // Line / Bar specifics
    if (Chart.defaults.elements?.line) {
        Chart.defaults.elements.line.tension = 0.3;
        Chart.defaults.elements.line.borderWidth = 2;
    }
    if (Chart.defaults.elements?.point) {
        Chart.defaults.elements.point.radius = 0;
        Chart.defaults.elements.point.hoverRadius = 4;
    }
    if (Chart.defaults.elements?.bar) {
        Chart.defaults.elements.bar.borderRadius = 4;
        Chart.defaults.elements.bar.borderSkipped = 'bottom';
    }
}

/**
 * Build complete dataset-configs for a chart.
 * series = [{ label, data, tone?: 'primary'|'accent'|..., patternSafe?: bool }]
 */
export function buildDatasets(series, { patternSafe = false, fillArea = false } = {}) {
    const t = readTokens();
    const tones = {
        primary:    t['--primary'],
        accent:     t['--accent'],
        success:    t['--success'],
        warning:    t['--warning'],
        'fairy-aura': t['--fairy-aura'],
        danger:     t['--danger'],
    };
    return series.map((s, i) => {
        const color = s.tone ? tones[s.tone] : paletteFor(series.length)[i];
        return {
            label: s.label || `Series ${i + 1}`,
            data: s.data || [],
            borderColor: color,
            backgroundColor: fillArea ? rgbaFromHex(color, 0.3) : color,
            fill: fillArea,
            tension: 0.3,
            borderDash: patternSafe ? patternFor(i) : [],
            ...s.extra,
        };
    });
}

/**
 * Hook up Chart to re-read tokens on theme-switch (Plan § 17).
 * Call once after instantiation; keeps chart in sync.
 */
export function subscribeToThemeChanges(chart) {
    const observer = new MutationObserver(() => {
        applyAuroraDefaults(chart.constructor);
        chart.update('none');
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    return observer;
}

function rgbaFromHex(hex, alpha) {
    const clean = hex.replace('#', '').trim();
    if (clean.length !== 6) return hex;
    const r = parseInt(clean.substring(0, 2), 16);
    const g = parseInt(clean.substring(2, 4), 16);
    const b = parseInt(clean.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}
