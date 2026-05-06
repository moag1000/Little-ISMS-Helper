# Aurora v4 + Z-Index Layer-Stack — Big-Bang Refactor (Spec)

**Status:** Draft v2 · awaiting user approval
**Datum:** 2026-05-06
**Scope:** C — Audit-listed + Sweep-found extras (truly everything)
**Branch:** kein, direkt auf `main`, atomic conventional commits
**Trigger:** Audit-Reports `docs/design_system/AUDIT_z-index-layer-stack.md` + `docs/design_system/AUDIT_TODO.md` + Patch `docs/design_system/PATCH_central_design_system.md`

## 0. Operational Rules (verbindlich für jeden Commit)

**Lint-Gate pro Commit (zwingend, kein Skip):**
```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```
Erst grün, dann `git commit`. Bei Fehler: fix oder Commit zurückhalten — kein `--no-verify`.

**Visual-Regress-Gate pro Commit:**
Nach jedem Commit der CSS oder Twig anfasst (C2, C4-C14):
1. Server starten falls nicht laufend (`symfony serve`).
2. Mindestens 3 betroffene Top-Pages laden in beiden Themes (light/dark).
3. Bei sichtbarem Regress: **STOPP**, Commit-Hash + Screenshot-Beschreibung an User melden, auf Entscheidung warten. Kein selbstständiges Weitermachen.
4. Bei Pixel-Shifts ≤ 2px durch Token-Mapping (z.B. C8 border-radius 8→10): akzeptabel, im Commit-Body benennen.

**Decision-Template für "belassen oder ersetzen":**
Bei jedem hardcodierten Wert (Color/Shadow/Radius):
- **Ersetzen** wenn: Wert matched existierenden Token exakt ODER Wert ist offensichtliche Token-Substitution (z.B. `#fff` auf Brand-Background → `var(--on-primary)`).
- **Belassen mit Pflicht-Kommentar `/* design-spec: <reason> */`** wenn: singulär (1-2 Stellen app-wide), bewusst vom Token abweichend (z.B. extra-starker Glow auf Hero), oder über Gradient/Image gerendert (Kontrast braucht Hardcoded-White).
- **Niemals** kommentarlos belassen.

**BC-Alias-Cleanup (für C12/C13):**
Cleanup-Release-Trigger = wenn alle 60 Consumer-Templates auf neue `.fa-*` Klassen umgestellt sind ODER 3 Monate nach Merge (whichever first). Owner = nächster Aurora-Sprint. Tracking via Issue/TODO in `_CARD_GUIDE.md` + `_BADGE_GUIDE.md` Header.

---

## 1. Ziel

Konsolidiere drei zusammenhängende Audit-Pakete in einer geschlossenen Refactor-Welle:

- **Z-Index-Layer-Stack** vereinheitlichen (heute 13 % Token-Quote → 100 %).
- **Aurora v4 Compliance** vollständig durchziehen: alle hardcodierten Farben, Shadows, Radien, RGB-Werte auf Tokens; Macros `_card`/`_badge` auf `.fa-*`-Klassen migrieren; Dashboards auf Aurora-First-Class-Patterns.
- **Admin-Panel-Modul** ins zentrale Design-System integrieren (Living-Styleguide-Update).

Endzustand: Stylelint blockiert sowohl raw-Hex in Color-Properties (heute schon aktiv) als auch raw-Numerics in `z-index` (neu).

## 2. Sweep-Ergebnis (über Audit hinaus)

| Kategorie | Audit-Listung | Tatsächlicher Bestand | Delta |
|---|---|---|---|
| `z-index` hardcoded | ~80 | 72 | Audit ungefähr korrekt |
| `z-index` token-using | 10 | 11 | OK |
| `color: white` non-token | 1 (components.css) | 57 (7 Files) | **+56** |
| `border-radius: Npx` non-token | 1 (info-box-white) | 30+ (3 Files) | **+29** |
| `rgba(0,0,0,X)` shadows | ~17 | 100+ (17 Files) | **+83** |
| `rgba(56,189,248,…)` Leaks außer fairy-aurora.css | 0 erwähnt | 5 | **+5** |

**Bereits vorhanden** (Audit war stale):
- `--primary-rgb / -success / -warning / -danger` (light + dark) ✅
- `--shadow-up-sm / -md` ✅
- `--print-fg / -bg` ✅
- `--alva-z: 9500` ✅

→ Ein Teil der Audit-TODOs (M2 Bottom-Sheet-Tokens, M4 RGB-Tokens, M5 Print-Tokens) reduziert sich auf "vorhandene Tokens auch wirklich nutzen".

## 3. Token-Plan

### 3.1 Neue Z-Index-Tokens (zusätzlich)

In `assets/styles/fairy-aurora.css` (neue Single-Source-of-Truth für Z-Stack — heute liegen die Tokens noch in `app.css`).

```css
:root {
  --z-base:            0;
  --z-popover:         50;   /* NEU — Tooltips, Quick-Actions */
  --z-dropdown:       100;   /* HOCH von 10 */
  --z-sticky:         200;   /* HOCH von 100 */
  --z-fixed:          500;   /* Sidebar */
  --z-overlay:        900;   /* NEU — Drawer-/Mega-Menu-Backdrops */
  --z-modal-backdrop: 1000;
  --z-modal:          1001;
  --z-popover-modal:  1100;  /* NEU — Popover IN Modal */
  --z-toast:          1500;  /* RUNTER von 9999 (über Modal, unter Tour) */
  --z-tour:           2000;  /* NEU */
  --z-command:        2500;  /* NEU */
  --z-turbo-bar:    999999;
}
```

### 3.2 Konsolidierungen

- `--alva-z: var(--z-overlay)` statt freier Wert `9500`. Alva-Dock liegt damit auf 900 (unter Modal). **Bewusste Änderung** — heute liegt Dock auf 9500 (über Toast/Modal). Audit empfiehlt diese Senkung.

### 3.3 Token-Verschiebung

`--z-*` Tokens und `--alva-z` ziehen aus `assets/styles/app.css` nach `assets/styles/fairy-aurora.css` um (Single-Source-of-Truth-Prinzip aus DESIGN_SYSTEM.md §2). Siehe Risk in §6.

## 4. Refactor-Plan (atomic commits auf `main`)

Reihenfolge zwingend wegen Token-Abhängigkeiten.

### C1 — `feat(tokens): consolidate z-index stack + add --r-icon + --shadow-overlay`
- Move `--z-*` + `--alva-z` aus `app.css` → `fairy-aurora.css`.
- Add `--z-popover, --z-overlay, --z-popover-modal, --z-tour, --z-command`.
- Bump `--z-dropdown` 10→100, `--z-sticky` 100→200, `--z-toast` 9999→1500.
- Map `--alva-z: var(--z-overlay)`.
- Add `--r-icon: 8px` (für Icon-Chip-Pattern, siehe C8).
- Add `--shadow-overlay: 0 20px 60px rgba(0,0,0,0.3)` (light + dark, siehe C6).
- Add `--surface-translucent` (light/dark, siehe C11).
- App.css: alte `--z-*` Defs entfernen.

**Touch:** `assets/styles/fairy-aurora.css`, `assets/styles/app.css`.

### C2 — `refactor(styles): map all hardcoded z-index to tokens`
72 Stellen über 14 CSS-Files. JSX in `assets/Little ISMS Helper Design System/explorations/` und `docs/design_system/admin/*.jsx` sind Spec/Doku-Assets und stehen im Stylelint-ignoreFiles → **nicht angefasst** (out of scope). `admin/` (Production-Pfad) existiert nicht. Mapping:

| Heute | Neu |
|---|---|
| `9999, 9998, 9997, 9996` (Modal-Backdrops in premium.css, command-palette.css, app.css onboarding) | `var(--z-modal-backdrop)` / `var(--z-modal)` |
| `99999 !important` (mega-menu.css:133) | `var(--z-overlay)` ohne `!important` |
| `9999` (toast.css, components.css duplicates) | `var(--z-toast)` |
| `10000-10010` (guided-tour.css) | `var(--z-tour)` + `+1/+2` für sub-layers |
| `9999` (command-palette.css) | `var(--z-command)` |
| `10000` (analytics.css heat-map-tooltip) | `var(--z-popover)` |
| `1050` (fairy-aurora-edge.css prefs-sheet) | `var(--z-modal)` |
| `1000` (user-dropdown app.css:2625, components.css dropdown/tooltip) | `var(--z-dropdown)` (popover) |
| `100, 50, 10, 5` (sticky toolbars/headers über alle Files) | `var(--z-sticky)` für Sticky, `var(--z-popover)` für Tooltips, lokale `1-5` belassen wenn parent `position:relative` |

**Touch:** alle 14 CSS-Files mit `z-index:` (siehe Audit-Tabelle plus skeleton.css, fairy-aurora-responsive.css).

### C3 — `feat(stylelint): ban raw numeric z-index values`
- `.stylelintrc.js`: `'declaration-property-value-disallowed-list'.['z-index']: ['/^[0-9]+$/']`
- Run lint, expect green (nach C2).

**Touch:** `.stylelintrc.js`.

### C4 — `refactor(styles): replace KPI-tint hex with --*-tint tokens (Audit H3)`
`ui-components.css:165-186`. Audit-spec exact:
```css
.kpi-card-success .kpi-card-icon { background: var(--success-tint); }
.kpi-card-warning .kpi-card-icon { background: var(--warning-tint); }
.kpi-card-danger  .kpi-card-icon { background: var(--danger-tint); }
.kpi-card-info    .kpi-card-icon { background: var(--primary-tint); }
.kpi-card-primary .kpi-card-icon { background: var(--surface-2); }
```

**Touch:** `assets/styles/ui-components.css`.

### C5 — `refactor(styles): replace primary-rgb leaks with --primary-rgb token`
5 Stellen in `fairy-aurora-components.css` (Zeilen 50, 71, 81, 139, 2349) wo `rgba(56,189,248,…)` oder `rgba(2,132,199,…)` direkt steht. → `rgba(var(--primary-rgb), …)`.

**Touch:** `assets/styles/fairy-aurora-components.css`.

### C6 — `refactor(styles): map black-rgba shadows to shadow tokens (Audit M1+M2)`

Schritt 1 — exakte Token-Substitutionen (kein Ermessen):
| Hardcoded | Token |
|---|---|
| `0 1px 3px rgba(0,0,0,0.05)` | `var(--shadow-sm)` |
| `0 4px 12px rgba(0,0,0,0.06)` | `var(--shadow-md)` |
| `0 8px 24px rgba(0,0,0,0.08)` | `var(--shadow-lg)` |
| `0 -4px 12px rgba(0,0,0,0.06)` | `var(--shadow-up-sm)` |
| `0 -4px 20px rgba(0,0,0,0.10)` | `var(--shadow-up-md)` |

Schritt 2 — Komposition mit Glow: `box-shadow: var(--shadow-md), 0 0 30px var(--primary-glow);` (token + token).

Schritt 3 — Abweichende Stärken (Decision-Template aus §0):
- `0 20px 60px rgba(0,0,0,0.3)` (premium.css:103, command-palette.css:32): >3 Stellen → **Neuer Token `--shadow-overlay: 0 20px 60px rgba(0,0,0,0.3);`** in `fairy-aurora.css` (light + dark).
- `0 24px 72px rgba(0,0,0,0.55)` (guided-tour.css:125): singulär → **belassen mit `/* design-spec: tour-popover deep-shadow */`**.
- `text-shadow: 0 1px 2px/3px rgba(0,0,0,0.6-0.9)` (app.css hero-Text auf Image-Bg): bewusst hoher Kontrast → **belassen mit `/* design-spec: text on hero image */`**.
- Alle übrigen 0.3-0.8 Werte: case-by-case nach Decision-Template, jeder Belass-Fall braucht Kommentar.

**Touch:** alle 17 CSS-Files mit `rgba(0,0,0,…)` (siehe Sweep) + `assets/styles/fairy-aurora.css` (neuer `--shadow-overlay`).

### C7 — `refactor(styles): replace 'color: white' / '#000' with --on-* / --print-fg tokens`
57 `color: white` Stellen über 7 Files. Decision-Template aus §0, plus konkrete Mappings:

| Selektor-Klasse | Background-Context | Token |
|---|---|---|
| `.btn-primary`, `.fa-cyber-btn--primary` | Primary-Background | `var(--on-primary)` |
| `.btn-success`, `--success`-Background | Success-Background | `var(--on-success)` |
| `.btn-danger`, `--danger`-Background | Danger-Background | `var(--on-danger)` |
| `.btn-warning`, `--warning`-Background | Warning-Background | `var(--on-warning)` |
| `.fa-cyber-btn--ghost`, Accent-Buttons | Accent-Background | `var(--on-accent)` |
| Bulk-Actions-Toolbar | Accent-Background | `var(--on-accent)` |
| `@media print` Sections | Print-Context | `var(--print-fg)` |
| Hero-Text auf Image/Gradient | Image/Gradient Overlay | **belassen** mit `/* design-spec: text on hero image */` |
| Premium-Toolbar dark glow | Dark gradient | **belassen** mit `/* design-spec: premium dark toolbar */` |
| Toast/Notification text auf colored bg | Brand-colored background | `var(--on-{primary|success|danger|warning})` je nach Variante |

`#000` in guided-tour.css:101-102, 258-259 = mask-composite hack (nicht Color-Property) → unverändert lassen.

**Touch:** `app.css`, `ui-components.css`, `bulk-actions.css`, `premium.css`, `analytics.css`, `components.css`, `fairy-aurora-components.css`.

### C8 — `refactor(styles): map hardcoded border-radius to --r-* tokens`

**Vorab-Entscheidung Icon-Chip:** Aktuelle Token-Skala (6/10/14/20px) hat keinen 8px-Wert. `width:32px;height:32px;border-radius:8px` (Icon-Chip in fairy-aurora-components.css:815) ist häufiges Mini-Pattern. Neuer Token `--r-icon: 8px` wird in C1 mit angelegt (zusammen mit z-tokens), damit dieser Commit nicht erst Token-Add braucht.

30+ Stellen. Mapping:
| Hardcoded | Token | Optische Differenz |
|---|---|---|
| `2px, 3px` | `var(--r-sm)` (6px) | +3-4px (akzeptabel, sind interne Mini-Indikatoren) |
| `4px, 5px, 6px` | `var(--r-sm)` (6px) | 0-2px |
| `8px` (Icon-Chip-Pattern) | `var(--r-icon)` (8px) | 0px |
| `8px` (anderswo) | `var(--r-md)` (10px) | +2px |
| `10px, 12px` | `var(--r-md)` (10px) | 0-2px |
| `14px, 16px` | `var(--r-lg)` (14px) | 0-2px |
| `20px+` | `var(--r-xl)` (20px) | 0px |
| `999px` | `var(--r-pill)` | 0px |

**Visual-Regress-Pflicht:** Vor jedem File-Commit Screenshot des betroffenen Selektors light+dark, Vorher/Nachher-Vergleich. Pixel-Shifts ≤ 2px im Commit-Body dokumentieren mit Selektor-Liste. Bei wahrnehmbarem Sprung (z.B. Card-Corner sichtbar runder/eckiger als gewohnt) → STOPP-Regel aus §0 greift.

**Touch:** `command-palette.css`, `fairy-aurora-components.css`, `guided-tour.css`, `ui-components.css`, `assets/styles/fairy-aurora.css` (`--r-icon`).

### C9 — `refactor(styles): map dark-mode RGB hex to --primary-rgb (Audit M4)`
~25 Stellen `rgba(56,189,248,…)` in `dark-mode.css` Zeilen 95, 102, 109, 116, 234, 242, 248 etc. → `rgba(var(--primary-rgb), …)`.

**Touch:** `assets/styles/dark-mode.css`.

### C10 — `refactor(styles): print-tokens for components.css sla-timeline (Audit M5)`
`components.css:856` → `border-color: var(--print-fg); background: var(--print-bg) !important;` (`!important` bleibt — Print-Override gegen Cascade ist legitim).

**Touch:** `assets/styles/components.css`.

### C11 — `refactor(styles): info-box-white tokens (Audit M3)`
`ui-components.css:1827-1838`:
- `border-radius: 8px` → `var(--r-icon)` (8px exakt) ODER `var(--r-md)` (10px) je nach visuellem Test.
- `padding: 1.5rem` → `var(--spacing-lg)`.
- `rgba(255,255,255,0.2)` → `var(--surface-translucent)` (Token wurde in C1 angelegt: light = `rgba(255,255,255,0.2)`, dark = `rgba(255,255,255,0.06)`).

**Touch:** `assets/styles/ui-components.css` (Token-Add ist schon in C1 erledigt).

### C12 — `feat(twig): migrate _card macro to .fa-* classes with BC-Aliases (Audit H1)`
`templates/_components/_card.html.twig`:
- Klassen-Map: `default → .fa-section`, `kpi → .fa-kpi-card.fa-kpi-card--{borderColor}`, `widget → .fa-widget-card`, `feature → .fa-feature-card`.
- Sub-Klassen: `card-header → .fa-section__header`, `card-body → .fa-section__body`, `card-footer → .fa-section__footer`.
- BC: alte Klassen 1 Release lang als Aliases beibehalten (`class="card fa-section"` parallel). Deprecation-Warning im dev-Env console.
- `_CARD_GUIDE.md` aktualisieren.

**Touch:** `templates/_components/_card.html.twig`, `templates/_components/_CARD_GUIDE.md`. **Nicht angefasst:** ~60 Consumer-Templates (BC-Layer macht weiter).

### C13 — `feat(twig): migrate _badge macro to .fa-status-pill with BC-Aliases (Audit H2)`
`templates/_components/_badge.html.twig`:
- Klassen-Map: `bg-X → fa-status-pill.fa-status-pill--X`.
- Sizes: `sm → --sm`, `lg → --lg`.
- `pill: true` deprecated (Aurora-Pills sind immer pill-shaped).
- BC-Aliases.
- `_BADGE_GUIDE.md` aktualisieren.

**Touch:** `templates/_components/_badge.html.twig`, `templates/_components/_BADGE_GUIDE.md`.

### C14 — `refactor(templates): migrate dashboards to Aurora-first patterns (Audit M6)`
4 Dashboard-Files: `board.html.twig`, `auditor.html.twig`, `ciso.html.twig`, `risk_manager.html.twig`.

**Vorab-Recherche-Ergebnis:** `bg-success bg-opacity-10` rendert via Bootstrap-Utility als `rgba(var(--bs-success-rgb), 0.1)`. Alle `--bs-*-rgb` Tokens sind in `fairy-aurora.css` Z. 412-440 + 591-603 + 642-648 (light + dark + media-query) korrekt gemappt. **Keine neue `.fa-stat-tile` Komponente nötig** — bestehende BS-Utility-Pattern bleiben unverändert (Audit L3).

Pro File:
1. `style="width:NN%"` für Progress: **belassen** (legitimer dynamic value).
2. `border-success/warning/danger` auf KPI-Hero-Tiles (Audit M6.2): → `_fa_rag_card` Macro wenn Status-Semantik (RAG), oder `_fa_kpi_card` wenn nur Border-Color-Akzent.
3. `bg-success/warning/danger bg-opacity-10 rounded` Stat-Tiles: **belassen** (BS-Mapping korrekt, kein Refactor nötig).
4. KPI-Blöcke: auf `_fa_kpi_card` Macro migrieren (Macro existiert).
5. Bootstrap `text-muted/danger/warning`: **belassen** (Audit L3).
6. `<table class="table table-bordered">`: Audit M6.3 schlägt `.fa-data-table` vor — neue Komponente. **Out of Scope C14**, weil sonst eigener Komponenten-Build nötig. Hinzufügen zu Out-of-Scope §7 als Follow-up.

**Touch:** 4 Dashboard-Templates. Keine neue Komponente.

### C15 — `feat(docs): integrate admin-panel module into living-styleguide`
Per `PATCH_central_design_system.md`:
- `docs/design_system/admin-panel.css` ist schon vor Ort.
- `docs/design_system/sections/admin-panel.html` ist schon vor Ort.
- `docs/design_system/design-system.html`: `<link rel="stylesheet" href="admin-panel.css">` im `<head>`, `<aside class="ds-sidebar">` neue `Module`-Group mit 11 Anchor-Links einfügen.
- `docs/design_system/design-system.js`: `parts`-Array in `boot()` um `'sections/admin-panel.html'` erweitern (vor `sections/integration.html`).

**Touch:** `docs/design_system/design-system.html`, `docs/design_system/design-system.js`.

### C16 — `docs(design): update DESIGN_SYSTEM.md cheatsheet with new tokens`
- §5 (Radius & Shadow) um `--shadow-up-*` ergänzen.
- Neuer §5.5 (Z-Index-Stack) mit kompletter Tabelle.
- §13 (Dateien) `app.css` als z-token-Quelle entfernen.

**Touch:** `docs/design_system/DESIGN_SYSTEM.md`.

## 5. Akzeptanz-Kriterien

- [ ] `npm run stylelint` grün (inkl. neuer z-index-Regel).
- [ ] `php bin/console lint:twig templates/` grün.
- [ ] `find src -name "*.php" -print0 | xargs -0 -n1 php -l` grün (sollte unverändert sein, kein PHP-Touch).
- [ ] `php bin/phpunit` grün (kein test-relevant code).
- [ ] Living-Styleguide unter `/dev/design-system` rendert mit neuer "Module"-Sidebar-Group.
- [ ] Manueller Smoke-Test: Toggle Dark-Mode auf 4 Dashboards, kein visueller Regress.
- [ ] Manueller Smoke-Test: Modal öffnen, Toast triggern → Toast über Modal sichtbar.
- [ ] Manueller Smoke-Test: Guided-Tour starten → über Toast.
- [ ] Manueller Smoke-Test: Mega-Menu öffnen → unter Toast/Tour, über Modal-Backdrop. Kein `!important`-Overflow.
- [ ] Manueller Smoke-Test: User-Dropdown öffnen → über sticky table headers (war fail vorher).
- [ ] Token-Quote z-index: Test-Command `grep -rn "z-index:" assets/styles/ | grep -v "var(--\|fairy-aurora\.css.*--z-\|/\*" | awk -F: '$3 !~ /: *(0|1|2|3|4|5)[^0-9]/ {print}'` ergibt 0 Treffer. Erlaubt: Token-Defs in `fairy-aurora.css`; lokale stacking-context Werte 0-5 (mit Pflicht-Kommentar `/* local stacking-context */`).

## 6. Risk + Rollback

| Risk | Wahrscheinlichkeit | Mitigation |
|---|---|---|
| Alva-Dock-Senkung 9500→900 stört User-Mental-Model (Dock heute über allem) | Mittel | Vorab Screenshot dokumentieren. Bei Feedback nach Release: schneller Hot-Fix `--alva-z: var(--z-tour)` (=2000). |
| Z-Token-Verschiebung `app.css → fairy-aurora.css` bricht Build wenn Reihenfolge in `app.html.twig` falsch | Niedrig | `fairy-aurora.css` lädt schon vor `app.css` (DESIGN_SYSTEM.md §1). Verify im Commit. |
| BC-Aliases in `_card`/`_badge` rendern doppelte Klassen → CSS-Cascade-Konflikt | Mittel | C12/C13 visuell smoke-testen auf 5 Top-Consumer-Pages. Bei Regress: Aliases als `data-legacy-class` statt zusätzliche Klasse (nicht-style-aktiv). |
| 30+ `border-radius` Substitutions führen zu 1-2px Pixel-Verschiebungen (8px→10px, 4px→6px) | Hoch | Bewusst akzeptiert (Token-Discipline). Kommentar im Commit. |
| `color: white` Substitutions auf Buttons mit unklarem Background könnten Kontrast brechen | Mittel | Pro Datei review, im Zweifel `color: white` belassen mit `/* on dark gradient */` Kommentar. |
| `--surface-translucent` Token-Dark-Variante nicht visuell verifiziert | Niedrig | C11 explizit smoke-testen. |
| Stop-Bedingung: visueller Regress in einem Commit | Mittel | §0 Visual-Regress-Gate erzwingt STOPP + User-Meldung statt selbstständigem Weiterbauen. |
| Lint-Gate-Fehler nach mehreren Commits führt zu Reset-Bedarf | Niedrig | §0 Lint-Gate vor jedem Commit verhindert Akkumulation. |

**Rollback:** Atomic commits → `git revert <sha>` pro Commit. Reihenfolge invers (C16→C1). Branch-Free, deshalb pro Commit ein eigener Revert-Commit.

## 7. Out of Scope (explizit nicht in dieser Welle)

- Neue Aurora-Komponenten erfinden (sticking with existing macros).
- Refactor von Bootstrap-Color-Utilities in Templates außerhalb der 4 Dashboards (`text-muted/danger/warning` bleiben überall).
- Performance-Optimierung CSS (file-splitting, minify).
- Documentation-Site Re-Theming (admin-panel.html bleibt eigenständig).
- JSX-Refactor in `assets/Little ISMS Helper Design System/explorations/` (Spec-Assets, nicht Production).
- TypeScript/Stimulus-Controller-Touches (wären nötig falls inline `style.zIndex = X` irgendwo — Sweep negativ).
- Migration der 123 `_card`/`_badge` Consumer-Templates auf direkte `.fa-*` Klassen (BC-Layer macht weiter; Cleanup-Release per §0 BC-Alias-Cleanup-Trigger).
- Alte Token-Definitionen (z.B. `--bs-modal-zindex`) — bleiben unverändert für Bootstrap-Internals.
- `<table class="table table-bordered">` → `.fa-data-table` Migration in Dashboards (Audit M6.3) — wäre eigene Komponenten-Build, separater Refactor. Follow-up.
- Visual-Regression-Suite-Setup (z.B. Percy/Chromatic) — hier nur manuelle Smoke-Tests per §0 Visual-Regress-Gate.
- `bg-{success,warning,danger} bg-opacity-10` BS-Utilities in Dashboards — bleiben (`--bs-*-rgb` Mapping korrekt).

## 8. Aufwandsschätzung

| Commit | Schätzung | Risiko |
|---|---|---|
| C1 Tokens move/extend (incl. --r-icon, --shadow-overlay, --surface-translucent) | 45min | niedrig |
| C2 Z-Index map (72 Stellen) | 2h | mittel |
| C3 Stylelint-Regel | 15min | niedrig |
| C4 KPI-Tints | 15min | niedrig |
| C5 Primary-RGB-Leaks | 30min | niedrig |
| C6 Black-RGBA-Shadows (~80 subst, 17 Files) | 3h | mittel-hoch |
| C7 White-Color (~40 subst, 7 Files) | 1.5h | mittel |
| C8 Border-Radius (~30 subst) | 1h | mittel (1-2px shifts) |
| C9 Dark-mode RGB | 45min | niedrig |
| C10 Print-Tokens | 5min | niedrig |
| C11 Info-Box (Token nun in C1 vorhanden) | 10min | niedrig |
| C12 _card macro + BC | 1h | mittel-hoch |
| C13 _badge macro + BC | 45min | mittel |
| C14 Dashboards (4 Files, kein neuer Component) | 1h | niedrig-mittel |
| C15 Admin-Panel-Patch | 30min | niedrig |
| C16 DESIGN_SYSTEM.md | 30min | niedrig |
| Smoke-Tests + Lint-Zyklen (jeder Commit) | 3h | — |
| **Total** | **~17h** | — |

Realistisch: 2-3 Arbeitstage à 8h, plus Buffer für Visual-Regress-Findings = 3 Tage.

## 9. Referenzen

- `docs/design_system/AUDIT_z-index-layer-stack.md` (Z-Index Audit-Quelle)
- `docs/design_system/AUDIT_TODO.md` (Aurora v4 Audit-Quelle)
- `docs/design_system/PATCH_central_design_system.md` (Admin-Panel-Patch)
- `docs/design_system/DESIGN_SYSTEM.md` (Token-SoT-Doku)
- `docs/design_system/FAIRY_AURORA_v4_ROADMAP.md` (Hintergrund-Vision)
- `assets/styles/fairy-aurora.css` (Token-SoT-Code)
- `templates/_components/_CARD_GUIDE.md` (Macro-Anti-Patterns-Übersicht)
- `CLAUDE.md` § Aurora v4 Components (kanonische Macro-Liste)
