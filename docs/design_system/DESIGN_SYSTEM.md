# Fairy Aurora v4 — Cheatsheet

> 1-Seite-Referenz für Developer. Vollständige Doku: [`design-system.html`](design-system.html).
> Tokens-Source: [`assets/styles/fairy-aurora.css`](../assets/styles/fairy-aurora.css).
> Prinzip: **Tokens sind Gesetz.** Hardcodierte Hex-Werte sind Bugs.

---

## 1. Setup

```html
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="assets/styles/fairy-aurora.css">
  <link rel="stylesheet" href="assets/styles/fairy-aurora-components.css">
</head>
```

Reihenfolge zählt: **Bootstrap zuerst**, Aurora-Tokens danach (überschreiben `--bs-*`).

---

## 2. Color-Tokens

| Token | Light | Dark | Verwendung |
|---|---|---|---|
| `--bg` | `#f6f4ef` | `#0a0e1a` | Page-Background |
| `--surface` | `#ffffff` | `#131826` | Cards, Modals |
| `--surface-2` | `#f0eee9` | `#1a2030` | Hover, Sub-Panels |
| `--fg` | `#0f172a` | `#e2e8f0` | Body-Text |
| `--fg-2` | `#475569` | `#94a3b8` | Secondary-Text |
| `--fg-3` | `#94a3b8` | `#64748b` | Muted, Hints |
| `--border` | `#e5e7eb` | `#1f2937` | Borders |
| `--primary` | `#0369a1` | `#22d3ee` | Links, CTAs, Info |
| `--primary-strong` | `#075985` | `#67e8f9` | Hover-State |
| `--accent` | `#a855f7` | `#a78bfa` | Alva, Highlights |
| `--success` | `#10b981` | `#34d399` | Compliant, OK |
| `--warning` | `#f59e0b` | `#fbbf24` | Review, Pending |
| `--danger` | `#ef4444` | `#f87171` | Critical, Failed |

Tints (`--*-tint`), Glows (`--*-glow`) und On-Colors (`--on-*`) sind je Token definiert.
**Niemals** Hex direkt schreiben — immer Token nutzen.

---

## 3. Typography

| Klasse | Size · Line | Weight | Use |
|---|---|---|---|
| `.fa-display` | 48 / 1.05 | 700 | Hero-Titles |
| `.ds-h1` | 36 / 1.15 | 700 | Page-H1 |
| `.ds-h2` | 26 / 1.25 | 650 | Section-H2 |
| `.ds-h3` | 18 / 1.4 | 600 | Sub-Section |
| Body | 15 / 1.55 | 400 | Default |
| `.fa-mono` / `code` | 13 / 1.5 | 500 | Code, Tokens, IDs |

Stack: `--font-sans: "Inter", system-ui` · `--font-mono: "JetBrains Mono", ui-monospace`.

---

## 4. Spacing-Scale

```
--spacing-xs: 4px    --spacing-sm: 8px    --spacing-md: 16px
--spacing-lg: 24px   --spacing-xl: 32px   --spacing-2xl: 48px   --spacing-3xl: 64px
```

Bootstrap-Utilities funktionieren analog: `m-2 = 8px`, `p-3 = 16px`, `gap-3 = 16px`.
**Nichts dazwischen.** Wenn 12px nötig sind, frag dich, ob 8px oder 16px reichen.

---

## 5. Radius & Shadow

```
--r-sm:   5px    --r-md:  6px    --r-lg: 8px    --r-xl: 12px
--r-2xl: 16px    --r-pill: 999px
--r-icon: 8px   /* Icon-Chip-Pattern (32×32 / 40×40) */

--shadow-sm:      0 1px 3px rgba(0,0,0,0.05)
--shadow-md:      0 4px 12px rgba(0,0,0,0.06)
--shadow-lg:      0 8px 24px rgba(0,0,0,0.08)
--shadow-up-sm:   0 -4px 12px rgba(0,0,0,0.06)   /* Mobile-Bottom-Sheet */
--shadow-up-md:   0 -4px 20px rgba(0,0,0,0.10)
--shadow-overlay: 0 20px 60px rgba(0,0,0,0.30)   /* Premium-Modal-Backdrop */
```

Dark-Mode-Shadows haben zusätzlichen Cyan-Glow (`+ 0 0 12px rgba(var(--primary-rgb),0.15)`).

Plus theme-translucent: `--surface-translucent` (Light: `rgba(255,255,255,0.2)`, Dark: `rgba(255,255,255,0.06)`) für Info-Boxes auf colored gradient hero/banner.

---

## 5.5 Z-Index-Stack

Single source of truth: `assets/styles/fairy-aurora.css`. Layer-Order von unten nach oben:

| Token | Wert | Verwendung |
|---|---|---|
| `--z-base` | 0 | Default |
| `--z-popover` | 50 | Tooltips, Quick-Actions, Loading-Overlays |
| `--z-dropdown` | 100 | User-Dropdown, Select-Menus |
| `--z-sticky` | 200 | Sticky-Header, Sticky-Toolbar |
| `--z-fixed` | 500 | Sidebar |
| `--z-overlay` | 900 | Drawer-Backdrops, Mega-Menu, Alva-Dock |
| `--z-modal-backdrop` | 1000 | Modal-Backdrop |
| `--z-modal` | 1001 | Modal-Body |
| `--z-popover-modal` | 1100 | Popover IN Modal |
| `--z-toast` | 1500 | Toast-Notifications (über Modal) |
| `--z-tour` | 2000 | Guided-Tour (über Toast) |
| `--z-command` | 2500 | Command-Palette (`Cmd+K`) |
| `--z-turbo-bar` | 999999 | Turbo-Drive-Loading-Bar |

Plus `--alva-z: var(--z-overlay)` (Alva-Companion-Dock).

Lokale stacking-contexts (Werte 0-5) in `position: relative` Containern: erlaubt mit Pflicht-Kommentar `/* local stacking-context */`. Stylelint-Regel blockt Werte ≥6 die nicht via Token gehen.

---

## 6. Komponenten — Quick Reference

### Brand (Header / Sidebar)
```html
<a class="fa-brand" href="/">
  <span class="fa-brand-mark"><span class="fa-brand-sparkle">✦</span></span>
  <span class="fa-brand-text">
    <span class="fa-brand-name">Alvara</span>
    <span class="fa-brand-version">ISMS · v4</span>
  </span>
</a>
```
Sizes: `--sm` (Sidebar collapsed) · default (Header) · `--lg` (Login, Setup-Wizard).
Brand-Mark = Aurora-Rondelle ✦ — **nicht** Alva. Alva nur in Empty-States, Hints, Onboarding.

### Buttons
```html
<button class="fa-cyber-btn fa-cyber-btn--primary">Speichern</button>
<button class="fa-cyber-btn fa-cyber-btn--ghost">Abbrechen</button>
<button class="fa-cyber-btn fa-cyber-btn--danger">Löschen</button>
```
Sizes: `--sm` (32h), default (40h), `--lg` (48h). Disabled: `[disabled]` oder `aria-disabled="true"`.

### Inputs (via Symfony Form-Theme)
```twig
{{ form_row(form.title, { attr: { theme: 'fa_cyber' }}) }}
```
Standalone-HTML: `<input class="fa-cyber-input">` · Focus = 3px primary-glow + 1px border.

### KPI-Card
```html
<article class="fa-kpi-card fa-kpi-card--success">
  <div class="fa-kpi-card__icon"><i class="bi bi-shield-check"></i></div>
  <div class="fa-kpi-card__body">
    <div class="fa-kpi-card__label">Compliance</div>
    <div class="fa-kpi-card__value">94<span class="fa-kpi-card__unit">%</span></div>
    <div class="fa-kpi-card__trend fa-kpi-card__trend--up">+3% YoY</div>
  </div>
</article>
```
Variants: `--primary` `--success` `--warning` `--danger` `--info`.

### Status-Pill
```html
<span class="fa-status-pill fa-status-pill--success">Compliant</span>
<span class="fa-status-pill fa-status-pill--warning">Review</span>
<span class="fa-status-pill fa-status-pill--danger">Critical</span>
```

### Alert
```html
<div class="fa-alert fa-alert--warning" role="alert">
  <i class="bi bi-exclamation-triangle fa-alert__icon"></i>
  <div class="fa-alert__body">
    <strong>3 Kontrollen sind fällig.</strong>
    <p>Review bis 30.11. erforderlich.</p>
  </div>
</div>
```

### Empty-State
```html
<div class="fa-empty-state">
  <div class="fa-empty-state__alva" data-alva-mood="sleeping" data-alva-size="84"></div>
  <h3 class="fa-empty-state__title">Keine Risiken hier.</h3>
  <p class="fa-empty-state__desc">Sobald ein Risiko erfasst wird, taucht es hier auf.</p>
  <div class="fa-empty-state__actions">
    <button class="fa-cyber-btn fa-cyber-btn--primary">Risiko anlegen</button>
  </div>
</div>
```
**Modifier `--active`:** Aurora-Surface mit Pattern-BG für prominente Empty-Pages (Inbox-Start, Erste-Schritte). Sub-Elemente: `__alva` · `__title` · `__desc` · `__actions`.

### Page-Header (HTML)
```html
<header class="fa-page-header">
  <div class="fa-page-header__meta">
    <span class="fa-page-header__badge"><i class="bi bi-inbox"></i> Inbox</span>
    <h1 class="fa-page-header__title">Eingangspost</h1>
    <p class="fa-page-header__subtitle">3 neue Dokumente seit heute Morgen</p>
  </div>
  <div class="fa-page-header__actions">
    <button class="fa-cyber-btn fa-cyber-btn--primary">Upload</button>
  </div>
</header>
```
`__meta` ist Pflicht-Wrapper für Badge/Title/Subtitle. Ohne `__meta` bricht das Flex-Layout.

### Hero (HTML mit Alva-Slot)
```html
<div class="fa-hero">
  <div class="fa-hero__alva" data-alva-mood="happy" data-alva-size="140"></div>
  <div class="fa-hero__body">
    <h2 class="fa-hero__title">Willkommen zurück, Maxi.</h2>
    <p class="fa-hero__subtitle">Heute warten 3 Aufgaben.</p>
    <div class="fa-hero__actions">…</div>
  </div>
</div>
```

### Alva-Suggestion (KI-Vorschlag-Patterns)
```html
<!-- Pill: „alva-suggested" -->
<span class="fa-status-pill fa-status-pill--accent">
  <span class="fa-status-pill__dot"></span> alva-suggested
</span>

<!-- Hinweis-Box: Mapping-Vorschlag -->
<div class="fa-alert fa-alert--info">
  <span class="fa-alert__icon"><i class="bi bi-stars"></i></span>
  <div class="fa-alert__body">
    <div class="fa-alert__title">Mapping-Vorschlag</div>
    <p class="fa-alert__message">ISO 27001 A.8.1.1 ↔ BSI ORP.1.A1 · Konfidenz 92%</p>
  </div>
</div>
```
Accent (`--accent` = violett) ist die Alva-Tone — **nie** magenta/pink.

### Page-Header (Twig)
```twig
{{ _fa_page_header.render({
  badge:    { icon: 'briefcase', label: 'Board' },
  title:    'Board-Dashboard',
  subtitle: 'Executive Summary für Q4',
  actions:  [{ label: 'Export', variant: 'ghost', icon: 'download' }]
}) }}
```

### Hero (Welcome-Banner)
```twig
{{ _fa_hero.render({
  mood:     'happy',
  title:    'Willkommen zurück, Maxi',
  subtitle: 'Heute warten 3 Aufgaben in der Inbox.',
  actions:  [{ label: "Los geht's", variant: 'primary', icon: 'arrow-right', href: '/dashboard' }]
}) }}
```
Genau **einmal pro Seite**, ganz oben. Alva 140px, Premium-Glow-Border. Für Login-Landing & Modul-Onboarding.

### Feature-Card (KPI-Tile mit Accent-Bar)
```html
<article class="fa-feature-card fa-feature-card--success">
  <div class="fa-feature-card__icon-chip"><i class="bi bi-check-circle-fill"></i></div>
  <p class="fa-feature-card__label">SLA erfüllt</p>
  <div class="fa-feature-card__value-row">
    <span class="fa-feature-card__value">98.4</span>
    <span class="fa-feature-card__unit">%</span>
  </div>
  <p class="fa-feature-card__hint">+2.1% seit letzter Woche</p>
</article>
```
Variants: `--primary` `--success` `--warning` `--danger`. Optional `href=` → Tag wird `<a>` (clickable).
**Wann statt KPI-Card?** Wenn die Karte prominent oder klickbar sein soll.

### RAG-Card (Executive Status-Tile)
```twig
{{ _fa_rag_card.render({
  status: 'amber',                {# green|amber|red #}
  title:  'Business Continuity',
  detail: '2 BCPs überfällig zum Review.'
}) }}
```
Für Board-Dashboards — eine Karte pro Compliance-Domäne.
**RAG vs. Status-Pill?** Pills sind inline (Tabellen). RAG-Cards sind Hero-Tiles.

### Filter-Chip · Toggle-Group (mutually-exclusive)
```html
<div class="fa-filter-chip-group" role="group" aria-label="Zeitraum">
  <button class="fa-filter-chip fa-filter-chip--active" aria-pressed="true">Heute</button>
  <button class="fa-filter-chip" aria-pressed="false">Woche</button>
  <button class="fa-filter-chip" aria-pressed="false">Monat</button>
</div>
```

### Filter-Chip · State-Pills (aktive Filter mit Remove-Button)
```html
<div class="fa-filter-chips">
  <span class="fa-filter-chip fa-filter-chip--primary">
    <span class="fa-filter-chip__label">Status</span>
    <span class="fa-filter-chip__value">Offen</span>
    <button class="fa-filter-chip__remove" aria-label="Filter entfernen">×</button>
  </span>
  <button class="fa-filter-chips__clear-all">Alle zurücksetzen</button>
</div>
```
**Toggle vs. State?** Toggle = Auswahl-UI (vorne). State = aktive Anzeige danach.
Nicht vermischen — gleiche Klasse, unterschiedliche innere Struktur.

### Filter-Select (Query-String-Filter)
```twig
{{ _fa_filter_select.render({
  name: 'status',
  label: 'Status',
  values: ['active','in_review','approved','archived'],
  prefix: 'asset.status.',
  domain: 'asset',
  selected: app.request.query.get('status'),
  all_label: 'Alle'
}) }}
```
Erstes Item ist immer "Alle" (leerer Wert). Nutzt Enum-Klasse oder Werte-Liste als single source of truth.

### Mode-Switch · Toggle-Card · Step-Header · Check-Row · Sparkline · Flash · Aurora-Surface · Typewriter
Vollständige Live-Beispiele und HTML-Templates: [`design-system.html`](design-system.html#mode-switch). Alle nutzen `.fa-*`-Klassen aus `fairy-aurora-components.css`.

---

## 6.1 Admin Panel

System-Administration (36 Module in 7 Gruppen) — eigene Component-Familie `.fa-admin-*`.
Live-Beispiele: [`design-system.html#admin-intro`](design-system.html#admin-intro) · Prototype: [`admin/Admin Panel.html`](../admin/Admin%20Panel.html).

**Variante A (gewählt):** Settings-Hub mit Card-Grid-Landing in der normalen App-Shell. Ein Sidebar-Eintrag „Administration", dahinter durchsuchbares Modul-Grid.

### Hub-Card
```html
<a class="fa-admin-hub-card" href="/admin/tenants">
  <span class="fa-admin-hub-card__icon"><i class="bi bi-building"></i></span>
  <span class="fa-admin-hub-card__title">Mandanten</span>
  <span class="fa-admin-hub-card__desc">Tenants, Branding, Limits</span>
  <span class="fa-admin-hub-card__count">12</span>
</a>
```

### Detail-Templates (4 Layouts)
| Template | Wann | Beispiele |
|---|---|---|
| `ListLayout` | Tabellen-artige Module | Tenants, Users, Webhooks, Sessions |
| `FormLayout` | Single-Record Settings | SMTP, White-Label, License, i18n |
| `StatusLayout` | KPI-Tiles + Sub-Status | Jobs, Backups, Updates, Health |
| `EmptyLayout` | Coming-Soon / Not-Configured | Module ohne Config |

Alle Templates wrappen `<DetailTemplate id title eyebrow actions toolbar>` mit Brand-Gradient-Edge im Header.

### Permission-Matrix
```html
<table class="fa-perm-matrix">
  <!-- Rolle × Resource Grid · aktive Cell mit cyan glow -->
</table>
```

### Audit-Log-Zeile
```html
<div class="fa-audit-row fa-audit-row--sealed">
  <span class="fa-audit-row__hash">a1b2c3…</span>
  <!-- sealed-Block in Lila (--accent) -->
</div>
```

### API-Key (mit Reveal/Copy + Danger Zone)
```html
<div class="fa-api-key">
  <code class="fa-api-key__value">sk_live_••••••••</code>
  <button class="fa-api-key__reveal"><i class="bi bi-eye"></i></button>
</div>
```

### Service-Tile
```html
<div class="fa-svc-tile fa-svc-tile--healthy">
  <span class="fa-svc-tile__dot"></span>
  Datenbank · 4/4 Pods · p95 38ms
</div>
```

**Tokens:** Cyber-Akzente nur dezent — Brand-Gradient-Kanten, Glow auf aktiven Cells, sealed-Audit in `--accent` (Lila), Aurora-Hero, MFA-Schild-Icons.

---

## 7. Iconography — `.fa-icon`

**77 ISMS-Domain-Icons** als CSS-Mask, einfärbbar via `currentColor`. Stil: Outline 1.4, monochrom.

> Aktualisiert: **186 Icons** (77 Domain + 109 weitere — Compliance, Frameworks, Entities, Status). Vollständige Gallery: [`design-system.html#icons-gallery`](design-system.html#icons-gallery).

```html
<i class="fa-icon fa-icon--audit-trail"></i>                       <!-- 1em (Default) -->
<i class="fa-icon fa-icon--audit-trail fa-icon--20"></i>            <!-- 20px -->
<i class="fa-icon fa-icon--threat fa-icon--danger"></i>             <!-- semantisch rot -->
```

**Größen:** `--16` `--20` `--24` `--32` `--48` (sonst `1em`).
**Farben:** `--success` `--warning` `--danger` `--info` `--muted` `--primary` (Status-Icons haben RAG-Defaults).

**9 Kategorien · 77 Icons:**

| Kategorie | Icons |
|---|---|
| Compliance | `compliance-shield` `regulator` `certificate` `attestation` `scope-statement` `soa` `gap-analysis` `control` |
| Audit | `audit-trail` `finding` `evidence` `sign-off` `review` `sample` `nonconformity` `corrective-action` `audit-internal` `audit-external` |
| Risk | `risk-score` `threat` `vulnerability` `mitigation` `likelihood` `impact` `residual-risk` `risk-register` `heatmap` `risk-accept` |
| Assets | `asset-server` `asset-database` `asset-cloud` `asset-endpoint` `asset-network` `asset-iot` `asset-ot` `asset-application` `data-personal` `data-confidential` |
| Identity | `user` `role` `mfa` `privileged` `sso` `group` `permission` |
| Policies | `policy` `sop` `contract` `nda` `version` `approval` `attachment` `archive` |
| Incident | `incident` `breach` `escalation` `recovery` `forensics` `root-cause` |
| Awareness | `training` `phishing-test` `learning-path` `awareness-stat` |
| Status | `status-ok` `status-warning` `status-critical` `status-info` `status-pending` `status-archived` |
| Actions | `approve` `reject` `assign` `delegate` `export` `import` `schedule` `filter` |

**Framework-Lockup** (Hybrid Schild + Mono-Kürzel):
```html
<span class="fa-framework-lockup">
  <i class="fa-icon fa-icon--compliance-shield"></i>
  <span class="fa-framework-lockup__abbr">ISO 27001</span>
</span>
```
Varianten: default · `--lg` (Detail-Seiten) · `--ghost` (Tabellen, ohne Pillen-Chrom).
Frameworks: ISO 27001/27002/9001/22301 · BSI IT-Grundschutz · BAIT · VAIT · MaRisk · NIS-2 · DORA · GDPR · SOC 2 · PCI-DSS · KRITIS · TISAX · NIST CSF · HIPAA · CIS · BSI C5 · FedRAMP · ENISA.

**Regel:** `bi-*` für generische UI (Pfeile, Burger, Suche). `.fa-icon--*` immer wenn ISMS-Fach-Vokabular gemeint ist.

---

## 8. Alva — Companion-Charakter

9 Moods · React-Komponente in `assets/components/AlvaCharacter.jsx`.

| Mood | Wann | Größe |
|---|---|---|
| `idle` | Default · Dock | 56 |
| `thinking` | KI-Vorschlag · Form-Hint | 32 |
| `working` | Batch-Import · Long-Run | 56–84 |
| `happy` | Form gespeichert · Erfolg | 84 |
| `sleeping` | Archiv · leerer Filter | 84 |
| `warning` | Review fällig · Frist | 40 |
| `focused` | Audit-Mode · Drill-Down | 40 |
| `scanning` | Risk-Scan · Discovery | 56 |
| `celebrating` | ISO-Cert · Milestone | 110 |

**Always `aria-hidden="true"`.** Alvas Aussage steht parallel im Alert-Text oder Empty-State-Title.

---

## 9. Bootstrap — Was ja, was nein

**✅ JA:** `.container` `.row` `.col-*` `.d-flex` `.gap-*` `.m-*` `.p-*` `.text-*` `.bg-body-*` Grid-Utilities.

**❌ NEIN:**
- `.btn` / `.btn-primary` → `.fa-cyber-btn`
- `.form-control` → Twig `fa_cyber`-Theme
- `.badge` → `.fa-status-pill`
- `.alert` → `.fa-alert`
- `.card`-Styling → `.fa-kpi-card` oder eigene Komposition

`--bs-primary`, `--bs-body-bg` etc. sind auf Aurora-Tokens gemappt — Utility-Klassen funktionieren out-of-the-box.

---

## 10. Dark-Mode

```html
<!-- Manuell -->
<html data-theme="dark">

<!-- JS-Toggle -->
document.documentElement.setAttribute('data-theme', 'dark');
localStorage.setItem('theme', 'dark');

<!-- System-follow -->
<html data-theme="system">
```

Drei Modi: `light` · `dark` · `system`. Jedes Token hat Light- und Dark-Werte — **nichts wird invertiert**.

---

## 11. Accessibility — Mindestmaß

- Body-Text: `--fg` auf `--bg` = **AAA**
- Button-Primary: `--on-primary` auf `--primary-strong` = **5.93:1 (AA)**
- Button-Accent: `white` auf `--accent-strong` = **7.21:1 (AAA)**
- Focus-Ring: 3px Glow + 1px solid Border (nie nur Glow)
- Touch-Targets: Min. **44×44px** (`--fa-btn-md` erfüllt das)
- Reduced-Motion: Alle dekorativen Animationen aus, Transitions auf `0.01ms`

---

## 12. Migration v3 → v4 — TL;DR

| v3 | v4 |
|---|---|
| `.fairy-*` | `.fa-*` |
| `--pink` / `--cyan` | `--accent` / `--primary` |
| `.fairy-helper` (4 Moods) | `.fa-alva` (9 Moods) |
| `data-theme="auto"` | `data-theme="system"` |

Vollständig: [`FAIRY_AURORA_MIGRATION.md`](FAIRY_AURORA_MIGRATION.md).

---

## 13. Dateien

| Pfad | Zweck |
|---|---|
| `assets/styles/fairy-aurora.css` | Tokens-Source-of-Truth |
| `assets/styles/fairy-aurora-components.css` | Alle `.fa-*`-Komponenten |
| `assets/styles/fairy-aurora-edge.css` | Edge-Components (filter-state-chip, stepper, dropdown-panel, banner, …) |
| `assets/styles/fairy-aurora-icons.css` | 186 Icon-Mask-Klassen + Framework-Lockup |
| `assets/icons/*.svg` | 77 Icon-Source-Files (24×24 Outline 1.4) |
| `templates/form/fa_cyber_input.html.twig` | Symfony Form-Theme |
| `states/FairyCharacter.jsx` | React-Companion (9 Moods, Tokens-Props) |
| `docs/design-system.html` | Interaktive Doku (mit Icon-Gallery + Admin-Panel) |
| `docs/admin-panel.css` | `.fa-admin-*` Components (Hub-Card, Perm-Matrix, Audit-Row, API-Key, Svc-Tile) |
| `docs/sections/admin-panel.html` | Admin-Panel-Section-Partial (in DS-Loader) |
| `admin/Admin Panel.html` | Live-Prototype (Hub + Detail-Templates) |
| `docs/FAIRY_AURORA_MIGRATION.md` | Migration v3 → v4 |
| `docs/FAIRY_AURORA_v4_ROADMAP.md` | Roadmap |
| `docs/DESIGN_SYSTEM.md` | Diese Datei |

---

## 14. Häufige Fehler — Don't

```css
/* ❌ Hex direkt */
.my-btn { background: #0284c7; }

/* ✅ Token */
.my-btn { background: var(--primary); }
```

```html
<!-- ❌ Bootstrap-Button -->
<button class="btn btn-primary">Save</button>

<!-- ✅ Aurora-Button -->
<button class="fa-cyber-btn fa-cyber-btn--primary">Save</button>
```

```html
<!-- ❌ Alva als reines Logo -->
<img src="alva.svg" class="brand-logo">

<!-- ✅ Brand-Mark = Aurora-Rondelle ✦ · Alva nur in Empty-States/Hints -->
<span class="fa-brand-mark"><span class="fa-brand-sparkle">✦</span></span>
```

```css
/* ❌ Z-index hardcoded (Stylelint blockt Werte ≥6) */
.user-dropdown { z-index: 9999; }

/* ✅ Token */
.user-dropdown { z-index: var(--z-dropdown); }
```

```css
/* ❌ Border-radius hardcoded */
.icon-chip { border-radius: 8px; }

/* ✅ Token */
.icon-chip { border-radius: var(--r-icon); }  /* 32×32 / 40×40 icon-chip */
.card      { border-radius: var(--r-md); }    /* 6px standard card */
```

```css
/* ❌ Brand-Background mit color: white */
.btn-primary { background: var(--primary); color: white; }

/* ✅ On-Token */
.btn-primary { background: var(--primary); color: var(--on-primary); }
```

---

**Fragen?** → Design-System-Maintainer in `#aurora-design` oder PR auf `main`.
