#!/usr/bin/env node
// Aggregates L2 scenario-results into a single HTML report.
//
// Inputs:  var/browser-coverage/scenario-results/*.json
// Output:  var/browser-coverage/scenario-report.html

import { readdirSync, readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = resolve(__dirname, '..', '..');
const RESULTS_DIR = resolve(PROJECT_ROOT, 'var/browser-coverage/scenario-results');
const REPORT_PATH = resolve(PROJECT_ROOT, 'var/browser-coverage/scenario-report.html');

if (!existsSync(RESULTS_DIR)) {
    console.error(`No scenario-results directory at ${RESULTS_DIR}.`);
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
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function badge(r) {
    if (r.skipReason) return `<span class="badge skip">skipped</span>`;
    if (!r.ok) return `<span class="badge fail">FAIL ${r.finalStatus ?? '✕'}</span>`;
    return `<span class="badge ok">${r.finalStatus ?? 'ok'}</span>`;
}

function renderPersona(p) {
    const groups = p.results.reduce((acc, r) => {
        (acc[r.category] ||= []).push(r);
        return acc;
    }, {});

    const body = Object.keys(groups).sort().map((cat) => {
        const rows = groups[cat].map((r) => `<tr class="${r.ok ? 'ok' : r.skipReason ? 'skip' : 'fail'}">
    <td>${badge(r)}</td>
    <td>${escape(r.label)}</td>
    <td><code>${escape(r.route)}</code></td>
    <td><code>${escape(new URL(r.finalUrl || 'http://x', 'http://x').pathname)}</code></td>
    <td>${r.durationMs} ms</td>
    <td>${escape(r.failReason ?? r.skipReason ?? '')}</td>
    <td>${r.consoleErrors.length > 0 ? `<details><summary>${r.consoleErrors.length}</summary><pre>${escape(r.consoleErrors.join('\n'))}</pre></details>` : ''}</td>
</tr>`).join('');
        return `<h3 class="cat">${escape(cat)} <small>(${groups[cat].length})</small></h3>
<table class="scenarios">
    <thead><tr><th>Status</th><th>Scenario</th><th>Form Route</th><th>After Submit</th><th>Latency</th><th>Fail / Skip reason</th><th>JS errors</th></tr></thead>
    <tbody>${rows}</tbody>
</table>`;
    }).join('');

    const okPct = p.total > 0 ? Math.round((p.ok / p.total) * 100) : 0;
    return `<section>
    <header>
        <h2>${escape(p.persona)}</h2>
        <p>
            <strong>${p.ok}/${p.total}</strong> ok (${okPct}%)
            · <strong>${p.failed}</strong> failed
            · <strong>${p.skipped}</strong> skipped
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
<title>L2 Scenario Report — Little ISMS Helper</title>
<style>
  body { font: 14px/1.5 -apple-system, system-ui, sans-serif; max-width: 1400px; margin: 2rem auto; padding: 0 1rem; color: #1c1f23; }
  h1 { margin-top: 0; }
  h2 { margin-top: 2rem; border-bottom: 1px solid #ddd; padding-bottom: .25rem; }
  h3.cat { margin-top: 1.5rem; color: #555; }
  table.scenarios { border-collapse: collapse; width: 100%; font-size: 12px; margin-bottom: 1rem; }
  table.scenarios th, table.scenarios td { padding: .35rem .5rem; border-bottom: 1px solid #f0f0f0; text-align: left; vertical-align: top; }
  table.scenarios tr.fail { background: #fff5f5; }
  table.scenarios tr.skip td:first-child { color: #92400e; }
  table.scenarios tr.ok td:first-child { color: #166534; }
  code { font: 12px ui-monospace, monospace; }
  pre { background: #f6f8fa; padding: .5rem; border-radius: 4px; overflow-x: auto; font-size: 11px; }
  .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; }
  .badge.ok { background: #dcfce7; color: #166534; }
  .badge.skip { background: #fef3c7; color: #92400e; }
  .badge.fail { background: #fee2e2; color: #991b1b; }
  details > summary { cursor: pointer; }
  header p { color: #555; }
  small { color: #888; font-weight: normal; }
</style>
</head>
<body>
<h1>L2 Scenario Coverage Report</h1>
<p><em>Form-fill smoke. Each scenario navigates the form, fills declared fields, submits, and asserts the post-submit state matches the YAML <code>expect</code> block.</em></p>
${personas.map(renderPersona).join('\n')}
</body>
</html>`;

mkdirSync(dirname(REPORT_PATH), { recursive: true });
writeFileSync(REPORT_PATH, html);
console.log(`L2 report written to ${REPORT_PATH}`);
console.log(`Personas: ${personas.map((p) => `${p.persona} (${p.ok}/${p.total} ok, ${p.failed} fail, ${p.skipped} skip)`).join(', ')}`);
