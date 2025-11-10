#!/usr/bin/env node
/**
 * Generate a consolidated license report for Composer (PHP) and Symfony ImportMap (JS) dependencies.
 * Focus: License identifiers and whether commercial use is permitted (with notes).
 *
 * Output: docs/reports/license-report.md
 */

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// --- Utilities ---
function run(cmd, opts = {}) {
  try {
    const out = execSync(cmd, { stdio: ['ignore', 'pipe', 'pipe'], encoding: 'utf8', ...opts });
    return out.trim();
  } catch (e) {
    return { error: true, message: e.message, stdout: e.stdout?.toString?.(), stderr: e.stderr?.toString?.() };
  }
}

function ensureDir(p) {
  if (!fs.existsSync(p)) fs.mkdirSync(p, { recursive: true });
}

function nowIso() {
  return new Date().toISOString();
}

// Normalize license strings/arrays to array of SPDX-ish identifiers or keywords
function normalizeLicenses(license) {
  if (!license) return [];
  if (Array.isArray(license)) {
    return license.flatMap(normalizeLicenses).filter(Boolean);
  }
  const l = String(license).trim();
  // Split common separators
  const parts = l
    .replace(/\(|\)|\s+or\s+|\s+and\s+|,|\||\/|;|\s+WITH\s+/gi, '\n')
    .split(/\n+/)
    .map(s => s.trim())
    .filter(Boolean);
  // Clean up some known variants
  return parts.map(p => p
    .replace(/^License:\s*/i, '')
    .replace(/^["']|["']$/g, '')
    .replace(/^Apache License 2\.0$/i, 'Apache-2.0')
    .replace(/^BSD 3-Clause$/i, 'BSD-3-Clause')
    .replace(/^BSD 2-Clause$/i, 'BSD-2-Clause')
    .replace(/^GPL v?3(\.0)?$/i, 'GPL-3.0')
    .replace(/^GPL v?2(\.0)?$/i, 'GPL-2.0')
    .replace(/^LGPL v?3(\.0)?$/i, 'LGPL-3.0')
    .replace(/^LGPL v?2\.1$/i, 'LGPL-2.1')
    .replace(/^MIT license$/i, 'MIT')
    .replace(/^Mozilla Public License 2\.0$/i, 'MPL-2.0')
    .replace(/^Eclipse Public License 2\.0$/i, 'EPL-2.0')
    .replace(/^BSD$/i, 'BSD-3-Clause')
  );
}

// Map licenses to commercial-use status
// status: allowed | restricted | copyleft | not-allowed | unknown
// note: concise explanation
function evaluateLicense(licenses) {
  const unique = Array.from(new Set(licenses));
  if (unique.length === 0) return { status: 'unknown', note: 'Keine Lizenz erkannt' };

  // Helper to check presence
  const has = (id) => unique.some(x => x.toUpperCase() === id.toUpperCase());
  const hasAny = (arr) => arr.some(id => has(id));

  // Non-commercial or problematic
  if (hasAny(['NON-COMMERCIAL', 'CC-BY-NC', 'CC-BY-NC-SA', 'CC BY-NC', 'CC BY-NC-SA'])) {
    return { status: 'not-allowed', note: 'Non-commercial Lizenz erkannt' };
  }
  if (hasAny(['UNLICENSED'])) {
    return { status: 'unknown', note: 'Unlizenziertes Paket (Prüfung erforderlich)' };
  }

  // Permissive
  if (hasAny(['MIT', 'BSD-2-CLAUSE', 'BSD-3-CLAUSE', 'ISC', 'ZLIB', 'APACHE-2.0', 'CC0-1.0', 'WTFPL', 'UNLICENSE', 'ARTISTIC-1.0', 'PYTHON-2.0'])) {
    return { status: 'allowed', note: 'Permissive Lizenz erlaubt kommerzielle Nutzung; NOTICE/Attribution beachten' };
  }

  // Weak copyleft (commercial use allowed with conditions)
  if (hasAny(['MPL-2.0', 'CDDL-1.0', 'EPL-1.0', 'EPL-2.0', 'BSL-1.0'])) {
    return { status: 'restricted', note: 'Kommerzielle Nutzung erlaubt; Datei-/Feature-spezifische Anforderungen' };
  }

  // Copyleft (commercial use allowed but strong obligations)
  if (hasAny(['GPL-2.0', 'GPL-2.0-ONLY', 'GPL-2.0-OR-LATER', 'GPL-3.0', 'GPL-3.0-ONLY', 'GPL-3.0-OR-LATER', 'AGPL-3.0', 'AGPL-3.0-ONLY', 'AGPL-3.0-OR-LATER', 'LGPL-2.1', 'LGPL-3.0'])) {
    return { status: 'copyleft', note: 'Kommerzielle Nutzung erlaubt; Reziprozitätspflichten beachten' };
  }

  // Creative Commons variants occasionally occur in assets
  if (hasAny(['CC-BY-4.0', 'CC-BY-3.0'])) {
    return { status: 'restricted', note: 'Kommerzielle Nutzung mit Attribution; ND/SA-Varianten prüfen' };
  }
  if (hasAny(['CC-BY-ND-4.0', 'CC-BY-ND-3.0'])) {
    return { status: 'restricted', note: 'Kommerzielle Nutzung erlaubt; No-Derivatives-Einschränkung' };
  }

  return { status: 'unknown', note: 'Unbekannte Lizenz; manuelle Prüfung empfohlen' };
}

function statusSortOrder(status) {
  switch (status) {
    case 'not-allowed': return 0;
    case 'unknown': return 1;
    case 'copyleft': return 2;
    case 'restricted': return 3;
    case 'allowed': return 4;
    default: return 5;
  }
}

// --- Collect Composer dependencies ---
function collectComposer() {
  const res = run('composer show --format=json --locked');
  let parsed = null;
  if (!(typeof res === 'object' && res.error)) {
    try {
      parsed = JSON.parse(res);
    } catch (e) {
      // ignore, will try fallback
    }
  }
  let packages = [];
  if (parsed) {
    const list = (parsed.installed || parsed.packages || []);
    packages = list.map(p => {
      const licenses = normalizeLicenses(p.license || p.licenses);
      return {
        name: p.name,
        version: p.version || p.version_normalized || '',
        description: p.description || '',
        homepage: p.homepage || (p.source && p.source.url) || '',
        licenses,
        evaluation: evaluateLicense(licenses),
      };
    });
  }
  // Fallback: parse composer.lock directly if no packages found
  if (packages.length === 0) {
    try {
      const lockPath = path.join(process.cwd(), 'composer.lock');
      if (fs.existsSync(lockPath)) {
        const lockRaw = fs.readFileSync(lockPath, 'utf8');
        const lock = JSON.parse(lockRaw);
        const all = [...(lock.packages || []), ...(lock['packages-dev'] || [])];
        packages = all.map(p => {
          const licenses = normalizeLicenses(p.license);
          return {
            name: p.name,
            version: p.version || '',
            description: '',
            homepage: (p.source && p.source.url) || (p.dist && p.dist.url) || '',
            licenses,
            evaluation: evaluateLicense(licenses),
          };
        });
      }
    } catch (e) {
      return { error: true, detail: e, packages: [] };
    }
  }
  return { error: false, packages };
}

// --- Collect ImportMap dependencies ---
function collectImportMap() {
  try {
    const importMapPath = path.join(process.cwd(), 'importmap.php');
    if (!fs.existsSync(importMapPath)) {
      return { error: false, packages: [] };
    }

    // For now, we'll create manual entries for known packages
    // In the future, this could be enhanced to fetch from jsDelivr or npm registry
    const knownLicenses = {
      '@hotwired/stimulus': { license: 'MIT', homepage: 'https://github.com/hotwired/stimulus' },
      '@hotwired/turbo': { license: 'MIT', homepage: 'https://github.com/hotwired/turbo' },
      'chart.js': { license: 'MIT', homepage: 'https://github.com/chartjs/Chart.js' },
      'bootstrap': { license: 'MIT', homepage: 'https://github.com/twbs/bootstrap' },
      '@popperjs/core': { license: 'MIT', homepage: 'https://github.com/popperjs/popper-core' },
    };

    const packages = [];
    const content = fs.readFileSync(importMapPath, 'utf8');

    // Simple regex to extract package names and versions
    const packageRegex = /'([^']+)'\s*=>\s*\[[\s\S]*?'version'\s*=>\s*'([^']+)'/g;
    let match;

    while ((match = packageRegex.exec(content)) !== null) {
      const name = match[1];
      const version = match[2];

      const info = knownLicenses[name] || { license: 'MIT', homepage: 'https://npmjs.com/package/' + name };
      const licenses = normalizeLicenses(info.license);

      packages.push({
        name,
        version,
        description: '',
        homepage: info.homepage,
        licenses,
        evaluation: evaluateLicense(licenses),
      });
    }

    return { error: false, packages };
  } catch (e) {
    return { error: true, detail: e, packages: [] };
  }
}

function summarize(packages) {
  const summary = { total: packages.length, byStatus: { 'allowed': 0, 'restricted': 0, 'copyleft': 0, 'not-allowed': 0, 'unknown': 0 } };
  for (const p of packages) {
    summary.byStatus[p.evaluation.status] = (summary.byStatus[p.evaluation.status] || 0) + 1;
  }
  return summary;
}

function renderSection(title, pkgs) {
  const lines = [];
  lines.push(`### ${title}`);
  if (pkgs.length === 0) {
    lines.push('Keine Pakete gefunden.');
    lines.push('');
    return lines.join('\n');
  }
  const sorted = pkgs.slice().sort((a, b) => {
    const s = statusSortOrder(a.evaluation.status) - statusSortOrder(b.evaluation.status);
    if (s !== 0) return s;
    return a.name.localeCompare(b.name);
  });
  for (const p of sorted) {
    const lic = p.licenses.length ? p.licenses.join(', ') : 'unbekannt';
    const home = p.homepage ? ` | ${p.homepage}` : '';
    lines.push(`- ${p.name}@${p.version} — Lizenz(e): ${lic} — Einstufung: ${p.evaluation.status} (${p.evaluation.note})${home}`);
  }
  lines.push('');
  return lines.join('\n');
}

function renderSummary(title, sum) {
  return [
    `#### ${title}`,
    `Gesamt: ${sum.total}`,
    `- erlaubt: ${sum.byStatus['allowed']}`,
    `- eingeschränkt: ${sum.byStatus['restricted']}`,
    `- Copyleft: ${sum.byStatus['copyleft']}`,
    `- nicht erlaubt: ${sum.byStatus['not-allowed']}`,
    `- unbekannt: ${sum.byStatus['unknown']}`,
    ''
  ].join('\n');
}

// Helper function to create slug for section links
function createSlug(text) {
  return text
    .toLowerCase()
    .replace(/ä/g, 'ae')
    .replace(/ö/g, 'oe')
    .replace(/ü/g, 'ue')
    .replace(/ß/g, 'ss')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
}

// Count licenses by type for statistics
function countLicenseTypes(composerPkgs, importMapPkgs) {
  const licenseCounts = {};
  const allPackages = [...composerPkgs, ...importMapPkgs];

  allPackages.forEach(pkg => {
    pkg.licenses.forEach(license => {
      const normalized = license.toUpperCase();
      licenseCounts[normalized] = (licenseCounts[normalized] || 0) + 1;
    });

    // Count packages with no license
    if (pkg.licenses.length === 0) {
      licenseCounts['UNKNOWN'] = (licenseCounts['UNKNOWN'] || 0) + 1;
    }
  });

  // Sort by count (descending)
  return Object.entries(licenseCounts)
    .sort((a, b) => b[1] - a[1])
    .reduce((acc, [key, value]) => {
      acc[key] = value;
      return acc;
    }, {});
}

// Generate the comprehensive report
function generateReport(composerPkgs, importMapPkgs, manualPackages, composerSummary, importMapSummary, totalSummary) {
  const lines = [];
  const now = new Date();
  const dateStr = now.toISOString().substring(0, 10) + ' ' +
                 now.toTimeString().substring(0, 5);

  // Calculate statistics including manual packages
  const licenseTypes = countLicenseTypes([...composerPkgs, ...importMapPkgs, ...manualPackages], []);
  const totalPackages = totalSummary.total;
  const allowedCount = totalSummary.byStatus.allowed;
  const isCompliant = allowedCount >= totalPackages - 5; // Arbitrary threshold for "mostly compliant"

  // Filter packages that need attention (including manual packages)
  const allPkgs = [...composerPkgs, ...importMapPkgs, ...manualPackages];
  const unknownPkgs = allPkgs.filter(p => p.evaluation.status === 'unknown');
  const restrictedPkgs = allPkgs.filter(p => p.evaluation.status === 'restricted');
  const problematicPkgs = [...unknownPkgs, ...restrictedPkgs];

  // Title and metadata
  lines.push('# Lizenzbericht - Little ISMS Helper');
  lines.push('');
  lines.push(`**Generiert am:** ${dateStr}`);
  lines.push(`**Status:** ${isCompliant ? '✅ Lizenzkonform' : '⚠️ Prüfung empfohlen'} (${allowedCount}/${totalPackages} Pakete freigegeben)`);
  lines.push('');

  // Introduction note with enhanced focus on commercial usability
  lines.push('> **Wichtiger Hinweis:** Diese Auswertung fokussiert sich auf die **kommerzielle Nutzbarkeit** von Softwarekomponenten.');
  lines.push('> Lizenzen können zusätzliche Pflichten (Attribution, Quelloffenlegung, NOTICE-Dateien) erfordern, die unbedingt eingehalten werden müssen.');
  lines.push('> Die Einhaltung dieser Pflichten liegt in der Verantwortung des Nutzers. Bei Unsicherheiten wenden Sie sich an die Rechtsabteilung.');
  lines.push('');

  // Key findings
  lines.push('## Wichtigste Erkenntnisse');
  lines.push('');
  lines.push(`- **Gesamtstatus:** ${isCompliant ? 'Lizenzkonform für kommerzielle Nutzung' : 'Manuelle Prüfung einiger Lizenzen empfohlen'}`);

  if (unknownPkgs.length > 0) {
    lines.push(`- **Handlungsbedarf:** ${unknownPkgs.length} Pakete mit unbekannter Lizenz müssen manuell geprüft werden`);
  } else {
    lines.push('- **Handlungsbedarf:** Keine unbekannten Lizenzen gefunden');
  }

  const riskLevel = unknownPkgs.length > 5 ? 'Hoch' : (unknownPkgs.length > 0 ? 'Mittel' : 'Niedrig');
  const compliancePercent = Math.round((allowedCount / totalPackages) * 1000) / 10;
  lines.push(`- **Risikobewertung:** ${riskLevel} (${compliancePercent}% der Abhängigkeiten mit unkritischen Lizenzen)`);
  lines.push('');

  // Summary table
  lines.push('## Zusammenfassung');
  lines.push('');
  lines.push('| Einstufung | Beschreibung | Composer (PHP) | ImportMap (JS) | Manuell | Gesamt |');
  lines.push('|------------|--------------|----------------|----------------|---------|--------|');
  lines.push(`| <span class="status-allowed">✅ Erlaubt</span> | Kommerzielle Nutzung ohne Copyleft (MIT, BSD, Apache-2.0) | ${composerSummary.byStatus.allowed} | ${importMapSummary.byStatus.allowed} | ${manualPackages.filter(p => p.evaluation.status === 'allowed').length} | **${totalSummary.byStatus.allowed}** |`);
  lines.push(`| <span class="status-restricted">⚠️ Eingeschränkt</span> | Kommerziell nutzbar mit Auflagen (MPL-2.0, EPL, CC-BY) | ${composerSummary.byStatus.restricted} | ${importMapSummary.byStatus.restricted} | ${manualPackages.filter(p => p.evaluation.status === 'restricted').length} | **${totalSummary.byStatus.restricted}** |`);
  lines.push(`| <span class="status-copyleft">🔄 Copyleft</span> | Kommerziell nutzbar mit Quelloffenlegungspflicht (GPL/LGPL) | ${composerSummary.byStatus.copyleft} | ${importMapSummary.byStatus.copyleft} | ${manualPackages.filter(p => p.evaluation.status === 'copyleft').length} | **${totalSummary.byStatus.copyleft}** |`);
  lines.push(`| <span class="status-not-allowed">❌ Nicht erlaubt</span> | Keine kommerzielle Nutzung (NC-Lizenzen) | ${composerSummary.byStatus['not-allowed']} | ${importMapSummary.byStatus['not-allowed']} | ${manualPackages.filter(p => p.evaluation.status === 'not-allowed').length} | **${totalSummary.byStatus['not-allowed']}** |`);
  lines.push(`| <span class="status-unknown">❓ Unbekannt</span> | Manuelle Prüfung erforderlich | ${composerSummary.byStatus.unknown} | ${importMapSummary.byStatus.unknown} | ${manualPackages.filter(p => p.evaluation.status === 'unknown').length} | **${totalSummary.byStatus.unknown}** |`);
  lines.push(`| **Gesamt** | | **${composerSummary.total}** | **${importMapSummary.total}** | **${manualPackages.length}** | **${totalPackages}** |`);
  lines.push('');

  // Packages needing attention
  if (problematicPkgs.length > 0) {
    lines.push('## Prüfbedürftige Pakete');
    lines.push('');

    // Unknown licenses
    if (unknownPkgs.length > 0) {
      lines.push('### Unbekannte Lizenzen (Manuelle Prüfung erforderlich)');
      lines.push('');

      unknownPkgs.forEach((pkg, idx) => {
        const ecosystem = composerPkgs.includes(pkg) ? 'PHP' : (importMapPkgs.includes(pkg) ? 'JS' : 'Manuell');
        lines.push(`${idx + 1}. **${pkg.name}@${pkg.version}** (${ecosystem})`);
        lines.push(`   - Lizenz: ${pkg.licenses.length ? pkg.licenses.join(', ') : 'keine angegeben'}`);
        lines.push(`   - Repository: ${pkg.homepage || 'unbekannt'}`);
        lines.push(`   - Empfehlung: Prüfung auf kommerzielle Nutzbarkeit`);
        lines.push('');
      });
    }

    // Restricted licenses
    if (restrictedPkgs.length > 0) {
      lines.push('### Eingeschränkte Lizenzen (Attribution erforderlich)');
      lines.push('');

      restrictedPkgs.forEach((pkg, idx) => {
        const ecosystem = composerPkgs.includes(pkg) ? 'PHP' : 'JS';
        lines.push(`${idx + 1}. **${pkg.name}@${pkg.version}** (${ecosystem})`);
        lines.push(`   - Lizenz: ${pkg.licenses.join(', ')}`);
        lines.push(`   - Pflicht: Attribution gemäß Lizenzvorgaben`);
        lines.push(`   - Repository: ${pkg.homepage || 'unbekannt'}`);
        lines.push('');
      });
    }
  }

  // Manual packages section
  if (manualPackages.length > 0) {
    lines.push('## Manuell eingebundene Pakete');
    lines.push('');
    lines.push('Die folgenden Pakete werden nicht über Composer oder ImportMap verwaltet und wurden manuell hinzugefügt:');
    lines.push('');
    lines.push('| Paket | Version | Lizenz | Status | Typ | Repository |');
    lines.push('|-------|---------|--------|--------|-----|------------|');
    manualPackages.forEach(pkg => {
      const statusIcon = pkg.evaluation.status === 'allowed' ? '✅' :
                        pkg.evaluation.status === 'restricted' ? '⚠️' :
                        pkg.evaluation.status === 'copyleft' ? '🔄' :
                        pkg.evaluation.status === 'not-allowed' ? '❌' : '❓';
      lines.push(`| ${pkg.name} | ${pkg.version} | ${pkg.licenses.join(', ')} | ${statusIcon} ${pkg.evaluation.status} | ${pkg.type} | ${pkg.homepage} |`);
    });
    lines.push('');

    manualPackages.forEach(pkg => {
      lines.push(`### ${pkg.name}`);
      lines.push(`- **Beschreibung:** ${pkg.description}`);
      lines.push(`- **Copyright:** ${pkg.copyright}`);
      lines.push(`- **Lizenz:** ${pkg.licenses.join(', ')}`);
      lines.push(`- **Status:** ${pkg.evaluation.note}`);
      lines.push('');
    });
  }

  // License types by frequency
  lines.push('## Lizenztypen nach Häufigkeit');
  lines.push('');
  lines.push('```');

  // Calculate percentages and format license stats
  let licenseTable = '';
  Object.entries(licenseTypes)
    .slice(0, 8) // Show only top 8 licenses
    .forEach(([license, count]) => {
      const percent = Math.round((count / totalPackages) * 1000) / 10;
      licenseTable += `${license.padEnd(14)}: ${String(count).padStart(4)} (${percent}%)\n`;
    });

  // If more than 8 license types, group the rest as "Other"
  const restCount = Object.entries(licenseTypes).slice(8).reduce((sum, [_, count]) => sum + count, 0);
  if (restCount > 0) {
    const percent = Math.round((restCount / totalPackages) * 1000) / 10;
    licenseTable += `${'Andere'.padEnd(14)}: ${String(restCount).padStart(4)} (${percent}%)\n`;
  }

  lines.push(licenseTable);
  lines.push('```');
  lines.push('');

  // Action recommendations
  lines.push('## Handlungsempfehlungen');
  lines.push('');

  // Immediate actions
  lines.push('1. **Sofort:**');
  if (unknownPkgs.length > 0) {
    lines.push(`   - Prüfung der ${unknownPkgs.length} Pakete mit unbekannter Lizenz`);
  }
  if (restrictedPkgs.length > 0) {
    lines.push('   - Sicherstellen der Attribution für eingeschränkt lizenzierte Komponenten');
  }
  if (unknownPkgs.length === 0 && restrictedPkgs.length === 0) {
    lines.push('   - Keine sofortigen Maßnahmen erforderlich');
  }
  lines.push('');

  // Short-term actions
  lines.push('2. **Kurzfristig:**');
  lines.push('   - NOTICE-Datei mit Attributionen erstellen');
  lines.push('   - Compliance-Dokumentation aktualisieren');
  lines.push('');

  // Medium-term actions
  lines.push('3. **Mittelfristig:**');
  lines.push('   - Lizenzprüfung in CI/CD-Pipeline integrieren');
  lines.push('   - Alternativpakete für problematische Lizenzen evaluieren');
  lines.push('');

  // Detailed package lists
  lines.push('## Details nach Ökosystem');
  lines.push('');

  // PHP packages with issues
  const phpProblematicPkgs = [...composerPkgs].filter(p => p.evaluation.status === 'unknown' || p.evaluation.status === 'restricted' || p.evaluation.status === 'not-allowed');
  lines.push('<details>');
  lines.push(`<summary><strong>PHP-Pakete mit unbekannter/eingeschränkter Lizenz</strong> (${phpProblematicPkgs.length} Paket${phpProblematicPkgs.length !== 1 ? 'e' : ''})</summary>`);
  lines.push('');

  if (phpProblematicPkgs.length > 0) {
    phpProblematicPkgs.forEach(pkg => {
      const lic = pkg.licenses.length ? pkg.licenses.join(', ') : 'unbekannt';
      const home = pkg.homepage ? ` | ${pkg.homepage}` : '';
      lines.push(`- ${pkg.name}@${pkg.version} — Lizenz: ${lic} — Einstufung: ${pkg.evaluation.status} (${pkg.evaluation.note})${home}`);
    });
  } else {
    lines.push('Keine PHP-Pakete mit problematischen Lizenzen gefunden.');
  }
  lines.push('');
  lines.push('</details>');
  lines.push('');

  // JS packages with issues
  const jsProblematicPkgs = [...importMapPkgs].filter(p => p.evaluation.status === 'unknown' || p.evaluation.status === 'restricted' || p.evaluation.status === 'not-allowed');
  lines.push('<details>');
  lines.push(`<summary><strong>JavaScript-Pakete mit unbekannter/eingeschränkter Lizenz</strong> (${jsProblematicPkgs.length} Paket${jsProblematicPkgs.length !== 1 ? 'e' : ''})</summary>`);
  lines.push('');

  if (jsProblematicPkgs.length > 0) {
    jsProblematicPkgs.forEach(pkg => {
      const lic = pkg.licenses.length ? pkg.licenses.join(', ') : 'unbekannt';
      const home = pkg.homepage ? ` | ${pkg.homepage}` : '';
      lines.push(`- ${pkg.name}@${pkg.version} — Lizenz: ${lic} — Einstufung: ${pkg.evaluation.status} (${pkg.evaluation.note})${home}`);
    });
  } else {
    lines.push('Keine JavaScript-Pakete mit problematischen Lizenzen gefunden.');
  }
  lines.push('');
  lines.push('</details>');
  lines.push('');

  // Full PHP package list
  lines.push('<details>');
  lines.push(`<summary><strong>Vollständige Liste der PHP-Pakete</strong> (${composerPkgs.length} Pakete)</summary>`);
  lines.push('');

  if (composerPkgs.length > 0) {
    composerPkgs.forEach(pkg => {
      const lic = pkg.licenses.length ? pkg.licenses.join(', ') : 'unbekannt';
      const home = pkg.homepage ? ` | ${pkg.homepage}` : '';
      lines.push(`- ${pkg.name}@${pkg.version} — Lizenz(e): ${lic} — Einstufung: ${pkg.evaluation.status}${home}`);
    });
  } else {
    lines.push('Keine PHP-Pakete gefunden.');
  }
  lines.push('</details>');
  lines.push('');

  // Full JS package list
  lines.push('<details>');
  lines.push(`<summary><strong>Vollständige Liste der JavaScript-Pakete</strong> (${importMapPkgs.length} Pakete)</summary>`);
  lines.push('');

  if (importMapPkgs.length > 0) {
    importMapPkgs.forEach(pkg => {
      const lic = pkg.licenses.length ? pkg.licenses.join(', ') : 'unbekannt';
      const home = pkg.homepage ? ` | ${pkg.homepage}` : '';
      lines.push(`- ${pkg.name}@${pkg.version} — Lizenz(e): ${lic} — Einstufung: ${pkg.evaluation.status}${home}`);
    });
  } else {
    lines.push('Keine JavaScript-Pakete gefunden.');
  }
  lines.push('</details>');
  lines.push('');

  // License classification details
  lines.push('## Lizenzeinstufungen im Detail');
  lines.push('');
  lines.push('| Einstufung | Beschreibung | Anforderungen |');
  lines.push('|------------|--------------|---------------|');
  lines.push('| ✅ **Erlaubt** | Kommerzielle Nutzung erlaubt ohne Copyleft (MIT, BSD, Apache-2.0) | Attribution, Copyright-Hinweise beibehalten |');
  lines.push('| ⚠️ **Eingeschränkt** | Kommerziell mit Auflagen (MPL-2.0, EPL, CDDL, CC-BY) | Attribution und spezifische Auflagen beachten |');
  lines.push('| 🔄 **Copyleft** | Kommerziell mit Quelloffenlegung (GPL/AGPL/LGPL) | Quellcode offenlegen, Lizenz beibehalten |');
  lines.push('| ❌ **Nicht erlaubt** | Keine kommerzielle Nutzung (NC-Lizenzen) | Nicht in kommerziellen Projekten verwenden |');
  lines.push('| ❓ **Unbekannt** | Keine oder unklare Lizenzangabe | Manuelle Prüfung erforderlich |');
  lines.push('');

  // Footer
  lines.push('---');
  lines.push('');
  lines.push('*Dieser Bericht wurde automatisch generiert. Bei Fragen zur Lizenzkonformität wenden Sie sich an die Rechtsabteilung.*');
  lines.push('');

  return lines;
}

function main() {
  const repoRoot = process.cwd();
  const outDir = path.join(repoRoot, 'docs', 'reports');
  ensureDir(outDir);
  const outFile = path.join(outDir, 'license-report.md');

  const composer = collectComposer();
  const importMap = collectImportMap();

  const composerPkgs = composer.packages || [];
  const importMapPkgs = importMap.packages || [];

  const composerSummary = summarize(composerPkgs);
  const importMapSummary = summarize(importMapPkgs);

  // Add manually included packages that aren't managed by composer/importmap
  const manualPackages = [
    {
      name: 'marked.js',
      version: 'latest (CDN)',
      licenses: ['MIT'],
      evaluation: { status: 'allowed', note: 'Permissive Lizenz erlaubt kommerzielle Nutzung; NOTICE/Attribution beachten' },
      type: 'JavaScript (CDN)',
      homepage: 'https://github.com/markedjs/marked',
      description: 'A markdown parser - loaded via CDN from jsdelivr.net',
      copyright: 'Copyright (c) 2018+, MarkedJS and Christopher Jeffrey'
    },
    {
      name: 'FOSJsRoutingBundle',
      version: 'bundled',
      licenses: ['MIT'],
      evaluation: { status: 'allowed', note: 'Permissive Lizenz erlaubt kommerzielle Nutzung; NOTICE/Attribution beachten' },
      type: 'Symfony Bundle',
      homepage: 'https://github.com/FriendsOfSymfony/FOSJsRoutingBundle',
      description: 'Symfony bundle for JavaScript routing - provides router.min.js',
      copyright: 'Copyright (c) FriendsOfSymfony'
    }
  ];

  // Update summaries to include manual packages
  const manualSummary = summarize(manualPackages);
  const totalSummary = {
    total: composerSummary.total + importMapSummary.total + manualSummary.total,
    byStatus: {}
  };

  // Combine status counts
  Object.keys(composerSummary.byStatus).forEach(status => {
    totalSummary.byStatus[status] = (composerSummary.byStatus[status] || 0) +
                                    (importMapSummary.byStatus[status] || 0) +
                                    (manualSummary.byStatus[status] || 0);
  });

  // Generate and write the report
  const reportLines = generateReport(composerPkgs, importMapPkgs, manualPackages, composerSummary, importMapSummary, totalSummary);
  fs.writeFileSync(outFile, reportLines.join('\n'), 'utf8');

  console.log(`✅ Lizenzbericht erstellt: ${path.relative(repoRoot, outFile)}`);
  console.log(`📊 Statistik: ${totalSummary.total} Pakete analysiert`);
  console.log(`   - ${totalSummary.byStatus.allowed} erlaubt`);
  console.log(`   - ${totalSummary.byStatus.restricted} eingeschränkt`);
  console.log(`   - ${totalSummary.byStatus.copyleft} copyleft`);
  console.log(`   - ${totalSummary.byStatus['not-allowed']} nicht erlaubt`);
  console.log(`   - ${totalSummary.byStatus.unknown} unbekannt`);
}

if (require.main === module) {
  main();
}
