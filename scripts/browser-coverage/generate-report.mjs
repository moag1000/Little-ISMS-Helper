#!/usr/bin/env node
// Aggregates the per-persona JSON outputs of the L1 browser-smoke run into a
// single HTML report. Used by `npm run e2e:smoke:report` and the nightly CI.
//
// Inputs:  var/browser-coverage/results/*.json
// Output:  var/browser-coverage/report.html

import { readdirSync, readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = resolve(__dirname, '..', '..');
const RESULTS_DIR = resolve(PROJECT_ROOT, 'var/browser-coverage/results');
const REPORT_PATH = resolve(PROJECT_ROOT, 'var/browser-coverage/report.html');

if (!existsSync(RESULTS_DIR)) {
    console.error(`No results directory at ${RESULTS_DIR}. Run the L1 smoke spec first.`);
    process.exit(1);
}

const files = readdirSync(RESULTS_DIR)
    .filter((f) => f.endsWith('.json'))
    .map((f) => resolve(RESULTS_DIR, f));

if (files.length === 0) {
    console.error(`No JSON results in ${RESULTS_DIR}.`);
    process.exit(1);
}

const personas = files.map((file) => JSON.parse(readFileSync(file, 'utf8')));

function escape(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function statusBadge(r) {
    if (!r.ok) return `<span class="badge fail">${r.status ?? '✕'}</span>`;
    if (r.bannerSeen) return `<span class="badge warn">${r.status} module-off</span>`;
    if (r.consoleErrors.length > 0) return `<span class="badge warn">${r.status} console</span>`;
    return `<span class="badge ok">${r.status}</span>`;
}

function renderPersonaSection(p) {
    const rowsByCategory = p.results.reduce((acc, r) => {
        (acc[r.category] ||= []).push(r);
        return acc;
    }, {});
    const categories = Object.keys(rowsByCategory).sort();

    const body = categories
        .map((cat) => {
            const rows = rowsByCategory[cat]
                .map(
                    (r) => `<tr class="${r.ok ? 'ok' : 'fail'}">
        <td>${statusBadge(r)}</td>
        <td><code>${escape(r.path)}</code></td>
        <td>${escape(r.name)}</td>
        <td>${r.durationMs} ms</td>
        <td>${r.consoleErrors.length > 0 ? `<details><summary>${r.consoleErrors.length}</summary><pre>${escape(r.consoleErrors.join('\n'))}</pre></details>` : ''}</td>
        <td>${r.reason ? escape(r.reason) : ''}</td>
    </tr>`,
                )
                .join('');
            return `<h3 class="cat">${escape(cat)} <small>(${rowsByCategory[cat].length})</small></h3>
<table class="routes">
    <thead><tr><th>Status</th><th>Path</th><th>Route name</th><th>Latency</th><th>JS errors</th><th>Reason</th></tr></thead>
    <tbody>${rows}</tbody>
</table>`;
        })
        .join('');

    const okPct = p.total > 0 ? Math.round((p.ok / p.total) * 100) : 0;
    return `<section data-persona="${escape(p.persona)}">
    <header>
        <h2>${escape(p.label)} <small>(${escape(p.persona)})</small></h2>
        <p>
            <strong>${p.ok}/${p.total}</strong> ok
            (${okPct}%)
            · <strong>${p.failed}</strong> failed
            · <strong>${p.module_banner}</strong> module-off banner
            · ${escape(p.ran_at)}
        </p>
    </header>
    ${body}
</section>`;
}

const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>L1 Browser-Coverage Report — Little ISMS Helper</title>
<style>
  body { font: 14px/1.5 -apple-system, system-ui, sans-serif; max-width: 1200px; margin: 2rem auto; padding: 0 1rem; color: #1c1f23; }
  h1 { margin-top: 0; }
  h2 { margin-top: 2rem; border-bottom: 1px solid #ddd; padding-bottom: .25rem; }
  h3.cat { margin-top: 1.5rem; color: #555; }
  table.routes { border-collapse: collapse; width: 100%; font-size: 12px; margin-bottom: 1rem; }
  table.routes th, table.routes td { padding: .25rem .5rem; border-bottom: 1px solid #f0f0f0; text-align: left; vertical-align: top; }
  table.routes tr.fail { background: #fff5f5; }
  table.routes tr.ok td:first-child { color: #166534; }
  code { font: 12px ui-monospace, monospace; }
  pre { background: #f6f8fa; padding: .5rem; border-radius: 4px; overflow-x: auto; font-size: 11px; }
  .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; }
  .badge.ok { background: #dcfce7; color: #166534; }
  .badge.warn { background: #fef3c7; color: #92400e; }
  .badge.fail { background: #fee2e2; color: #991b1b; }
  details > summary { cursor: pointer; }
  header p { color: #555; }
  small { color: #888; font-weight: normal; }
</style>
</head>
<body>
<h1>L1 Browser-Coverage Report</h1>
<p><em>Persona-driven route-smoke navigation. Each persona is filtered against the route manifest; ok = HTTP &lt; 500 and no page-error.</em></p>
${personas.map(renderPersonaSection).join('\n')}
</body>
</html>`;

mkdirSync(dirname(REPORT_PATH), { recursive: true });
writeFileSync(REPORT_PATH, html);
console.log(`L1 report written to ${REPORT_PATH}`);
console.log(`Personas: ${personas.map((p) => `${p.persona} (${p.ok}/${p.total})`).join(', ')}`);
