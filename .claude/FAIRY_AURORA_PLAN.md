# FairyAurora + Alva — Design-Konzept-Migration (Full-Surface)

**Erstellt:** 2026-04-21 · **Aktualisiert:** 2026-04-21 (Scope: *„ALLE UI-Items"*)
**Grundlage:** `assets/Little ISMS Helper Design System/` (Variant.jsx, dashboard/, states/, onboarding/)
**Status:** 📅 Plan — **komplett-flächige Migration** statt nur einzelner Seiten

---

## 1. Vision

Little ISMS Helper bekommt ein **kohärentes, produkt-reifes Design-Konzept
als echter Look-&-Feel-Reset**, nicht nur ein Skin-Swap:

- **FairyAurora-Palette** — cyan + violett, Light + Dark **gleichrangig
  von Anfang an** (kein „erst Light, Dark später")
- **Alva** — die Fee wird **Logo + Charakter** mit 9 Stimmungen, zentralisiert sichtbar
- **Cyberpunk-Tech-Ästhetik** subtil — notched-corners-Buttons, 4-Ecken-Tick-Marker auf Panels, SVG-Tech-Backdrop (Grid + Dots + Circuit-Traces)
- **Typografie:** Inter (Sans) + JetBrains Mono (Labels/Metadaten) — self-hosted
- **Setup-Wizard** wird zur geführten Alva-Conversation mit Typewriter-Dialog
- **Login + alle Auth-Flows** mit Alva-Fairy, Orbit-Animation, Brand-Component

Produkt-Name bleibt **Little ISMS Helper**. Die Fee heißt **Alva**. Die
Versions-Anzeige („v3.4" im Design-System) wird mit der Tool-Version
gefüllt. **Prinzipien werden konzeptionell angewandt — nicht stellenweise.**

---

## 2. Entscheidungen (fix)

1. **Scope:** Volle Full-Surface-Migration — **ALLE UI-Items**: Tokens,
   Komponenten, Shell, Character, Wizard, Login, jede Sub-Seite.
2. **Fonts:** Self-hosted (Inter + JetBrains Mono) — kein Google-CDN
3. **Modes:** Light (Default) **und** Dark **gleichwertig** — jede
   Komponente muss beide Modi korrekt rendern, bevor sie gemerged wird.
4. **PDFs:** bleiben neutral-corporate, nicht aurora
5. **Product-Name:** Little ISMS Helper bleibt — nur Fee = Alva
6. **Version:** explizit anzeigen (Mono-Label „v2.7" etc. im Brand-Block)
7. **A11y:** WCAG 2.2 AA muss erhalten bleiben — alle Animationen respektieren `prefers-reduced-motion`
8. **Alva zentralisiert:** Eine Instanz pro View, nicht mehrere
9. **Alva als Logo:** Das aktuelle `logo.svg` wird durch ein
   FairyAurora-Logo ersetzt (Alva im `mood=idle`)
10. **Harmonisierung bei Migration:** Bisher nicht-vereinheitlichte
    Komponenten werden im Zuge der Migration konsolidiert, wenn sie
    sinnvoll einem Pattern zuzuordnen sind — Design-System-Reset
    = Gelegenheit für Tech-Debt-Closure.
11. **Konzeptionelle Anwendung:** Die Gestaltungsprinzipien werden
    **systematisch** angewandt, nicht lokal gepatcht (siehe § 3).

## 3. Gestaltungsprinzipien (konzeptionell anzuwenden)

Diese 10 Prinzipien bilden das Design-Chart für ALLE UI-Items. Wenn ein
Template gegen eines dieser Prinzipien verstößt, ist das ein Migrations-
Ticket.

1. **Farb-Semantik ist konsistent:** `primary` = Aktion/Navigation,
   `accent` = Hervorhebung/Maschinell, `success/warning/danger` = Status.
   Keine dekorativen Farben mehr — jede Farbe trägt Bedeutung.
2. **Oberflächenhierarchie in 3 Ebenen:** `bg → surface → surface-2`.
   Jedes Element sitzt auf genau einer dieser 3 Ebenen. Verschachtelung
   darüber hinaus = Code-Smell.
3. **Borders erzählen Zustand:** Default `border`, Hover `border-strong`,
   Active `primary`, Error `danger`. Border-Farbe ≠ Deko.
4. **Mono-Font = Metadaten:** Alle Labels, IDs, Timestamps, Kürzel,
   Versionsangaben, Shortcuts in JetBrains Mono (10-11 px, uppercase
   mit 0.08-0.12em letter-spacing). Sans-Font nur für fließenden Text
   und Headlines.
5. **Glow signalisiert Interaktion oder Hervorhebung:** `box-shadow:
   0 0 N*px ${primary-glow}` NUR bei Emphasis, Focus, Hover-High-Intent.
   Nicht als Dauer-Deko.
6. **Corner-Tick-Marker auf semantischen Panels:** Auth-Seiten, Setup-
   Steps, Dialoge, die Aufmerksamkeit verdienen (keine random Tabellen-
   Dekoration).
7. **Notched-Corner-Buttons nur für Primary:** Visueller Wiedererkennungs-
   anker. Secondary und Link-Buttons bleiben rechteckig.
8. **KPIs folgen einem Pattern:** mono-label + big-sans-value + optional
   delta-chip + optional sparkline. Abweichungen nur mit explizitem
   Grund.
9. **Alva ist ein Charakter, kein Ornament:** Ihre Mood reagiert auf den
   Tool-Zustand. Nie dekorativ, immer kontextuell.
10. **Light + Dark sind zwei Ausdrucksformen der gleichen Idee:** nie
    „Dark ist das echte Design, Light ist Kompromiss" oder umgekehrt.
    Kontrast-Check pflichtig in beiden Modi bevor ein Element live geht.

## 4. Harmonisierungs-Agenda (Tech-Debt-Closure bei Gelegenheit)

Während der Migration werden folgende Inkonsistenzen **mitgezogen**:

- **Button-Stile:** Bootstrap `btn-primary` vs custom inline-styles →
  alle auf `CyberButton`-Pattern (primary/secondary/accent)
- **Form-Fields:** Mix aus `_form_field.html.twig` (52 Forms) und
  rohen HTML-Inputs → alles auf `_form_field.html.twig`
- **Tables:** `<table class="table">` vs `_table.html.twig`-Embed →
  Component-Variante durchsetzen
- **Modals:** Bootstrap `.modal` vs Stimulus-getriebene Custom-Modals
  (Command Palette, Preferences) → ein einheitliches `_modal`-Component
  mit 4-Corner-Tick-Markern
- **Breadcrumbs:** `{% block breadcrumb_items %}` (admin-layout) vs
  `{% include '_breadcrumb' %}` (base-layout) → **eine** Variante
  (siehe Sprint 12 Anfang der Konvergenz)
- **Page-Header:** Inline `<h1><p>` vs `_page_header.html.twig`-Include
  → alle auf Component
- **Empty-States:** alert-info-Fallback vs `_empty_state.html.twig` →
  alles auf Component (Sprint 8G bereits angefangen)
- **Progress-Bars:** Bootstrap `.progress` vs custom → aurora-gradient-
  gefüllter Progress überall
- **Badge-Variants:** `text-bg-*` Bootstrap vs manuell gestyled →
  `_badge.html.twig` + optional `_status_pill.html.twig`
- **Icon-Usage:** `bi-*` (397 Nutzungen) bleibt, aber Farben via
  `color: var(--primary)` statt `text-primary` Bootstrap-Class

---

## 3. Palette (aus `explorations/Variant.jsx` → `aurora`)

### Light Mode

```css
--bg: #f5f6fa;           --surface: #ffffff;       --surface-2: #eef0f9;
--border: #dfe3f0;       --border-strong: #b9bfd6;
--fg: #1e1b4b;           --fg-2: #4c4a73;          --fg-3: #6d6b92;
--primary: #0284c7;      --primary-hover: #0369a1;  --primary-glow: rgba(2,132,199,0.2);
--accent: #7c3aed;       --accent-glow: rgba(124,58,237,0.2);
--success: #059669;      --warning: #d97706;       --danger: #dc2626;
--fairy-primary: #0284c7; --fairy-accent: #7c3aed; --fairy-aura: #818cf8;
```

### Dark Mode

```css
--bg: #0a0e1a;           --surface: #141829;       --surface-2: #1e2139;
--border: #232845;       --border-strong: #3d4270;
--fg: #e9eaf5;           --fg-2: #b9bad4;          --fg-3: #6d6f99;
--primary: #38bdf8;      --primary-hover: #7dd3fc; --primary-glow: rgba(56,189,248,0.3);
--accent: #a78bfa;       --accent-glow: rgba(167,139,250,0.3);
--success: #34d399;      --warning: #fbbf24;       --danger: #f87171;
--fairy-primary: #38bdf8; --fairy-accent: #a78bfa; --fairy-aura: #6366f1;
```

---

## 4. Komponenten-Inventar (aus Design-System)

### Primitives
- **Brand** — ✦-Gradient-Box + „Little ISMS Helper" + Mono-Version-Label
- **CyberButton** (primary/secondary/accent)
  - Primary: notched-corner (8 px chamfer oben-links + unten-rechts), Gradient-BG, primary-glow-shadow, Mono-Uppercase-Text mit Bullet + ›
  - Secondary: transparent + border-strong
  - Accent: accent-15-bg + accent-border + accent-glow
- **CyberInput** — 4 kleine Ecken-Tick-Marker (1-px lines), Mono-uppercase-Label, optional „✦ von Alva"-Badge
- **CyberSelect** — gleicher Frame wie CyberInput + ▾-Indicator
- **ToggleCard** — Checkbox + Titel + Sub + optional Accent-Badge, Glow bei aktiviert
- **CheckRow** — links-Akzentstrip + Status-Glyph (✓/!/✕/·) + Label + Mono-Value
- **Status-Pill** — colored dot + mono-uppercase-Text + tint-bg + border

### Dashboard-Patterns
- **KPI-Card** — Label (mono 10px uppercase) + Value (sans 30px weight 600) + optional Delta-Chip (up/down icon + mono-Text) + Hint + Sparkline (SVG 140×32)
  - Emphasis-Variante: Top-Gradient-Line + primary-glow-shadow + gradient-BG
- **Sparkline** — SVG area-chart mit End-Dot + halo-circle
- **Control-Heatmap** — 7-col Grid, Zellen mit left-2px-accent-strip + Glow
- **Activity-Feed** — Icon-Chip (tinted square) + Avatar (fairy-gradient für ✦-User) + Text mit Target-bold + Mono-Timestamp
- **Task-Queue** — Priority-Dot (glow) + Titel + Due (Mono) + Owner-Initialen-Avatar

### Layout-Shell
- **Sidebar** (224 px, left) — Logo + Org-Selector + Nav-Items (mit active-gradient-bg + left-2px-accent) + Footer (Settings/Help)
- **Topbar** (52 px) — Search (with ⌘K-pill) + flex-spacer + System-Status-Pill + Bell + User-Pill-Avatar

### Setup-Wizard
- **Left-Panel** (480 px fixed) — Gradient-BG (surface → bg) + TechBackdrop + 4-Corner-Tick-Marken + Brand + OnboardingFairy + Typewriter-Alva-Dialog + Phase-Indicator
- **Right-Panel** — StepHeader + Form-Felder + NavBar
- **TechBackdrop** — SVG mit Grid + Dots + Circuit-Traces + Nodes + Scan-Line, radial-fade-mask
- **Typewriter** — Text-Animation mit blinkendem Caret
- **OnboardingFairy** — Aura-Pulse + Orbit-Dots + FairyAurora-SVG mit Mood-dependent wing-tilt
- **Flow-Array** — 12 Steps mit `{ mood, line, sub, phase }` pro Step

### Login
- **LoginFairy** — stärker als Onboarding-Fairy (kein Flow)
- Nutzt Brand + CyberInput × 2 + CyberButton + Remember-Checkbox

### Character (Alva)
- **FairyCharacter** SVG-Component mit 9 Moods: `idle / happy / thinking / focused / working / scanning / warning / celebrating / sleeping`
- Pro Mood: Aura-Farbe + Aura-Scale + Aura-Opacity + Body-Bob-Animation
- Spezialeffekte:
  - `scanning` → horizontale Scan-Line
  - `celebrating` → schnellere Wing-Flap + pink-Aura
  - `warning` → Shake-Animation + amber-Aura
  - `sleeping` → no Wing-Flap, langsames Sleep-Bob
- Parameter: `size (60-240)`, `tone (dark/light)`, `tokens { primary, accent, aura }`, `mood`

---

## 5. Aufwand & Phasen

| # | Phase | FTE-d | Abhängigkeit |
|---|-------|-------|--------------|
| **0** | **Brand-Assets & Logo** (Alva-SVG als logo.svg, Favicon, PWA-Icons 72-512, Apple-Touch, OG-Image) | 1,5 | — |
| **1** | Theme-Foundation (Tokens, Hex-Ersetzung, Aurora-Gradient-BG, Dark-Mode, Fonts, A11y-Audit) | 7,0 | 0 |
| **2** | Component-Library — *kritisch für Flächen-Abdeckung:* `_card`, `_form_field`, `_badge`, `_empty_state`, `_breadcrumb`, `_table`, `_modal`, `_alert`, `_tab`, `_pagination`, Primitives (Brand, CyberButton, CyberInput, Status-Pill) + Dashboard-Patterns (KPI-Card, Sparkline, Control-Heatmap) | 7,0 | 1 |
| **3** | Shell-Layout (Sidebar + Topbar, Mega-Menu-Integration-Plan) | 3,0 | 1, 2 |
| **4** | Alva-Character (SVG-Port, 9 Moods, zentralisierter Mount, Mood-Trigger-Regeln) | 3,0 | 1 |
| **5** | Setup-Wizard-Redesign (Left-Panel + Typewriter + TechBackdrop + Flow-Array, 12 Steps) | 4,0 | 1, 2, 4 |
| **6** | Login-Seite + weitere Auth-Flows (Password-Reset, 2FA-Challenge, Verify-Email) | 2,0 | 1, 2, 4 |
| **7** | **Universal-Coverage-Sweep** — Audit aller Templates (489+), Inline-Hex-Werte migrieren, Outlier fixen, Chart.js-Farben + Stimulus-Controller-inline-styles | 3,0 | 1-6 |
| **8** | **Responsive-Sweep** — Breakpoints, Off-Canvas-Drawer, Topbar-Kollaps, KPI-Grid-Reflow, Table-Scroll, Form-1-col-Fallback | 2,0 | 3 |
| **9** | **Charts + Dropdowns + Skeleton + Stepper** — Chart.js-Theme, Bell/⌘K/User-Menu/Tenant-Switch-Dropdowns, Skeleton-Loader, horizontaler Stepper (Incident/DPIA/Risk-Treatment) | 2,0 | 2 |
| **10** | **Tour + Error-Pages + Email + Print** — Guided-Tour-Overlay-Reskin, 404/500/403, Email-Template-Header, `@media print` Neutral-Fallback | 1,5 | 2, 4 |
| **Total** | | **36,0** | |

**Realistisch:** 5-6 Kalenderwochen mit Feedback-Zyklen.

---

## 6. Technische Umsetzung — Entscheidungen

### Self-Hosted Fonts

- Font-Files `inter-{400,500,600,700}.woff2` + `jetbrains-mono-{400,500,600}.woff2` in `public/fonts/`
- `@font-face`-Block in `assets/styles/app.css` oben
- `font-display: swap` für Performance
- Preload für Above-the-Fold-Fonts (inter-500 + inter-600)

### Alva als Twig-Macro

```twig
{% import '_components/_alva.html.twig' as alva %}
{{ alva.character(mood='idle', size=150) }}
```

- Ein Macro rendert den SVG inline (kein extra HTTP-Roundtrip)
- Mood als Parameter → CSS-Klasse am Root-Element
- 9 Keyframe-Animations in `assets/styles/alva.css`
- `@media (prefers-reduced-motion: reduce)` → alle Animationen aus

### Zentralisierte Mood-Trigger

- **Zentrales Alva-Panel** im Layout (z. B. Sidebar-Footer oder Topbar-Corner)
- Mood wird **server-seitig** (in HomeController / BaseController) ermittelt:
  - Default: `idle`
  - Wenn offene kritische Findings > 0 → `warning`
  - Wenn Import/Scan läuft → `working` (per flash-variable)
  - Während Wizard-Scan → `scanning`
  - Audit-Pass-Flash → `celebrating` (1 Page-Load)
  - Nachts (>22 Uhr lokale Zeit) → `sleeping` (Easter Egg)
- Template erhält `alva_mood` im Context, nutzt Macro

### PDFs bleiben neutral

- `templates/pdf/_base_document.html.twig` und alle PDF-Templates **nicht** anfassen
- Corporate Corporate-Farben (dezentes blau, schwarz, grau) bleiben für Print/Auditor-Gebrauch

---

## 7. A11y-Pflichten

- `prefers-reduced-motion: reduce` in **allen** Animations-Keyframes
- Kontrast-Checks: jeder Text auf jedem Background ≥ 4,5:1 (normal) bzw. 3:1 (large)
- Focus-Ring sichtbar bei Cyber-Inputs trotz clipPath
- Notched-Corners nicht für kritische Elemente (Screenreader-neutral)
- Alva-SVG mit `role="img"` + `aria-label` (z. B. „Alva signalisiert eine Warnung")
- Keyboard-Navigation: Setup-Wizard-NavBar, Toggle-Cards, Cyber-Inputs alle tab-erreichbar

---

## 8. Umsetzungs-Reihenfolge (empfohlen)

1. **FA-1 Tokens + Fonts** commit-baseline. Sofort sichtbar: Farben + Schrift.
2. **FA-2 CSS Hex-Ersetzung** + Aurora-Gradient-BG.
3. **FA-3 Alva als Twig-Macro** + zentralisiertes Mount.
4. **FA-4 Primitives** (Brand, CyberButton, CyberInput, Status-Pill) als Twig-Partials.
5. **FA-5 KPI-Card + Sparkline** als Ersatz für aktuelle Admin-Dashboard-KPIs.
6. **FA-6 Sidebar + Topbar** als neue Shell (optional Feature-Flag für schrittweisen Rollout).
7. **FA-7 Setup-Wizard-Redesign** mit Flow-Array + Typewriter.
8. **FA-8 Login-Seite**.
9. **FA-9 A11y-Audit + prefers-reduced-motion-Check**.

---

## 9. Nicht-Ziele

- **Keine komplette Rebrand auf „Alvara"** — Produktname bleibt *Little ISMS Helper*.
- **Keine PDF-Farbänderung** — Print bleibt corporate-neutral.
- **Keine Hyper-Gamification** — Alva bleibt subtil, professional mit Wink.
- **Keine mehrfachen Alva-Instanzen pro View** — eine Alva, ein Mood.
- **Keine CDN-Fonts** — self-hosted.

---

## 10. Brand-Assets (Phase 0)

**Grundsatz-Entscheidung:** **Alva ersetzt das aktuelle Logo.** Das
FairyAurora-Motiv aus `states/FairyCharacter.jsx` wird zum Brand-Logo.

Zu erzeugen:

| Asset | Format | Quelle |
|-------|--------|--------|
| `public/logo.svg` | SVG 120×120, `mood=idle`, subtil-breathing (3s-Cycle, scale 0.98→1.02, `prefers-reduced-motion: reduce` → static) | Port aus FairyCharacter.jsx |
| `public/icons/icon-{72,96,128,144,152,192,384,512}.png` | PNG, Aurora-Primary-auf-Dark-Surface | gerendert aus logo.svg |
| `public/apple-touch-icon.png` | PNG 180×180 | gerendert aus logo.svg |
| `public/favicon.ico` | Multi-Size ICO (16/32/48) | gerendert aus logo.svg |
| `public/og-image.png` | PNG 1200×630, Alva + Brand-Text | für Social-Meta |
| `public/logo-email.png` | PNG 200×60, Alva + „Little ISMS Helper" + Version | für Transaktions-Mails |

Aktuelles `logo.svg` + `logo_v2-v8.svg` werden **archiviert** unter
`public/archive/legacy-logos/`, nicht gelöscht (DSGVO-Beleg alte CI).

---

## 11. Icon-Set — Entscheidung offen

Aktueller Stand: **397 Bootstrap-Icons-Referenzen** in Templates + 104 in
Stimulus-Controllern. Design-System nutzt eigene SVG-Icons (`Icons.jsx`).

Zwei Wege:

- **A) Bootstrap Icons behalten** (aktuell) — keine Template-Änderung,
  nur Farben via CSS-Vars. 0 FTE-d Extra. **Empfohlen**, da Icon-Vielfalt
  von Bootstrap größer ist und Produkt-Scope viele spezifische Icons
  benötigt (shield, building, diagram-3 etc.).
- **B) Custom Icon-Set** aus `Icons.jsx` — einheitlicher Look, aber
  nur ~15 Icons verfügbar. Würde Neuentwicklung von ~30-40 weiteren
  Icons erfordern. +4 FTE-d.

**Entscheidung: Bootstrap Icons behalten**, Farben via
`color: var(--primary)` → matched automatisch mit Aurora-Theme.

---

## 12. Flächen-Abdeckung — kritische Hebel

Damit „ALLE UI-Items" in Aurora ankommen, muss die **Component-Layer**
vollständig aktualisiert sein — weil 80 % der Templates darauf aufbauen.
Prio-Reihenfolge:

1. **`_card.html.twig`** — migriert in 8H.4, aktuell in 2500+ Nutzungen
2. **`_form_field.html.twig`** — 52 Form-Types nutzen es
3. **`_badge.html.twig`** — breit verteilt
4. **`_empty_state.html.twig`** — 15 Index-Templates
5. **`_breadcrumb.html.twig`** — überall
6. **`_table.html.twig`** — Listen-Templates
7. **`_modal.html.twig`** — Dialogs
8. **`_page_header.html.twig`** — Page-Titel
9. **`_alert.html.twig`** — Flash-Messages
10. **Neu: `_status_pill.html.twig`** — noch nicht da, aus Design-System
11. **Neu: `_kpi_card.html.twig`** — noch nicht da, aus Dashboard-JSX

Nach Component-Update erben alle Seiten automatisch den Aurora-Look.
Universal-Coverage-Sweep (Phase 7) räumt dann die Outlier auf:
- Inline `<style>`-Blöcke in Templates
- Inline-Hex-Werte außerhalb der Components
- Chart.js-Farbschema in Portfolio-Report + Analytics
- Guided-Tour-Controller-Farben (bereits 3→2-Farben-Update geplant in Phase 1)

---

## 13. Pattern-Source-of-Truth (Referenz-Implementierungen)

Zwei Seiten des Design-Systems sind nicht nur Beispiele, sondern
**verbindliche Referenz-Implementierungen**. Wenn ein Detail
im Rest der Migration unklar ist, zählt was dort steht.

### 🎯 `onboarding/login.html` — Pattern-Source für Auth + Marketing-Screens

Definiert wie **leere, fokussierte Seiten** aussehen:
- Brand-Component oben (Alva-Gradient-Box + „Little ISMS Helper" + Version)
- LoginFairy mit Orbit-Dots + Aura-Pulse zentral
- CyberInput × N stacked mit 4-Ecken-Tick-Markern
- CyberButton primary full-width (notched corners)
- Secondary-Links klein, unten, mono-font
- TechBackdrop subtil als radial-gradient-masked SVG-Overlay
- **Anwendbar auf:** Login, Password-Reset, 2FA-Challenge, Verify-Email,
  Logout-Confirmation, Account-Recovery, Error-Pages (404/500/403)

### 🎯 `onboarding/onboarding.html` — Pattern-Source für Multi-Step-Flows

Definiert wie **geführte Prozesse mit Alva-Begleitung** aussehen:
- 2-Col Layout (Left 480px Alva-Panel / Right Step-Form)
- Alva-Mood pro Step aus Flow-Array (`{mood, line, sub, phase}`)
- Typewriter-Animation für Alva-Dialog
- Phase-Indicator (Technik / Inhalt / ...) als mono-label
- StepHeader (Schritt N/M · Kind)
- Form-Primitives (CyberInput, CyberSelect, ToggleCard, CheckRow)
- NavBar (Zurück/Weiter, disabled-state, optional secondary)
- **Anwendbar auf:** Setup-Wizard (existent), Compliance-Wizard
  (existent), Mapping-Wizard (existent), Version-Migrations-UI
  (existent), CSV-Import-UI (existent), DPIA-Assistent, Incident-
  Response-Workflow, Risk-Treatment-Workflow

### 🎯 `dashboard/dashboard.html` — Pattern-Source für Daten-reiche Seiten

Definiert wie **Übersichts-Dashboards** aussehen:
- Sidebar (224px) + Topbar (52px) Shell
- KPI-Row mit Sparkline + Delta-Chip + Emphasis-Variante
- Control-Heatmap (7-col Grid mit left-accent-strips)
- Activity-Feed (Avatar + Icon-Chip + Target-Text + Timestamp)
- Task-Queue (Priority-Dot + Owner)
- **Anwendbar auf:** Home-Dashboard, Admin-Dashboard, Framework-
  Dashboard, Portfolio-Report, Group-Report-Hub, Role-Dashboards
  (CISO/Risk-Manager/Auditor/Board), NIS2-Dashboard, DORA-Dashboard,
  BSI-Grundschutz-Check, Mapping-Hub, Reuse-Hub, jede „Übersichts"-Seite

### 🎯 `states/character-states.html` — Pattern-Source für Alva-Integration

Definiert **wann und wie Alva reagiert**:
- 9 Moods mit klarer Semantik (idle = Warten, working = Import läuft,
  scanning = Wizard-Assessment, warning = kritische Findings,
  celebrating = Audit bestanden, sleeping = Nachts, etc.)
- Animation pro Mood (breathing, bouncing, scanning, shaking, working)
- Aura-Farbton pro Mood
- **Anwendbar in:** Jedem Template, das einen Server-Zustand hat, der
  Alvas Stimmung beeinflussen kann. Zentralisierte Mount-Regel heißt:
  **eine** Alva pro View, der Mood wird vom Controller entschieden.

---

## 14. Micro-Interactions-Policy

Quelle: `assets/Little ISMS Helper Design System/states/micro-interactions.html`.
Nicht alles 1:1 übernehmen — GRC/Audit-Tool braucht professionelle
Ruhe. Grundhaltung: **Motion trägt Bedeutung, nie Dekoration.**

### 14.1 Timing-Tokens (verbindlich)

Einmal als CSS-Custom-Properties in `app.css` definieren, überall nutzen.
Keine Ad-hoc-Durations in Components.

```css
--t-instant: 80ms;   /* Focus-Ring, Button-Press, State-Flip */
--t-fast:    120ms;  /* Hover, Tooltip-Pop, kleine Transitions */
--t-base:    240ms;  /* Entry/Exit Standard, Modal-Open */
--t-slow:    360ms;  /* Toast-Slide, Section-Reveal */
--t-magic:   600ms;  /* Alva-Moment, Celebration, Dust */
--ease-out:  cubic-bezier(0.16, 1, 0.3, 1);
--ease-in-out: cubic-bezier(0.4, 0, 0.2, 1);
```

### 14.2 4 Prinzipien (verbindlich)

1. **Motion trägt Bedeutung** — jede Animation sagt etwas über
   Zustand/Fortschritt. Keine Loop-Animationen „weil es schön aussieht".
2. **Respekt vor dem Nutzer** — `prefers-reduced-motion: reduce` schaltet
   alle nicht-essentiellen Animationen ab. Funktionalität bleibt.
3. **Alva reagiert, nicht performt** — Mood-Wechsel sind Antworten auf
   Events, keine permanenten Schleifen.
4. **Timing ist Rhythmus** — konsistente Dauern über die 5 Tokens.
   Kein 150ms hier und 220ms da.

### 14.3 1:1 übernehmen

| Pattern | Wo | Warum |
|---------|----|----|
| Button-States (hover `translateY(-1px)` + primary-glow, active flat, loading-dots) | alle CyberButtons | klare Affordance, kein Over-Motion |
| Input-Focus (cyan-glow `box-shadow: 0 0 0 3px primary-glow`) | alle CyberInputs | essenzielle A11y-Affordance |
| Toggle-Check-Ripple (240ms pop + check-draw) | ToggleCard | trägt Bedeutung (Bestätigung) |
| Toast-mit-Alva-Avatar (60px) | Flash-Messages | professioneller Feedback-Kanal |
| Entry-Animations `fade-up`, `pop`, `slide` (240-360ms) | Modal, Panel, Section-Reveal | Standard-Set reicht, kein Exotik |
| Progress-Bar-Shimmer **nur wenn aktiv** | Import/Scan laufend | signalisiert „arbeitet gerade" |

### 14.4 Dämpfen / Reduzieren (too much für GRC-Tool)

| Pattern | Entscheidung | Begründung |
|---------|--------------|------------|
| Dust-Partikel (14 × animierte Sparkles) | **nur 2 Momente**: Audit-bestanden + Setup-Wizard-Complete | sonst wirkt Tool wie Spiel |
| Ping-Caret im Input | **drop** | laufender `||` im Input stört beim Tippen, unruhig |
| Orbit-Dots permanent um Alva | **nur Welcome/Setup/Login** | im Dashboard dauerhaft rotierend = Ablenkung |
| Number-Tick-Animation auf KPIs | **nur bei Delta-Change**, nicht bei jedem Page-Load | sonst flackern Dashboards bei Navigation |
| Shimmer auf Progress immer | **nur wenn `aria-busy=true`** | 0%/100% keine Animation |
| Tooltip-Pop für alle 4 Typen (hint/success/warn/guide) | **success + celebrating** animiert, **hint + warn** statisch fade-in | Warnungen dürfen nicht „hüpfen" |
| Alva-Mood-Switch mit Magic-Transition (600ms) | **nur bei semantischen Wechseln** (idle→warning), nicht bei jedem Navigations-Flash | sonst nervig |

### 14.5 Ergänzen (fehlt im Design-System, braucht aber Policy)

| Pattern | Token | Warum |
|---------|-------|----|
| Table-Row-Hover (bg `surface-2`, 120ms) | `--t-fast` | Listen brauchen Affordance |
| Modal-Backdrop-Fade (240ms) + Modal-Pop (240ms) | `--t-base` | Dialog-Konvention |
| DnD-Ghost (opacity 0.5, 80ms) + Drop-Target-Highlight (primary-glow) | `--t-instant` | SoA-Applicability, Task-Reorder |
| Skeleton-Loader-Pulse (base 1.5s) | Token-basiert | während Ajax-Loads in Tables |
| Focus-Ring explizit (`0 0 0 3px primary-glow`, 80ms) | `--t-instant` | WCAG 2.2 AA – nicht nur Hover |
| Audit-Log-Entry-Highlight (neu eingetroffener Eintrag, 2s primary-tint-fade) | `--t-magic` → `--t-slow` | Compliance-Nachvollzug |
| Nav-Active-Indicator-Slide (Left-Accent-Strip verschiebt sich bei Navigation) | `--t-base` | Orientierung |

### 14.6 Kontext-Map — welcher Kontext welche Motion-Intensität

| Kontext | Intensität | Erlaubt | Tabu |
|---------|-----------|---------|------|
| **Dashboard** | ruhig | Hover, Focus, Delta-Tick, Skeleton | Dust, Orbit-permanent, Shimmer-permanent |
| **Setup-Wizard** | lebendig | Typewriter, Orbit, Mood-Switch, Entry-Anims | Number-Tick (irrelevant hier) |
| **Login / Auth** | magisch-fokussiert | Orbit-Dots, Aura-Pulse, Focus-Glow | Dust (zu spielerisch) |
| **Formulare (CRUD)** | funktional | Focus, Toggle-Ripple, Validation-Pop | Orbit, Dust, Mood-Switch |
| **Compliance-Wizard / Mapping** | moderat | Entry-Anims, Progress-Shimmer aktiv, Scan-Line bei Alva=scanning | Celebration |
| **Incident-Response** | ernst | Focus, Hover, Toast-warn statisch | Celebration, Dust, Pop-Tooltips |
| **Audit-Pass-Flash (1x)** | feierlich | Dust (14 Partikel), Alva=celebrating 5s, Number-Tick | alles nach 5s zurück auf idle |
| **Inactivity / Nacht** | still | Alva=sleeping (sehr langsames Bob), sonst nichts | keine auto-running Motion |

### 14.7 Umsetzungs-Hinweis

Timing-Tokens + 4 Prinzipien kommen in **Phase FA-1 (Theme-Foundation)**
an Bord. Die Übernehmen/Dämpfen/Ergänzen-Tabellen sind Akzeptanz-
Kriterien für **Phase FA-2 (Component-Library)** und
**Phase FA-7 (Universal-Coverage-Sweep)** — wer eine Component baut
oder fixt, prüft gegen diese Tabellen.

---

## 15. Responsive-Policy

Tool muss auf Tablet + Handy bedienbar bleiben — auch wenn Desktop
Primär-Kontext ist (Auditor sitzt am Laptop). Breakpoints orientieren
sich an Tailwind-Konvention (unabhängig von Framework — reines CSS):

| Token | Breite | Geräte | Verhalten |
|-------|--------|--------|-----------|
| `sm` | < 640 | Phone | Single-Col, reduzierte Topbar, Sidebar = Off-Canvas |
| `md` | < 1024 | Tablet | Sidebar = Off-Canvas (Hamburger top-left), Topbar voll |
| `lg` | ≥ 1024 | Desktop | Sidebar fix 224px, volles Pattern |
| `xl` | ≥ 1280 | Wide | Setup-Wizard: Left-Panel 480px bleibt, Right-Panel max 720px |

**Transformations-Regeln:**

- **Sidebar < lg:** Off-Canvas-Drawer, Hamburger-Button in Topbar. Slide-in 240ms `var(--t-base)`. Backdrop-Fade gleiche Timing.
- **Topbar < md:** Search-Pill kollabiert zu Search-Icon (expandiert on-click), User-Pill wird Avatar-Only, System-Status-Pill in User-Dropdown verschoben.
- **KPI-Grid:** `lg` 4-col → `md` 2-col → `sm` 1-col. Emphasis-Card bleibt first.
- **Tables:** `< md` horizontal-scroll mit `overflow-x: auto` + fade-Edge-Shadow. Sticky-First-Col für ID/Title.
- **Formulare:** 2-col-Pattern → 1-col ab `md`. Buttons full-width ab `sm`.
- **Setup-Wizard:** `< lg` → Alva-Panel kollabiert zu Top-Strip (80px Höhe mit Alva mini + Phase-Indicator), Step-Form darunter full-width. Typewriter auf 1 Zeile gekürzt.
- **Dashboard-Patterns:** Control-Heatmap bleibt 7-col (scroll wenn zu eng), Activity-Feed + Task-Queue untereinander ab `md`.
- **Alva-Topbar-Mount < md:** Topbar-Alva aus, **aber** Alva mini (28px) erscheint im **Hamburger-Drawer-Header** (nur wenn Drawer offen). Mood bleibt kontextuell sichtbar, kostet aber keinen Topbar-Platz.

**Touch-Targets:** Minimum 44×44px auf `<md` — CyberButton Padding entsprechend erhöhen.

---

## 16. Chart-Theme-Spec (Chart.js)

Chart.js bleibt als Lib. Theme wird über **ein zentrales Modul**
`assets/chart-theme.js` geliefert, das Tokens aus CSS-Custom-Properties
liest — kein hard-coded Hex mehr.

### Series-Palette (max. 5 Datenreihen)

| Slot | Light | Dark | Nutzung |
|------|-------|------|---------|
| 1 | `#0284c7` primary | `#38bdf8` primary | Hauptreihe |
| 2 | `#7c3aed` accent | `#a78bfa` accent | Vergleichsreihe |
| 3 | `#059669` success | `#34d399` success | Positive |
| 4 | `#d97706` warning | `#fbbf24` warning | Aufmerksamkeit |
| 5 | `#818cf8` fairy-aura | `#6366f1` fairy-aura | Neutral-Kontrast |

Bei > 5 Reihen: Rotation mit Alpha-Verlauf (100 % → 70 % → 50 %) statt
neue Farben.

### Chart-Elemente

- **Grid:** `var(--border)`, dash `[2, 4]`, alpha 0.5
- **Axis-Text:** `var(--fg-3)`, JetBrains Mono 10px, uppercase
- **Tooltip-BG:** `var(--surface-2)`, border `var(--border)`, border-radius 6px, primary-glow-shadow
- **Legend:** `var(--fg-2)`, Inter 12px, Circle-Marker (nicht Box)
- **Area-Fill:** Gradient von Color (alpha 0.3) → transparent, vertikal
- **Bar-Radius:** 4px top-only
- **Donut-Gap:** 2px zwischen Segmenten, Innenkreis 65 %
- **Line-Tension:** 0.3 (nicht hart-eckig, nicht über-gerundet)
- **Point-Radius:** 0 default, 4 on-hover mit primary-glow-halo

### Color-Deficit-Safety

Strich-Muster pro Serie **zusätzlich** zur Farbe — essenziell für
farbsehschwache Nutzer (~8 % der Männer). Drei Aktivierungs-Modi:

- **User-Opt-in:** Account-Preference „Accessibility: Pattern-Safe Charts" (Default off — cleaner Look im Standard)
- **Auto bei Print:** `@media print` aktiviert Patterns automatisch (Schwarz-Weiß-Ausdruck sonst unlesbar)
- **Nie als globaler Default:** Charts sollen ohne Prefs-Setting sauber bleiben

Dash-Pattern pro Serie, wenn aktiv:
- Slot 1: solid
- Slot 2: `[6, 3]` dash
- Slot 3: `[2, 2]` dot
- Slot 4: `[8, 3, 2, 3]` dash-dot
- Slot 5: `[10, 5]` long-dash

### Dark-Mode-Switch ohne Chart-Re-Render

Chart-Theme nutzt CSS-Vars in `ctx.getComputedStyle()` bei jedem
Mode-Switch und ruft `chart.update('none')` auf — kein Data-Reload.

---

## 17. Mode-Switch-UI (Light/Dark/System)

### Control-Ort

Topbar-Rechts, zwischen System-Status-Pill und Bell. Icon-Button (36×36),
Icon wechselt mit State (☀ / ☾ / ⇌).

### 3-State-Toggle

- **Light** (explizit)
- **Dark** (explizit)
- **System** (folgt `prefers-color-scheme`)

**Default für neue User: Light** — Geschäftskontext konservativ, Switch
ist 1 Klick weg. `system` nur bei explizitem User-Opt-in.

Click cycled L → D → S → L.

### Persistenz

- **Logged-in:** User-Preference in DB (`user.theme_preference` Enum
  `light|dark|system`, Default `system`).
- **Anonym:** `localStorage.theme_preference`.
- **Sync:** nach Login wird localStorage-Wert in User-Preference
  gemerged (User wins bei Konflikt).

### Transition

`transition: background-color var(--t-base), color var(--t-base),
border-color var(--t-base)` auf `html`. Keine Flash-of-Unstyled-Theme
— Theme wird vor `<body>`-Render per inline-Script gesetzt.

### Ausnahmen

- PDFs: immer Light-Palette (siehe § 9 Nicht-Ziele)
- Emails: immer Light-Palette (Mail-Client-Kompatibilität)
- Print: immer Light (`@media print`, siehe § 23)

---

## 18. Guided-Tour-Integration (Reskin statt Rebuild)

Sprint 13 hat 6 rollenbasierte Touren implementiert. Logik bleibt
unverändert — nur CSS-Theme wird auf Aurora umgestellt.

### Tour-Popover

- Panel-Pattern mit 4-Corner-Tick-Markern
- BG `var(--surface-2)`, border `var(--border-strong)`, primary-glow-shadow
- Header: Alva mini (size 60, kontextueller Mood) links + Titel
- Body: Content-Slot
- Footer: „Zurück" (CyberButton secondary) + „Weiter / Fertig" (CyberButton primary) + Step-Counter mono-uppercase rechts

### Highlight-Ring

- Box-Shadow `0 0 0 3px var(--primary-glow), 0 0 12px var(--primary)` um Target-Element
- Puls-Animation 2s: `1.0 → 1.05 → 1.0` scale + opacity `0.6 → 1.0 → 0.6`
- `prefers-reduced-motion`: static Ring, kein Puls

### Backdrop

- `rgba(0,0,0,0.4)` + `backdrop-filter: blur(2px)`
- Click auf Backdrop = Tour pausieren (bestehende Logik)

### Alva-Mood-Mapping pro Tour-Schritt

Bestehendes Tour-Content-Format erhält neues optionales Feld:
```json
{ "step": 1, "target": "#kpi-row", "title": "...", "alva_mood": "focused" }
```
Default `idle` wenn nicht gesetzt. Admin-Modul (Tour-Editor) bekommt
Mood-Dropdown.

### FTE-Anteil: in FA-10 enthalten (0,5 FTE-d von 1,5)

---

## 19. Email-Template-Pattern (Hybrid-Neutral)

Transaktions-Mails sind **eigenes Render-Environment** — kein CSS-Var-
Support, unsichere Dark-Mode-Auto-Invertierung in Apple-Mail, keine
Webfonts. Strategie: **Light-only, Inline-CSS, minimaler Aurora-Akzent
im Header**.

### Struktur

```
┌─────────────────────────────┐
│ HEADER-STRIP                │  ← Aurora-Gradient (hier einzige
│ [Logo] Little ISMS Helper   │    Farbe), 80px hoch
├─────────────────────────────┤
│                             │
│ Betreff-Titel (sans 20px)   │  ← Body weiß, fg schwarz
│                             │
│ Plain-Content-Paragraphs    │
│                             │
│ [CTA-Button primary-hex]    │  ← inline-Hex aus Light-Palette
│                             │
├─────────────────────────────┤
│ Footer fg-3 klein           │  ← Impressum, Abmelden
│ Little ISMS Helper · v2.7   │  ← Mono via Georgia-Fallback
└─────────────────────────────┘
```

### Regeln

- **Fonts:** system-stack (`-apple-system, Segoe UI, Arial`), keine webfonts
- **Inline-CSS only** — kein `<style>`-Block (Gmail/Outlook strip)
- **Hex-Colors nur Light-Palette** — auch wenn Client Dark-Mode erzwingt
- **Logo:** `public/logo-email.png` (PNG fallback, nicht SVG — Outlook-Kompatibilität)
- **Keine Alva-Animation** — statisches PNG-Render von `mood=idle`
- **Max-Width 600px** — responsive bei Mobile-Clients
- **CTA-Button:** `<a>` mit inline `background`, `color`, `padding`, keine Notched-Corners (Rendering-Risk)

### Template-Scope

Betrifft: Password-Reset-Mail, 2FA-Backup-Code, Incident-Notification,
Report-Schedule, User-Invitation, Workflow-Step-Assignment,
GDPR-Data-Breach-Alert — insgesamt ~12 Templates in
`templates/email/`.

### FTE-Anteil: in FA-10 enthalten (0,5 FTE-d von 1,5)

---

## 20. Zusätzliche Components (ergänzt das Inventar § 4)

### Skeleton-Loader

- Block mit `background: var(--surface-2)` + shimmer-Gradient-Animation
- Shimmer: linear-gradient bewegt sich 1,5s von -100 % nach 100 %
- Varianten: text-line (16px hoch, 80 % breit, border-radius 4), avatar-circle, card-block (full height des Karten-Targets)
- `prefers-reduced-motion`: static BG, keine Shimmer
- Nutzung: während Ajax-Loads in Tables, Dashboard-KPIs beim ersten Load

### Date-Picker

- Bleibt nativ `<input type="date">` — keine Lib
- CSS-Override für Light/Dark-Kalender-Popup
- Label mono-uppercase wie CyberInput
- Frame = CyberInput-Pattern (4-Corner-Ticks optional)

### File-Upload / DnD-Zone

- Container = CyberInput-Frame, aber `height: 120px` minimum
- Default: Icon `bi-cloud-upload` + „Datei hierher ziehen oder klicken"
- `.dragover`: BG `primary-glow-bg` (primary + alpha 0.1) + border primary + primary-glow-shadow
- `.uploading`: Progress-Bar (Aurora-Gradient) + Dateiname mono + Cancel-X
- `.uploaded`: Success-Tint + Check-Icon + Filename + Remove-Button

### Dropdown-Panel (unified)

Gilt für Bell-Notifications, ⌘K-Search, User-Menu, Tenant-Switch:

- BG `var(--surface-2)`, border `var(--border)`, border-radius 8px
- Primary-glow-shadow subtil (`0 4px 20px rgba(2,132,199,0.08)`)
- Header: mono-uppercase-Label „BENACHRICHTIGUNGEN" / „SCHNELLSUCHE" / etc.
- Body: scrollable (`max-height: 480px`, overflow-y auto)
- Items: padding 10px 12px, hover BG `bg`, linker 2px-primary-strip on hover
- Footer (optional): „Alle anzeigen →" Link mono
- Animation: Pop (`--t-base`, scale 0.95→1, opacity 0→1)
- Position: abs-positioned unter Trigger, min-width 320px

### Stepper (horizontal)

Für Prozesse, die **nicht** Setup-Wizard-Pattern sind — also
Incident-Response, DPIA, Risk-Treatment, BCM-Exercise:

```
● ━━━ ● ━━━ ○ ━━━ ○ ━━━ ○
 1     2     3     4     5
Done  Active Pend. Pend. Pend.
```

- Dots 14×14, Connector 2px line
- States: `done` = accent-fill + check-glyph, `active` = primary-fill + primary-glow + pulse, `pending` = border-only
- Labels darunter: sans 12px, mono-10px-Step-Count darüber
- Klickbar: nur `done` + `active` (kein Skip auf future-step)
- Mobile < md: vertikal statt horizontal

### FTE-Anteil: in FA-9 enthalten (Komponenten-Pack 2,0 FTE-d)

---

## 21. Empty-State-mit-Alva (Policy)

Jede Index-Seite mit leerem Zustand erhält Alva als Fokus — nicht
dekorativ, sondern als **Ankerpunkt** für „Was jetzt?".

### Pattern

```
     ╭────────────────────╮
     │                    │
     │      [Alva 80px]   │  ← mood kontextabhängig
     │                    │
     │   Headline sans    │
     │   Subline fg-2     │
     │                    │
     │ [CyberButton primary]│ ← 1 klare CTA
     │                    │
     │  Secondary-Link    │ ← optional (z. B. „Import")
     ╰────────────────────╯
```

### Mood-Mapping

| Kontext | Mood | Beispiel |
|---------|------|----------|
| Noch nichts angelegt | `thinking` | „Noch keine Risiken erfasst" |
| Filter leer | `focused` | „Keine Treffer für aktuelle Filter" |
| Alles erledigt | `sleeping` | „Keine offenen Tasks — gut gemacht" |
| Feature nicht aktiviert | `idle` | „Modul noch nicht konfiguriert" |
| Fehler beim Laden | `warning` | „Daten konnten nicht geladen werden" |

### Topbar-Alva-Ausnahme

Auf Empty-State-Seiten **kein** Topbar-Alva-Corner — eine Alva pro
View (§ 2 Punkt 8). Content-Alva hat Vorrang.

### FTE-Anteil: in FA-2 enthalten (Component `_empty_state.html.twig`)

---

## 22. Error-Pages (404 / 500 / 403)

Pattern-Source: Login-Pattern (§ 13). Fokussiert, kein Shell-Chrome.

| Page | Alva-Mood | Headline | CTA |
|------|-----------|----------|-----|
| **404** | `thinking` | „Hmm, das finde ich nicht." | „Zum Dashboard" |
| **500** | `warning` | „Etwas ist schiefgelaufen." | „Nochmal versuchen" + Request-ID mono |
| **403** | `sleeping` | „Dafür fehlt dir die Berechtigung." | „Admin kontaktieren" |
| **Wartung** | `sleeping` | „Tool ist kurz offline." | Geschätzte Dauer mono |

- Brand-Component oben
- Alva 120px zentriert
- Request-ID (nur 500) als mono-uppercase-Label unter CTA für Support-Ticket
- Dark + Light parallel

### FTE-Anteil: in FA-10 enthalten (0,5 FTE-d von 1,5)

---

## 23. Print-Styles (non-PDF)

User kann HTML-Seite direkt drucken (Ctrl+P). Spec separate von
PDF-Templates:

```css
@media print {
  :root { /* Light-Palette fix, auch wenn Dark aktiv */ }
  .sidebar, .topbar, .alva-topbar, .tech-backdrop, .guided-tour-overlay { display: none; }
  * { animation: none !important; transition: none !important; box-shadow: none !important; }
  .content { margin: 0; max-width: 100%; }
  a::after { content: " (" attr(href) ")"; font-size: 10px; color: #666; }
  .page-break { break-after: page; }
}
```

- Alva ausgeblendet (sonst merkwürdig auf Papier)
- TechBackdrop aus, Gradient-BG aus — sauberes Weiß
- Link-URLs in Klammern hinter Link-Text (Audit-Nachvollziehbarkeit)

### FTE-Anteil: in FA-10 enthalten (kleiner Teil)

---

## 24. Roll-out-Strategie (Big-Bang)

**Entscheidung: Big-Bang, kein Feature-Flag, kein Tenant-Toggle.**

Gründe:
- Design-Reset soll eindeutig sichtbar sein — paralleler Alt/Neu-Zustand verwirrt User
- Feature-Flag-Gerüst für CSS = über-engineered
- Tenant-Toggle = multiplikativer QA-Aufwand

**Ausnahme:** Shell-Layout (FA-3 Sidebar+Topbar) läuft hinter Body-Class
`.shell-v4` kurz staged (3-5 Tage intern), dann wird Class zum Default.

**Version-Bump:** Nach allen 10 Phasen gemerged → Major-Version
**v2.7 → v3.0**. Release-Notes-Section „Design-Reset" mit
Vorher/Nachher-Screenshots. Single-Source-of-Truth der Version:
`composer.json` → muss bei jedem Phase-Merge mit-gezogen werden
(v2.7 → v2.8 → … → v3.0 final). Brand-Component + Email-Footer lesen
aus `composer.json` via Twig-Global.

**Deployment-Reihenfolge:**
1. FA-0 Brand-Assets (sichtbar: Favicon, Logo)
2. FA-1 Theme-Foundation (sichtbar: Farben, Fonts) — **ab hier ist Tool merklich anders**
3. FA-2..FA-7 inkrementell gemerged, einzelne Commits
4. FA-8..FA-10 Finale
5. v4.0-Tag + Changelog + E-Mail an User

**Kein paralleler Alt/Neu-Zustand länger als 1 Sprint.**

---

## 25. Performance-Budget

Messbare Grenzen, gegen die die Migration geprüft wird:

| Metrik | Budget (Desktop) | Budget (4G-Mobile) |
|--------|------------------|---------------------|
| First-Contentful-Paint | ≤ 1,5s | ≤ 2,5s |
| Largest-Contentful-Paint | ≤ 2,0s | ≤ 3,5s |
| Total-Blocking-Time | ≤ 200ms | ≤ 400ms |
| Cumulative-Layout-Shift | ≤ 0,05 | ≤ 0,1 |
| Font-Preload | max 2 files, ~60 KB | gleich |
| Alva-SVG inline | ≤ 8 KB gzip | gleich |
| Chart.js-Bundle | bleibt (~60 KB) | bleibt |

**Maßnahmen:**
- Font-Preload nur `inter-500` + `inter-600` Above-the-Fold
- `inter-{400,700}` + JetBrains Mono als `font-display: swap`, lazy
- Alva-SVG inline (kein HTTP-Roundtrip), aber **nur auf Views mit Alva-Mount**
- Dark-Mode-Switch: nur CSS-Var-Tausch, kein Chart-Re-Render (§ 16)
- SVG-TechBackdrop: eine Instanz gecached per background-image

**CI-Gate:** Lighthouse-Score ≥ 90 auf Login + Dashboard. Darunter = Merge-Block.

---

## 26. i18n × Typewriter × Mood-Labels

Texte sind **lokalisiert** (DE Default, EN), Timing muss beide tragen.

### Flow-Array-Struktur

```javascript
const FLOW = [
  {
    step: 1,
    mood: 'working',
    phase: 'technik',
    line_de: "Lass uns Technik-Basics klären.",
    line_en: "Let's set up the tech basics.",
    sub_de: "Dauert ~2 Minuten.",
    sub_en: "About 2 minutes."
  },
  // ...
];
```

### Typewriter-Speed

- DE: 30ms/char (etwas schneller — DE-Text ~20 % länger)
- EN: 35ms/char
- Harte Deckelung: 2,8s pro Zeile (unabhängig von Länge)
- Skip-Button „›› Überspringen" / „›› Skip" erscheint nach 500ms

### Alva-Mood-aria-labels

Alva SVG bekommt `aria-label` aus Translation-Key `alva.mood.{mood}`:
- `alva.mood.idle_de: "Alva wartet"`
- `alva.mood.warning_de: "Alva signalisiert eine Warnung"`
- etc. für alle 9 Moods × 2 Sprachen

### FTE-Anteil: in FA-5 enthalten

---

## 27. Admin-Panel-Pattern

Admin-Routen (`/admin/`, `/{locale}/admin/`) nutzen **das gleiche**
Shell + Component-Set wie User-UI — **eine** UI, keine Sub-Welt.

### Abgrenzungen (subtil)

- **Sidebar-Org-Block:** Tenant-Initialen werden durch „ADMIN"-Pill
  (accent-Farbe, mono-uppercase) ersetzt
- **Topbar-Farb-Akzent:** 2px-Top-Border in `accent` statt `primary`
  (unterschwellig „hier bist du im Admin")
- **Default-Alva-Mood:** `focused` statt `idle` (Admin = konzentrierte Arbeit)
- **Tables:** nutzen `_table.html.twig` mit `compact`-variant
  (Padding 6px/10px statt 10px/14px, keine alternate-row-BG)
- **Breadcrumb:** erste Crumb heißt „Admin", verlinkt auf `/admin/`

### Explizit **keine** Abgrenzungen

- Gleiche Schrift, gleiche Farb-Primitives
- Gleiche Buttons, gleiche Forms
- Gleiche Dark/Light-Unterstützung

---

## 28. Entscheidungen-Log (2026-04-21)

Alle ursprünglich offenen Fragen sind geklärt:

| # | Frage | Entscheidung |
|---|-------|--------------|
| 1 | Tenant-Branding erlaubt? | **Ja, minimal** — nur Tenant-Logo in Sidebar-Org-Block überschreibbar (Admin → Tenant-Settings). **Keine** Primary-Farb-Overrides (zu viele QA-Kombinationen). Aurora-Palette + Alva-Logo im Login/Email/404 bleiben **fix**. |
| 2 | Mobile-Alva? | Topbar-Alva aus `<md`, **aber** Alva mini (28px) erscheint im Hamburger-Drawer-Header wenn Drawer offen. Kontextuelle Präsenz ohne Platz-Kosten. |
| 3 | Version-Bump | **v2.7 → v3.0** (aktuell 2.7, Major-Bump = 3.0). Nicht v4.0. |
| 4 | Alva-Logo-Animation | **Subtil breathing** (3s-Cycle, scale 0.98↔1.02). `prefers-reduced-motion: reduce` → static. Favicon immer static. |
| 5 | Version-Quelle | **`composer.json`** — Single-Source-of-Truth. Bei jedem Phase-Merge mit-bumpen (v2.7 → v2.8 → … → v3.0 final). Brand + Email-Footer lesen via Twig-Global. |
| 6 | Chart-Pattern-Safety | **Opt-in** via User-Preference „Accessibility: Pattern-Safe Charts" (Default off, cleaner Look) **+ Auto-on bei Print** (`@media print`). Nie global default. |
| 7 | Dark-Mode-Default | **Light** für neue User — Geschäftskontext konservativ. Switch 1 Klick weg. `system` nur bei explizitem User-Opt-in. |

### Weiterer Input vom User

- Screens, Pattern-Details, Content-Themes, Rollen-Perspektiven.

---

## 29. Edge-Components & Flash-Bridge (Feinheiten für Kohärenz)

Vier Pattern, die häufig benutzt werden aber bisher nur implizit im Plan
waren — hier explizit als Teil von FA-2 Component-Library:

### Flash-Bridge (Symfony → Aurora-Toast)

- Bestehende `$this->addFlash('success', '...')` bleibt unverändert
- Neuer Stimulus-Controller `aurora-toast` liest `<div data-flash type="..." message="...">` und rendert Aurora-Toast (60px Alva + Text, 4s auto-hide, Close-X)
- Mood-Mapping: `success` → celebrating, `warning` → warning, `error` → warning + danger-Akzent, `info` → thinking
- Position: top-right, stacked bei mehreren
- Animation: slide-in from right (`--t-slow`), fade-out (`--t-base`)

### Global-Banner (Top-of-Page-Strip)

- Volle Breite **über** Topbar, 44px hoch
- Varianten: `info` (primary-tint), `warning` (warning-tint), `danger` (danger-tint)
- Icon + Text + optional-CTA + Close-X
- Use-Cases: Maintenance-Announcement, License-expiring, Tenant-Suspended, GDPR-Consent-Nag, Major-Version-Upgrade-Prompt
- Persistenz des Dismiss: per User + per Banner-ID in localStorage

### Accordion

- Panel mit Chevron-rotate 180° on open (`--t-base`)
- Border wird `border-strong` bei open, primary-glow bei active-section
- Kein Bootstrap-Collapse — eigener Stimulus-Controller `aurora-accordion` für konsistente Timing-Tokens
- Nutzung: Compliance-Wizard-Sections, Settings-Groups, FAQ

### Confirmation-Dialog (Destructive Actions)

- `_modal.html.twig` + `confirm-variant`
- Alva mini 60px, mood `warning`
- Headline: „Wirklich löschen?" / „Wirklich zurücksetzen?"
- Sub-Text: Konsequenz-Hinweis
- 2 Buttons: Cancel (CyberButton secondary) + Confirm (CyberButton **danger**-variant — border `danger`, text `danger`, danger-glow-on-hover)
- Optional: Typing-Confirmation („Tippe ‚LÖSCHEN' um zu bestätigen") für High-Risk (Tenant-Delete)

### Filter-Chips

- Inline-Pills unterhalb Search/Filter-Bar
- Format: `[Label: Wert × ]` — mono-uppercase-Label + sans-Wert + Close-X
- Border `border`, BG `surface-2`, hover `border-strong`
- Click × → Filter entfernt, Liste re-rendert
- „Alle löschen"-Link daneben wenn > 1 Chip aktiv

### FTE-Anteil: in FA-2 enthalten (Teil der Component-Library)
