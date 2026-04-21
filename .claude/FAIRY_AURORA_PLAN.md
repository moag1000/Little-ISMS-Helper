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
| **Total** | | **30,5** | |

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
| `public/logo.svg` | SVG 120×120, `mood=idle`, neutral | Port aus FairyCharacter.jsx |
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

## 14. Offen / erwartet

- **Weiterer Input vom User** — Screens, Pattern-Details, Content-Themes.
- **Versionierungs-Quelle:** `composer.json` version oder eine dedizierte
  Config-Konstante? Für Brand-Component-Version-Label.
- **Alva-Body-Default:** Das Logo ist Alva im `mood=idle`. Soll das Logo
  statisch (kein CSS-Animation) bleiben, oder mit sehr langsamem
  Breathing-Effect (3s-Cycle, subtil)?
