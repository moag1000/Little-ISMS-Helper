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
| `--bg` | `#f5f6fa` | `#0a0e1a` | Page-Background |
| `--surface` | `#ffffff` | `#141829` | Cards, Modals |
| `--surface-2` | `#eef0f9` | `#1e2139` | Hover, Sub-Panels |
| `--surface-3` | `#e5e8f4` | `#282d48` | Tertiary-Surfaces |
| `--fg` | `#1e1b4b` | `#e9eaf5` | Body-Text |
| `--fg-2` | `#4c4a73` | `#b9bad4` | Secondary-Text |
| `--fg-3` | `#6d6b92` | `#6d6f99` | Muted, Hints |
| `--border` | `#dfe3f0` | `#232845` | Borders |
| `--border-strong` | `#b9bfd6` | `#3d4270` | Strong Borders, Outlines |
| `--primary` | `#0284c7` | `#38bdf8` | Links, CTAs, Info, Brand |
| `--primary-strong` | `#0369a1` | `#38bdf8` | Hover-State, Body-Text auf Surface |
| `--accent` | `#7c3aed` | `#a78bfa` | Alva, ✦-Signal, Highlights |
| `--accent-strong` | `#6d28d9` | `#a78bfa` | Accent-Hover, Button-BG |
| `--success` | `#059669` | `#34d399` | Compliant, OK |
| `--warning` | `#d97706` | `#fbbf24` | Review, Pending |
| `--danger` | `#dc2626` | `#f87171` | Critical, Failed |

Tints (`--*-tint`), Glows (`--*-glow`) und On-Colors (`--on-*`) sind je Token definiert.
**Niemals** Hex direkt schreiben — immer Token nutzen.

> **D01 / D02 / D03:** Marke ist **Sky + Violett**. Pink ist gestrichen — das ✦-Signal (Automation, Auto-Fill, Suggestion) trägt jetzt `var(--accent)`.
> Legacy-Aliase (`--cyber-cyan`, `--cyber-pink`, `--bg-1/2/3`) leben in `colors_and_type.css` und lösen auf Aurora-Tokens auf (D08).

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

**Stack** (D07 — Inter primär, Space Grotesk als Geometric-Sans-Fallback gegen Form-Reflow):

```
--font-sans: "Inter", "Space Grotesk", system-ui,
             -apple-system, BlinkMacSystemFont, "Segoe UI",
             Roboto, "Helvetica Neue", Arial, sans-serif;
--font-mono: "JetBrains Mono", ui-monospace, SFMono-Regular,
             Menlo, Consolas, monospace;
```

Inter und JetBrains Mono werden self-hosted unter `/fonts/`. Space Grotesk: Google Fonts über HTML-`<link>` (Self-Host als nächster Schritt).

---

## 4. Spacing-Scale

```
--spacing-xs: 4px    --spacing-sm: 8px    --spacing-md: 16px
--spacing-lg: 24px   --spacing-xl: 32px   --spacing-2xl: 48px   --spacing-3xl: 64px
```

Bootstrap-Utilities funktionieren analog: `m-2 = 8px`, `p-3 = 16px`, `gap-3 = 16px`.
**Nichts dazwischen.** Wenn 12px nötig sind, frag dich, ob 8px oder 16px reichen.

> **D05:** `--spacing-*` ist Canon (in `fairy-aurora.css`). `--space-*` aus `colors_and_type.css` bleibt als `@deprecated`-Alias erhalten.

---

## 5. Radius & Shadow

```
--r-xs: 3px    --r-sm: 5px    --r-md: 6px
--r-lg: 8px    --r-xl: 12px   --r-2xl: 16px   --r-pill: 999px

--shadow-sm: 0 2px 4px rgba(15, 23, 42, 0.08)
--shadow-md: 0 4px 8px rgba(15, 23, 42, 0.12)
--shadow-lg: 0 8px 16px rgba(15, 23, 42, 0.16)
```

Default-Card-Radius = `--r-lg` (8px). Dark-Mode-Shadows bekommen einen subtilen Cyan-Glow on top.

> **D04:** 7 Stufen, enger gestaffelt als die alte 4/8/12er-Skala. Legacy `--radius-sm/--radius/--radius-lg/--radius-pill` sind Aliase auf `--r-sm/--r-lg/--r-xl/--r-pill`.

---

## 5.1 Motion / Timing

```
--t-instant: 80ms     /* Hover-Tints, Focus-Rings */
--t-fast:    120ms    /* Button-Press, Tabs */
--t-base:    240ms    /* Standard — Default-Transition */
--t-slow:    360ms    /* Modals, Drawer-Slide */
--t-magic:   600ms    /* Aurora-Loops, ✦-Sparkle, Fairy-Animationen */

--ease-out:    cubic-bezier(0.16, 1, 0.3, 1)
--ease-in-out: cubic-bezier(0.4, 0, 0.2, 1)
```

> **D06:** 5 Stufen statt 3. `--t-normal` lebt als `@deprecated`-Alias auf `--t-base` weiter.

---

## 6. Komponenten — Quick Reference

### fa-brand
```html
<a class="fa-brand" href="/">
  <span class="fa-brand-mark"><span class="fa-brand-sparkle">✦</span></span>
  <span class="fa-brand-text">
    <span class="fa-brand-name">Little ISMS Helper</span>
    <span class="fa-brand-version">ISMS · v4</span>
  </span>
</a>
```
Sizes: `--sm` (Sidebar collapsed) · default (Header) · `--lg` (Login, Setup-Wizard).
Brand-Mark = Aurora-Rondelle ✦ — **nicht** Alva. Alva nur in Empty-States, Hints, Onboarding.

### fa-cyber-btn
```html
<a href="{{ path('route') }}" class="fa-cyber-btn fa-cyber-btn--primary">
  <i class="fa-icon fa-icon--ui-plus" aria-hidden="true"></i>
  <span class="fa-cyber-btn__label">Speichern</span>
</a>

<button type="submit" class="fa-cyber-btn fa-cyber-btn--primary fa-cyber-btn--lg" disabled aria-disabled="true">
  <span class="fa-cyber-btn__label">Speichern</span>
</button>
```
**Variants:** `--primary` · `--ghost` · `--secondary` · `--accent` · `--danger` (5 Tones, Accent für Alva/Magic-Actions).
**Sizes:** `--sm` (32h) · default (40h) · `--lg` (48h — Hero-CTAs, Wizards). **Disabled:** `disabled` + `aria-disabled="true"`.
**Inhalt:** Icon optional, Label IMMER in `<span class="fa-cyber-btn__label">` (Spacing-konsistent für Icon-only via `aria-label="…"` ohne Label-Span).

### fa-cyber-input (via Symfony Form-Theme)
```twig
{{ form_row(form.title, { attr: { theme: 'fa_cyber' }}) }}
```
Standalone-HTML: `<input class="fa-cyber-input">` · Focus = 3px primary-glow + 1px border.

### fa-kpi-card
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

### fa-status-pill
```html
<span class="fa-status-pill fa-status-pill--success">Compliant</span>
<span class="fa-status-pill fa-status-pill--warning">Review</span>
<span class="fa-status-pill fa-status-pill--danger">Critical</span>
```

### fa-alert
```html
<div class="fa-alert fa-alert--warning" role="alert">
  <i class="bi bi-exclamation-triangle fa-alert__icon"></i>
  <div class="fa-alert__body">
    <strong>3 Kontrollen sind fällig.</strong>
    <p>Review bis 30.11. erforderlich.</p>
  </div>
</div>
```

### fa-empty-state
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

### fa-page-header (Static HTML)
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

### fa-hero (Static HTML mit Alva-Slot)
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

### Alva-Suggestion-Patterns (verschiedene Klassen)
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

### fa-page-header (Twig-Macro)
```twig
{{ _fa_page_header.render({
  badge:    { icon: 'briefcase', label: 'Board' },
  title:    'Board-Dashboard',
  subtitle: 'Executive Summary für Q4',
  actions:  [{ label: 'Export', variant: 'ghost', icon: 'download' }]
}) }}
```

### fa-hero (Twig-Macro)
```twig
{{ _fa_hero.render({
  mood:     'happy',
  title:    'Willkommen zurück, Maxi',
  subtitle: 'Heute warten 3 Aufgaben in der Inbox.',
  actions:  [{ label: "Los geht's", variant: 'primary', icon: 'arrow-right', href: '/dashboard' }]
}) }}
```
Genau **einmal pro Seite**, ganz oben. Alva 140px, Premium-Glow-Border. Für Login-Landing & Modul-Onboarding.

### fa-feature-card (KPI-Tile mit Accent-Bar)
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

### fa-rag-card (Executive Status-Tile)
```twig
{{ _fa_rag_card.render({
  status: 'amber',                {# green|amber|red #}
  title:  'Business Continuity',
  detail: '2 BCPs überfällig zum Review.'
}) }}
```
Für Board-Dashboards — eine Karte pro Compliance-Domäne.
**RAG vs. Status-Pill?** Pills sind inline (Tabellen). RAG-Cards sind Hero-Tiles.

### fa-filter-chip · Toggle-Group (mutually-exclusive)
```html
<div class="fa-filter-chip-group" role="group" aria-label="Zeitraum">
  <button class="fa-filter-chip fa-filter-chip--active" aria-pressed="true">Heute</button>
  <button class="fa-filter-chip" aria-pressed="false">Woche</button>
  <button class="fa-filter-chip" aria-pressed="false">Monat</button>
</div>
```

### fa-filter-chip · State-Pills (aktive Filter mit Remove-Button)
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

### fa-filter-select (Query-String-Filter)
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

### Sonstige Mikro-Komponenten

**`.fa-mode-switch`** — Light/Dark/System-Toggle:
```html
<div class="fa-mode-switch" role="radiogroup" aria-label="Theme">
  <button class="fa-mode-switch__btn" aria-pressed="false" data-theme="light"><i class="fa-icon fa-icon--ui-sun"></i></button>
  <button class="fa-mode-switch__btn is-active" aria-pressed="true" data-theme="system"><i class="fa-icon fa-icon--ui-display"></i></button>
  <button class="fa-mode-switch__btn" aria-pressed="false" data-theme="dark"><i class="fa-icon fa-icon--ui-moon"></i></button>
</div>
```

**`.fa-toggle-card`** — große Option-Karte mit Icon + Label + Check (für Wizards, Setup):
```html
<label class="fa-toggle-card fa-toggle-card--checked">
  <input type="radio" name="preset" value="iso27001" checked>
  <span class="fa-toggle-card__icon"><i class="fa-icon fa-icon--compliance-shield"></i></span>
  <span class="fa-toggle-card__title">ISO 27001:2022</span>
  <span class="fa-toggle-card__desc">93 Annex-A-Controls, Risk-Management-System</span>
</label>
```

**`.fa-step-header`** — Wizard-Step-Indikator (Marker für aktuellen Schritt):
```html
<div class="fa-step-header">
  <span class="fa-step-header__index">2</span>
  <span class="fa-step-header__of">von 4</span>
  <h2 class="fa-step-header__title">Discovery &amp; Secret</h2>
  <p class="fa-step-header__hint">SAML-IdP-URL eintragen und Tenant-Credentials hinterlegen.</p>
</div>
```

**`.fa-check-row`** — Confirmation-Liste mit ✓ (Setup-Done, Audit-Findings cleared):
```html
<ul class="fa-check-row-list">
  <li class="fa-check-row fa-check-row--done"><i class="fa-icon fa-icon--ui-check"></i> Tenant angelegt</li>
  <li class="fa-check-row fa-check-row--done"><i class="fa-icon fa-icon--ui-check"></i> Admin-User eingeladen</li>
  <li class="fa-check-row"><i class="fa-icon fa-icon--ui-hourglass"></i> Framework-Import (läuft …)</li>
  <li class="fa-check-row fa-check-row--pending"><i class="fa-icon fa-icon--status-pending"></i> Erste Policy importieren</li>
</ul>
```

**`.fa-sparkline`** — Inline-Mini-Chart für KPI-Trends (SVG-only, kein JS):
```html
<svg class="fa-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none" aria-label="Trend +12% over 7 Tage">
  <polyline points="0,18 14,16 28,17 42,12 56,10 70,8 84,6 100,4" />
</svg>
```
Tone-Modifier: `.fa-sparkline--success` (grün) · `.fa-sparkline--warning` (gelb) · `.fa-sparkline--danger` (rot).

**`.fa-flash`** — Server-rendered Flash-Message-Slot (alt; neue Code-Base nutzt `_fa_toast` aus 6.7):
```html
<div class="fa-flash fa-flash--success" role="status">
  <i class="fa-icon fa-icon--ui-check"></i> Risiko gespeichert.
</div>
```

**`.fa-typewriter`** — Animations-Klasse für ein „hippes“ Reveal von Text (Login-Screen, Hero):
```html
<h1 class="fa-typewriter" data-text="Cyberpunk Fairy meets ISMS."></h1>
```
Stimulus-Controller `fa-typewriter` schreibt Zeichen für Zeichen; mit `prefers-reduced-motion: reduce` wird der Text sofort gesetzt.

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

## 6.2 Layout & Container

### fa-section — Section-Wrapper mit Header + Tools + Footer
```twig
{% import '_components/_fa_section.html.twig' as _fa_section %}

{{ _fa_section.render({
    title:  'Neueste Aktivität',
    tools:  '<button class="fa-filter-chip fa-filter-chip--active">Heute</button>',
    body:   activityList,
    footer: '<a href="..." class="fa-link">Alle anzeigen →</a>'
}) }}
```
Ersetzt `<div class="container mt-5"> + <h3>`-Patterns. Header-Bottom-Divider fadet von `--primary-tint` zu transparent. Props: `title` · `tools` (raw HTML rechts) · `body` (raw HTML) · `footer` · `id` · `class`.

### fa-action-bar — Page-Action-Toolbar (Search + Filters + Primary CTA)
```twig
{% import '_components/_fa_action_bar.html.twig' as _fa_action_bar %}

{{ _fa_action_bar.render({
    title: 'All Risks',
    count: risks|length,
    search: { placeholder: 'Search risks…', name: 'q', action: path('app_risk_index') },
    filters: [
        { label: 'High',   href: '?severity=high',   active: false },
        { label: 'Medium', href: '?severity=medium', active: true }
    ],
    secondary: [{ label: 'Export', icon: 'bi-download', href: path('app_risk_export') }],
    primary:   { label: 'New Risk', icon: 'bi-plus-lg', variant: 'primary', href: path('app_risk_new') }
}) }}
```
Sitzt zwischen Page-Header und Liste/Tabelle. `<form role="search">` mit visually-hidden Label, aktive Filter-Chips bekommen `aria-current="true"`.

---

## 6.3 Daten & Tabellen

### fa-table — Aurora Data-Table (replaces `<table class="table">`)
**80+ Adoptionen seit v3.5.** Zwei APIs — Render für simple Daten, Embed für volle Cell-Kontrolle.

**Render-API:**
```twig
{% import '_components/_fa_table.html.twig' as _fa_table %}

{{ _fa_table.render({
    id: 'risk-list',
    headers: [
        { label: 'Name',   key: 'name',    sortable: true },
        { label: 'Status', key: 'status' },
        { label: 'Owner',  align: 'right' }
    ],
    rows: rows,
    stickyHead: false, striped: true, compact: false,
    empty: 'No risks yet.'
}) }}
```

**Embed-API** (für Bulk-Select + custom cells):
```twig
{% embed '_components/_fa_table.html.twig' with {
    stickyHead: true, bulkSelect: true, sortable: true, id: 'asset-list'
} %}
    {% block table_head %}
        <tr>
            <th class="fa-table__th fa-table__th--checkbox">
                <input type="checkbox" data-action="fa-bulk-select#selectAll">
            </th>
            <th class="fa-table__th fa-table__th--sortable" data-sort-key="name">…</th>
        </tr>
    {% endblock %}
    {% block table_body %}…{% endblock %}
{% endembed %}
```
Stimulus-Controller: `fa-table-sort` (sortable), `fa-bulk-select` (selection). Modifier: `--striped` · `--compact` · `--sticky-head` · `--hover` (default an).

### fa-progress — Aurora Progress-Bar
**54 Adoptionen seit v3.5.** Ersetzt hand-gerollte `.progress > .progress-bar`.
```twig
{% import '_components/_fa_progress.html.twig' as _fa_progress %}

{{ _fa_progress.render({
    value: 67, max: 100,
    tone: 'success',        {# primary | success | warning | danger | info | neutral #}
    size: 'md',             {# sm | md | lg #}
    label: 'SOA Coverage',
    showText: true, striped: false, animated: false
}) }}
```
Auto-Clamp auf 0–100%. `role="progressbar"` + `aria-valuenow/min/max` automatisch.

---

## 6.4 Bulk-Actions

### _bulk_action_bar — Floating Bulk-Action-Bar (canonical BEM)
Zwei Modi. **Default:** generischer Floating-Bar mit Action-Array. **Tag-Mode (WS-5):** Inline Tag-Picker für Asset-Tagging.

```twig
{# Generic — neutral surface (default) #}
{% include '_components/_bulk_action_bar.html.twig' with {
    actions: ['export', 'delete']
} %}

{# Generic — brand gradient (hero lists: risks, documents) #}
{% include '_components/_bulk_action_bar.html.twig' with {
    actions: ['status_change', 'approve', 'export', 'delete'],
    variant: 'brand'
} %}

{# WS-5 Tag-Apply #}
{% include '_components/_bulk_action_bar.html.twig' with {
    entity_class: 'App\\Entity\\Asset',
    action_urls:  { tag_add: path('admin_tag_bulk_apply', {entityClass: 'Asset'}) },
    available_tags: available_tags()
} only %}
```
Stimulus-Controller `bulk-actions`. Actions: `export` · `tag` · `assign` · `approve` (Quick-Button) · `status_change` (Dropdown mit 5 Stages) · `delete` · `applicable_change`. BEM-CSS: `.fa-bulk-bar` · `.fa-bulk-bar--brand` · `__count` · `__count-num` · `__divider` · `__actions` · `__close` + `.fa-bulk-btn` · `--success` · `--danger`. `variant: 'brand'` ist Hero-Listen vorbehalten — Default ist `neutral`.

> Status-Change Matrix (server-enforced): `draft→in_review` · `in_review→approved` · `in_review→draft` · `approved→published` · `published→archived` · `archived→published`. Niemals beliebige Targets.

---

## 6.5 ISMS-Patterns

### fa-entity-card — Listen-Item-Card mit Entity-Icon + Status-Pill
Für Findings, Risks, Incidents, Audits, Nonconformities. Auto-Link wenn `href` gesetzt.
```twig
{% import '_components/_fa_entity_card.html.twig' as _fa_entity_card %}

{{ _fa_entity_card.render({
    type:    'finding',
    icon:    'finding',
    title:   'F-2026-014 Lückenhafte Logging-Konfig',
    meta:    ['ISO 27001 A.8.15', 'fällig 12.05.'],
    href:    path('finding_show', {id: 14}),
    status:  { variant: 'warning', label: 'Offen' },
    compact: false
}) }}
```
Types: `finding` · `nonconformity` · `risk` · `control` · `evidence` · `incident` · `audit` (linke Akzent-Leiste pro Type).

### fa-entity-badge — Größere Entity-Marker (Listen-Header, Detail-Marker)
```twig
{% import '_components/_fa_entity_badge.html.twig' as _fa_entity_badge %}

{{ _fa_entity_badge.render({ type: 'risk', icon: 'risk-register', label: 'Risk' }) }}
```
Types: `finding` · `nonconformity` · `risk` · `control` · `evidence` · `policy` · `asset` · `incident` · `audit` · `training`.

### fa-audit-row — Audit-Trail-Zeile (tamper-evident)
```twig
{% import '_components/_fa_audit_row.html.twig' as _fa_audit_row %}

{{ _fa_audit_row.render({
    ts:     '10:42:18.331',
    sev:    'info',          {# info | warning | critical | danger #}
    actor:  'Maxi Schubert',
    action: 'role.update',
    target: 'role:auditor',
    hash:   'a7c3…91e2'
}) }}

{# Sealed/failed-Lifecycle-Marker #}
{{ _fa_audit_row.render({
    ts: '10:38:02.118', sev: 'info', actor: 'system',
    action: 'audit.seal', target: 'period:2025-W47',
    pill: 'sealed', hash: '00f2…3d8a',
    sealed: true
}) }}
```
Mono-Font für ts/action/hash. `sealed: true` → Lila-Tint (`--accent`). `failed: true` → Danger-Tint.

### audit-log-item — Detail-Card (Audit-Log Detail/History)
Card-Variante zu `fa-audit-row` für Detail-Views, Entity-History und expandierbare Drill-Downs. Funktioniert 1:1 in Light und Dark via Aurora-Tokens. Verwendet in `templates/audit_log/detail.html.twig` und `entity_history.html.twig`.
```html
<div class="audit-log-list">
  <article class="audit-log-item audit-log-item--update">
    <div class="audit-log-item__icon">
      <i class="fa-icon fa-icon--ui-edit"></i>
    </div>
    <div class="audit-log-item__head">
      <span class="audit-log-item__actor">J. Krämer</span>
      <span class="audit-log-item__action">update</span>
      <time class="audit-log-item__ts">2026-05-18 · 13:18:44</time>
    </div>
    <div class="audit-log-item__body">
      <div class="audit-log-item__entity">Control <code>A.8.15</code></div>
      <div class="audit-log-item__desc">Owner gewechselt …</div>

      {# optional · Diff-Slot für oldValues/newValues #}
      <div class="audit-log-item__diff">
        <div class="audit-log-item__diff-col audit-log-item__diff-col--old">
          <h5>Vorher</h5>
          <dl><dt>owner</dt><dd>alice@…</dd></dl>
        </div>
        <div class="audit-log-item__diff-col audit-log-item__diff-col--new">
          <h5>Nachher</h5>
          <dl><dt>owner</dt><dd>bob@…</dd></dl>
        </div>
      </div>

      <div class="audit-log-item__meta">
        <span class="audit-log-item__meta-cell">10.42.0.94</span>
        <span class="audit-log-item__hash">f8e9…1234</span>
      </div>
    </div>
  </article>
</div>
```
**Tone-Variants:** `--create` · `--update` · `--delete` · `--login` · `--access` · `--system`.
**Lifecycle-Modifier:** `--sealed` (tamper-evident, ✦-Akzent) · `--failed` (Validation/Replay-Fehler) — on-top kombinierbar.
**Wann statt `fa-audit-row`?** Card-Pattern (audit-log-item) für Detail-Seiten und Drill-Downs mit Diff-Slot. Row-Pattern (fa-audit-row) weiterhin für scrollbare Tabellen-Listen (10–11 px Mono).

### fa-diff-row — Old → New Diff-Visualisierung
Für Bulk-Import Delta-Mode + OSCAL-Conflict-Cards. Auto-Derive von `changeType` (added/modified/removed/unchanged).
```twig
{% import '_components/_fa_diff_row.html.twig' as _fa_diff_row %}

{# Field-Level-Diff #}
{{ _fa_diff_row.render('old name', 'new name', { label: 'Asset Name' }) }}

{# Inline-Cell-Pair für Tabellen #}
<td>{{ _fa_diff_row.cell_diff('confidential', 'restricted') }}</td>

{# Labeled Listen-Reihe #}
<ul class="fa-diff-list">
  {{ _fa_diff_row.row('Owner', 'alice@old.com', 'bob@new.com', 'modified') }}
  {{ _fa_diff_row.row('Tags', null, ['critical', 'pii'], 'added') }}
</ul>
```
Semantische `<ins>` / `<del>` für added/removed.

### fa-stepper — Multi-Step Wizard-Chrome
```twig
{% import '_components/_fa_stepper.html.twig' as _fa_stepper %}

{{ _fa_stepper.render([
    { label: 'Preset wählen',       href: path('app_sso_wizard_step', {step: 1}) },
    { label: 'Discovery & Secret',  href: path('app_sso_wizard_step', {step: 2}) },
    { label: 'Test-Connection' }
], currentIndex = 1, { ariaLabel: 'SSO-Setup-Wizard' }) }}
```
Status auto-derived: `idx < currentIndex` → completed, `idx == currentIndex` → active (mit `aria-current="step"`), sonst pending. Override per `step.status: 'error'`. Verwendet in SSO-Wizard, Bulk-Import, OSCAL-Import.

### fa-deadline-counter — Regulatorischer Countdown
GDPR Art.&nbsp;33 (72h), NIS-2 Art.&nbsp;23 (24h/72h), DORA Art.&nbsp;19. Status-Pill mit Auto-Tone und Minuten-Tick.
```twig
{% import '_components/_fa_deadline_counter.html.twig' as _fa_deadline_counter %}

{{ _fa_deadline_counter.render({
    deadline_at: incident.detectedAt|date_modify('+72 hours'),
    label:       'DSGVO Art. 33 SLA',
    compact:     false
}) }}
```
Auto-Toning: `< 24h` → danger, `< 7d` → warning, sonst success. Überschritten → stabiler `overdue`-Danger-State. Stimulus-Controller `deadline-counter` aktualisiert das Label jede Minute — kritisch für die letzte Stunde des 72h-GDPR-Fensters.

### fa-matrix-table — Risk-Heatmap (5 × 5)
ISO-27005-Heatmap. Y-Achse Wahrscheinlichkeit, X-Achse Auswirkung. Auto-Severity-Mapping aus Score, Count-Pill pro Zelle, optional Legende.
```twig
{% import '_components/_fa_matrix_table.html.twig' as _fa_matrix %}

{{ _fa_matrix.render({
    title:  'Risk-Heatmap · Q2 2026',
    rows:   ['Sehr niedrig', 'Niedrig', 'Mittel', 'Hoch', 'Sehr hoch'],
    cols:   ['Vernachlässigbar', 'Gering', 'Mittel', 'Hoch', 'Katastrophal'],
    cells:  matrix,    {# 5×5 Array of { score, severity?, count, href? } #}
    yLabel: 'Wahrscheinlichkeit',
    xLabel: 'Auswirkung',
    legend: true
}) }}
```
**Severity-Mapping** auto-derived aus Score: `≤3 low` · `≤6 moderate` · `≤9 medium` · `≤16 high` · `>16 critical`. Override per `cell.severity`. Baut auf existierender `.isms-risk-matrix`-CSS (deprecated als direkter Klassen-Einsatz — neuer Code nutzt den Macro).

### fa-settings-table — Form-Grid für Admin-Settings
Tabellarisches Form-Layout mit gleich-alignten Label/Control/Status-Spalten. Pflicht für alle Settings-Detail-Seiten unter `/admin/settings/*`.
```twig
{% import '_components/_fa_settings_table.html.twig' as _fa_settings %}

{{ _fa_settings.render({
    title: 'SMTP-Server · Outbound-Mail',
    meta:  'module · email_delivery',
    rows: [
        { kind: 'text',   label: 'SMTP-Host', hint: 'FQDN oder IP',
          name: 'smtp_host', value: config.smtp_host,
          status: { label: 'erreichbar', tone: 'success' } },

        { kind: 'select', label: 'Port', hint: '587 für STARTTLS',
          name: 'smtp_port', value: 587,
          options: [
              { value: 587, label: '587 (STARTTLS)' },
              { value: 465, label: '465 (SMTPS)' }
          ] },

        { kind: 'chips',  label: 'Empfangs-Modus', name: 'modes',
          values: ['audit', 'risk', 'sla'],
          options: ['audit', 'risk', 'digest', 'reports', 'sla'],
          span: 2 },

        { kind: 'toggle', label: 'DKIM signieren', hint: 'DomainKeys Identified Mail',
          name: 'dkim', value: true,
          status: { label: 'aktiv', tone: 'success' } }
    ]
}) }}
```
**Row-Kinds:** `text` · `email` · `number` · `select` · `chips` · `toggle` · `textarea`.
**Status-Tones:** `success` · `warning` · `danger` · `neutral`.
**Wann statt fa-cyber-field?** Settings-Table für 5+ gleich-alignte Felder mit Live-Status (DKIM aktiv, SPF OK). fa-cyber-field für 1–3 isolierte Inputs in Wizards/Filter-Bars. FormType + `fa_cyber`-Theme wenn CSRF/Validation gebraucht.

---

## 6.6 Forms (Raw HTML, ohne Symfony-FormType)

### fa-cyber-field — Aurora-Frame Inputs
Selbe `.fa-cyber-input`-Frame wie das Symfony-Form-Theme, aber als Macro für Templates ohne FormType (Filter-Bars, Wizard-Steps, GET-Search).
```twig
{% import '_components/_fa_cyber_field.html.twig' as _fa_cyber %}

{# Single-Line Input #}
{{ _fa_cyber.text('finding_reference', {
    label:       'Finding-Reference'|trans,
    value:       finding_default|default(''),
    placeholder: 'AF-2026-001',
    help:        'Bezug zum Audit-Finding'|trans,
    max:         100,
    required:    true,
    error:       errors.finding_reference|default('')
}) }}

{# Multi-Line #}
{{ _fa_cyber.textarea('description', { label: 'Beschreibung', rows: 6 }) }}

{# Select #}
{{ _fa_cyber.select('severity',
    [{value:'low',label:'Niedrig'},{value:'high',label:'Hoch'}],
    { label: 'Schweregrad', value: 'high', placeholder: '— wählen —' }
) }}
```
Für FormType-rendered Inputs weiterhin das Symfony Form-Theme `fa_cyber_input.html.twig` nutzen.

---

## 6.7 Feedback

### fa-toast — Toast/Flash-Stack
Server-rendered (für Flash-Messages) und JS-driven (Stimulus-Controller `fa-toast`). In `base.html.twig` wired — du musst nichts selbst rendern, außer für inline-immediate Visibility ohne JS.
```twig
{% import '_components/_fa_toast.html.twig' as _fa_toast %}

{{ _fa_toast.stack([
    { tone: 'success', title: 'Saved.', message: 'Control updated.' },
    { tone: 'warning', message: '3 reviews due in <7 days.' }
], position = 'top-right') }}
```
Dynamisch via Custom-Event:
```js
window.dispatchEvent(new CustomEvent('fa-toast:show', { detail: {
    tone: 'success', title: 'Saved.', message: '...', duration: 5000
}}));
```
Tones: `info` · `success` · `warning` · `danger`. Critical (warning/danger) → `role="alert"` + `aria-live="assertive"`. Auto-dismiss mit Progress-Bar; `persistent: true` für Dauer-Toasts.

### fa-condition-builder — Visual Rule-Builder (Notification-Rules)
Ersetzt JSON-Editor. Chip-Row: `[field-dropdown] [operator] [value] [×]  [+ Bedingung]`.
```twig
{% import '_components/_fa_condition_builder.html.twig' as _fa_condition_builder %}

{{ _fa_condition_builder.render(
    fields = [
        { key: 'severity', label: 'Schweregrad', type: 'enum', options: ['low','medium','high','critical'] },
        { key: 'count',    label: 'Betroffene',  type: 'number' }
    ],
    operators = [
        { key: 'eq',  label: '=', appliesTo: ['enum','string','number'] },
        { key: 'gte', label: '≥', appliesTo: ['number'] }
    ],
    conditions = [{ field: 'severity', operator: 'eq', value: 'high' }],
    { name: 'rule_conditions', ariaLabel: 'Notification-Bedingungen' }
) }}
```
Stimulus-Controller `condition-builder` serialisiert zu Hidden-Input-JSON. Validiert dass Operator zum Field-Type passt.

### fa-alva-hint — Proaktiver Alva-Tipp
Wrapper um `fa-alert` mit `„Alva-Tipp:“`-Prefix, optionaler Action-Button (CSRF-protected POST) und Dismiss-Button. Dismissal lebt in der DB → cross-device.
```twig
{% import '_components/_fa_alva_hint.html.twig' as _fa_alva_hint %}

{% set hint = alva_hint(asset) %}   {# AlvaHintExtension Twig-Function #}
{% if hint %}
    {{ _fa_alva_hint.render(hint) }}
{% endif %}
```
Das `hint`-Argument ist ein <code>App\AlvaHint\AlvaHint</code> DTO. Mood ausgelesen aus `hint.mood` und auf das Alert-Element als `data-alva-mood="…"`. Stimulus-Controller `alva-hint` regelt Dismiss-POST nach `/alva-hint/dismiss`.

### fa-confirm — Modal Confirmation Dialog (3 Eskalations-Stufen)
Für irreversible / tenant-wide Aktionen (Revoke, Delete-Tenant, Force-Logout, Hard-Reset).
```twig
{% import '_components/_fa_confirm.html.twig' as _fa_confirm %}

{{ _fa_confirm.render({
    tone:           'danger',              {# warn | danger | nuclear #}
    icon:           'exclamation-triangle-fill',
    title:          'API-Key dauerhaft widerrufen?',
    sub:            'Der Key <code>lih_live_91de…</code> wird sofort ungültig.',
    diff: [
        { label: 'Aktive Verbindungen', value: '14 → 0' },
        { label: 'Reporting-Service',   value: 'offline', tone: 'danger' }
    ],
    confirmPhrase:   'Reporting-Service',
    submitLabel:     'Endgültig widerrufen',
    cooldownSeconds: 5,
    formAction:      path('admin_api_key_revoke', {id: key.id}),
    formCsrfToken:   csrf_token('api_key_revoke')
}) }}
```
**Eskalations-Tones:**
- `warn` — Confirmation alone reicht
- `danger` — Type-to-confirm-Phrase erforderlich
- `nuclear` — Type-to-confirm + 5-Sekunden-Cooldown vor Submit

Stimulus-Controller `fa-confirm`. Diff-Block visualisiert before→after-Effekte mit optionaler `tone`-Färbung pro Row.

---

## 6.8 Aurora Surface & Pattern Utilities

Aurora-Atmosphäre als Opt-in-Klasse — Pattern-Dots, Radial-Gradients, Hero-Glow. Auf jedes Modul anwendbar.

```html
<div class="fa-aurora-surface">…default — Radial-Gradients + dünnes Pattern…</div>
<div class="fa-aurora-surface--subtle">…Body-Backdrop (Opacity 0.04)…</div>
<div class="fa-aurora-surface--hero">…Hero + Empty-State (Opacity 0.16)…</div>
<div class="fa-aurora-surface--dots">…reines Circuit-Dot-Pattern, transparenter Hintergrund…</div>
```

**Pattern-Intensitäten** (Tokens):
```
--pattern-opacity-subtle:  0.04   /* Body-Backdrop */
--pattern-opacity-default: 0.08   /* Standard-Container */
--pattern-opacity-hero:    0.16   /* Hero + Empty-State */
```

**Wann welcher?** `--subtle` als Body-Backdrop, default für Section-Wrapper, `--hero` für Empty-States mit prominentem Alva, `--dots` wenn nur das Pattern ohne Gradient gewollt ist (z. B. über farbigen Background).

---

## 6.9 CSS-only Patterns

Keine Twig-Macros — pures HTML + CSS-Klassen aus `fairy-aurora-components.css`.

### fa-drawer — Slide-in Side-Sheet
```html
<div class="fa-drawer-backdrop is-open"></div>
<aside class="fa-drawer fa-drawer--right is-open">
  <header class="fa-drawer__header">
    <h2 class="fa-drawer__title">Risiko bearbeiten</h2>
    <button class="fa-drawer__close" aria-label="Schließen">×</button>
  </header>
  <div class="fa-drawer__body">…Form…</div>
  <footer class="fa-drawer__footer">
    <button class="fa-cyber-btn fa-cyber-btn--ghost">Abbrechen</button>
    <button class="fa-cyber-btn fa-cyber-btn--primary">Speichern</button>
  </footer>
</aside>
```
Modifier: `--left` · `--right` (default) · `--sm` (380px) · `--lg` (720px). Toggle: `.is-open` auf Drawer + Backdrop.

### fa-menu — Dropdown / Overflow-Action-Menu
```html
<div class="fa-menu-anchor">
  <button class="fa-cyber-btn fa-cyber-btn--ghost fa-cyber-btn--sm"
          aria-haspopup="menu" aria-expanded="false"
          data-action="click->fa-menu#toggle">
    <i class="fa-icon fa-icon--ui-more" aria-hidden="true"></i> Aktionen
  </button>
  <div class="fa-menu is-open" role="menu">
    <div class="fa-menu__group">
      <a href="#" class="fa-menu__item" role="menuitem">
        <i class="fa-icon fa-icon--ui-edit" aria-hidden="true"></i> Bearbeiten
      </a>
      <a href="#" class="fa-menu__item" role="menuitem">
        <i class="fa-icon fa-icon--ui-files" aria-hidden="true"></i> Duplizieren
      </a>
    </div>
    <div class="fa-menu__divider"></div>
    <div class="fa-menu__group">
      <a href="#" class="fa-menu__item fa-menu__item--danger" role="menuitem">
        <i class="fa-icon fa-icon--ui-trash" aria-hidden="true"></i> Löschen
      </a>
    </div>
  </div>
</div>
```
Toggle: `.is-open` auf `.fa-menu`. Modifier: `.fa-menu__item--danger` für destruktive Aktionen, `.fa-menu__divider` als Trenner zwischen Gruppen.

### breadcrumb — Page-Path-Indikator
Pfad-Navigation am oberen Rand jeder Detail-Seite. `Startseite` als erster Crumb mit Home-Icon, `/`-Separator via CSS `::before`, aktives Crumb via `.active` + `aria-current="page"`.
```html
<nav aria-label="breadcrumb" class="breadcrumb-nav">
  <ol class="breadcrumb">
    <li class="breadcrumb-item">
      <a href="{{ path('app_home') }}">
        <i class="fa-icon fa-icon--nav-home"></i><span>Startseite</span>
      </a>
    </li>
    <li class="breadcrumb-item"><a href="...">Konzern-Reports</a></li>
    <li class="breadcrumb-item active" aria-current="page">Konzern-Struktur</li>
  </ol>
</nav>
```
**Twig-Macro:** `{% include '_components/_breadcrumb.html.twig' with { breadcrumbs: [...] } %}` — Home-Crumb wird auto-prepended, übergib nur die Pfad-Levels. CSS-Canon in `assets/styles/ui-components.css`.

### fa-pager — Pagination
```html
{# Vollständig: Info + Pager + Per-Page-Select #}
<div class="fa-pager-bar">
  <div class="fa-pager-bar__info"><strong>21–40</strong> von <strong>247</strong></div>

  <nav class="fa-pager" aria-label="Seitennavigation">
    <button class="fa-pager__btn" aria-label="Erste Seite">
      <i class="fa-icon fa-icon--util-chevron-left"></i>
    </button>
    <button class="fa-pager__btn" aria-label="Vorherige">‹</button>
    <button class="fa-pager__btn">1</button>
    <button class="fa-pager__btn">2</button>
    <button class="fa-pager__btn is-active" aria-current="page">3</button>
    <span class="fa-pager__ellipsis">…</span>
    <button class="fa-pager__btn">13</button>
    <button class="fa-pager__btn" aria-label="Nächste">›</button>
  </nav>

  <div class="fa-pager-perpage">
    <label for="perpage">Pro Seite</label>
    <select id="perpage">
      <option>20</option><option>50</option><option>100</option>
    </select>
  </div>
</div>

{# Kompakt-Variante (mobile, dichte Tabellen) #}
<nav class="fa-pager fa-pager--compact" aria-label="Kompakt-Pager">
  <button class="fa-pager__btn">‹</button>
  <button class="fa-pager__btn">1</button>
  <button class="fa-pager__btn is-active" aria-current="page">2</button>
  <button class="fa-pager__btn">3</button>
  <button class="fa-pager__btn">›</button>
</nav>
```
Aktive Seite: `.is-active` + `aria-current="page"` — Brand-Gradient mit Glow. Ellipsis-Indikator: `.fa-pager__ellipsis`. Compact-Modifier: `.fa-pager--compact` (kleinere Buttons, weniger Padding).

### fa-kbd / fa-kbd-combo / fa-cheatsheet — Keyboard-Keys & Shortcut-Overlay
```html
{# Einzeltasten #}
Drücke <kbd class="fa-kbd">?</kbd> für Hilfe oder <kbd class="fa-kbd">Esc</kbd> zum Schließen.

{# Tastenkombinationen #}
<div class="fa-kbd-combo">
  <kbd class="fa-kbd">⌘</kbd>
  <span class="fa-kbd-combo__plus">+</span>
  <kbd class="fa-kbd">K</kbd>
</div>

{# Größeres Format für Hero-Slots #}
<kbd class="fa-kbd fa-kbd--lg">?</kbd>

{# Vollständiger Cheatsheet-Overlay #}
<div class="fa-cheatsheet">
  <header class="fa-cheatsheet__header">
    <div>
      <div class="fa-cheatsheet__sub">Tastenkürzel</div>
      <h3 class="fa-cheatsheet__title">ISMS-Helper</h3>
    </div>
    <kbd class="fa-kbd fa-kbd--lg">?</kbd>
  </header>
  <div class="fa-cheatsheet__grid">
    <div class="fa-cheatsheet__group">
      <h4>Navigation</h4>
      <div class="fa-cheatsheet__row">
        <span>Command-Palette</span>
        <div class="fa-kbd-combo"><kbd class="fa-kbd">⌘</kbd><kbd class="fa-kbd">K</kbd></div>
      </div>
      <div class="fa-cheatsheet__row">
        <span>Quick-Search</span>
        <kbd class="fa-kbd">/</kbd>
      </div>
    </div>
    <div class="fa-cheatsheet__group">
      <h4>Aktionen</h4>
      <div class="fa-cheatsheet__row">
        <span>Speichern</span>
        <div class="fa-kbd-combo"><kbd class="fa-kbd">⌘</kbd><kbd class="fa-kbd">S</kbd></div>
      </div>
      <div class="fa-cheatsheet__row">
        <span>Schließen</span>
        <kbd class="fa-kbd">Esc</kbd>
      </div>
    </div>
  </div>
</div>
```
Komponenten:
- **`.fa-kbd`** — einzelne Taste. Modifier `.fa-kbd--lg` für Hero-Slot (z. B. das `?`-Icon)
- **`.fa-kbd-combo`** — Wrapper für Zwei-Tasten-Kombination, mit `.fa-kbd-combo__plus` als visueller `+`-Separator
- **`.fa-cheatsheet`** — Modal-Overlay-Content; Header (`__header` / `__sub` / `__title`), 2-Spalten-Grid (`__grid` / `__group`), Zeilen (`__row`)

### fa-accordion — `<details>`-basierte Collapsible-Sections
Native HTML-Semantik, **kein JS nötig**. Header mit Icon · Title · Badge · Chevron (rotiert bei `[open]`). Glow-Border wenn expandiert.
```html
<div class="fa-accordion">
  <details class="fa-accordion__item">
    <summary class="fa-accordion__summary">
      <i class="fa-icon fa-icon--ui-info fa-accordion__icon"></i>
      <span class="fa-accordion__title">Wie funktioniert die Risk-Matrix?</span>
      <span class="fa-accordion__badge">3 min</span>
      <i class="fa-icon fa-icon--util-chevron-down fa-accordion__chevron"></i>
    </summary>
    <div class="fa-accordion__body">
      Likelihood × Impact ergibt einen Score von 1–25 …
    </div>
  </details>
  <details class="fa-accordion__item">…</details>
</div>
```
**Modifier:** `.fa-accordion--flush` für rahmenlose Stacks (FAQ, Sidebar-Sections). Sub-Elemente: `__item` · `__summary` · `__icon` · `__title` · `__badge` · `__chevron` · `__body`.

**Migrate from:** Bootstrap `.accordion` + `.accordion-item` + `.accordion-button`. Bootstrap-Bridge ist in `fairy-aurora-components.css` aurora-tokenisiert (alle `--bs-accordion-*` gemappt) — Bestand sieht identisch aus, neuer Code nutzt `.fa-accordion`.

---

## 7. Iconography — `.fa-icon`

**281 Klassen · 232 SVGs** (49 als Aliase auf bestehende Geometrien) in 13 Kategorien. CSS-Mask, einfärbbar via `currentColor`. Stil: Outline 1.4, monochrom.

> **Update Mai 2026:** Gate&nbsp;11 hat 64 fehlende Icons aus den Templates aufgedeckt —
> 39 davon als neue SVGs ergänzt (Quick-Wins, Stepper-Digits, ISMS-Kompositionen), 46 als Aliase
> auf bestehende Geometrien gemappt. Vollständige Galerie: [`design-system.html#icons-gallery`](design-system.html#icons-gallery) · Phase-Reviews: [`/decisions/icons-inventory.html`](../decisions/icons-inventory.html).

```html
<i class="fa-icon fa-icon--audit-trail"></i>                       <!-- 1em (Default) -->
<i class="fa-icon fa-icon--audit-trail fa-icon--20"></i>            <!-- 20px -->
<i class="fa-icon fa-icon--threat fa-icon--danger"></i>             <!-- semantisch rot -->
```

**Größen:** `--16` `--20` `--24` `--32` `--48` (sonst `1em`).
**Farben:** `--success` `--warning` `--danger` `--info` `--muted` `--primary` (Status-Icons haben RAG-Defaults).

**13 Kategorien:**

| Kategorie | Icons |
|---|---|
| Compliance | `compliance-shield` `regulator` `certificate` `attestation` `scope-statement` `soa` `gap-analysis` `control` `control-shield` |
| Audit | `audit-trail` `finding` `evidence` `sign-off` `review` `sample` `nonconformity` `corrective-action` `audit-internal` `audit-external` |
| Risk | `risk-score` `threat` `vulnerability` `mitigation` `likelihood` `impact` `residual-risk` `risk-register` `heatmap` `risk-accept` |
| Assets | `asset-server` `asset-database` `asset-cloud` `asset-endpoint` `asset-network` `asset-iot` `asset-ot` `asset-application` `data-personal` `data-confidential` |
| Identity | `user` `role` `mfa` `privileged` `sso` `group` `permission` `ui-key` |
| Policies | `policy` `sop` `contract` `nda` `version` `approval` `attachment` `archive` `document-history` |
| Incident | `incident` `breach` `escalation` `recovery` `forensics` `root-cause` `status-fire` |
| Awareness | `training` `phishing-test` `learning-path` `awareness-stat` |
| Status | `status-ok` `status-warning` `status-critical` `status-error` `status-info` `status-pending` `status-archived` `status-fire` |
| **UI · Actions** | `ui-launch` `ui-camera` `ui-upload` `ui-undo` `ui-checklist` `ui-checklist-multi` `ui-files` `ui-chat` `ui-phone` `ui-file-person` `ui-circle` `ui-key` |
| **Nav · Module** | `nav-database` `nav-speedometer` `nav-shield-check` `nav-shield-lock` `nav-palette` `nav-envelope` `nav-archive` `nav-calendar` `nav-truck` `nav-building-shield` `nav-file-earmark-text` `nav-file-earmark-spreadsheet` |
| **Util · Stepper & Files** | `util-1-circle` `util-2-circle` `util-3-circle` `util-4-circle` `util-filetype-json` `util-filetype-csv` `util-bug` `util-geo` |
| Actions | `approve` `reject` `assign` `delegate` `export` `import` `schedule` `filter` `play` `pause` |

**Flat-Namespace-Aliase** (kurze Klassen für häufige Standalone-Icons):
`bell` · `send` · `grid` · `link` · `download` · `documents` · `assets` · `clock` · `trash` · `save` · `edit` · `plus` · `check` · `shield-check` · `cpu` · `fire` — alle als <code>fa-icon--&lt;name&gt;</code>.

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
| `--cyber-cyan` / `--cyber-pink` | `--primary` / `--accent` (Aliase, @deprecated) |
| `--space-md` | `--spacing-md` (Alias, @deprecated) |
| `--radius` | `--r-lg` (Alias, @deprecated) |
| `--t-normal` | `--t-base` (Alias, @deprecated) |
| `.fairy-helper` (4 Moods) | `.fa-alva` (9 Moods) |
| `data-theme="auto"` | `data-theme="system"` |
| `.kpi-card` | `fa-feature-card` (Legacy emitiert dev-warning) |
| `.bulk-action-bar*` | `.fa-bulk-bar*` (BEM, canonical) |
| `<table class="table">` | `fa-table` macro (80+ adopted) |
| `.progress > .progress-bar` | `fa-progress` macro (54 adopted) |

Voll: [`FAIRY_AURORA_MIGRATION.md`](FAIRY_AURORA_MIGRATION.md).
12 Token-Layer-Entscheidungen aus Mai 2026: [`/decisions/index.html`](../decisions/index.html).
23-Macro-Inventar: [`/decisions/macros-inventory.html`](../decisions/macros-inventory.html).

---

## 13. Dateien

| Pfad | Zweck |
|---|---|
| `assets/styles/fairy-aurora.css` | **Tokens-Canon** — Source-of-Truth |
| `assets/styles/fairy-aurora-components.css` | Alle `.fa-*`-Komponenten |
| `assets/styles/fairy-aurora-edge.css` | Edge-Components (filter-state-chip, stepper, dropdown-panel, banner, …) |
| `assets/styles/fairy-aurora-icons.css` | 190 Icon-Mask-Klassen + Framework-Lockup |
| `colors_and_type.css` | Deprecation-Bridge — Legacy-Aliase auf Aurora |
| `assets/tokens.jsx` | JS-Tokens (D12) — `useTokens()`-Hook + Legacy `window.T` |
| `assets/icons/*.svg` | 232 Icon-Source-Files (24×24 Outline 1.4) · 281 fa-icon--* Klassen inkl. Aliase |
| `templates/form/fa_cyber_input.html.twig` | Symfony Form-Theme |
| `states/FairyCharacter.jsx` | React-Companion (9 Moods, Tokens-Props) |
| `docs/design-system.html` | Interaktive Doku (mit Icon-Gallery + Admin-Panel) |
| `admin/aurora-canon.css` | Admin-spezifische Tokens (gescoped auf `.admin-panel`, D10) |
| `admin/Admin Panel.html` | Live-Prototype (Hub + Detail-Templates) |
| `docs/FAIRY_AURORA_MIGRATION.md` | Migration v3 → v4 |
| `docs/FAIRY_AURORA_v4_ROADMAP.md` | Roadmap |
| `docs/DESIGN_SYSTEM.md` | Diese Datei |
| `decisions/index.html` | Token-Layer-Decisions (Mai 2026, alle approved) |

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

---

**Fragen?** → Design-System-Maintainer in `#aurora-design` oder PR auf `main`.
