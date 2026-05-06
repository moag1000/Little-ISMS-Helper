# Aurora v4 + Z-Index Big-Bang Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Konsolidiere Z-Index-Layer-Stack (heute 13 % Token-Quote → 100 %), migriere alle hardcodierten Farben/Shadows/Radien auf Tokens, finalisiere `_card`/`_badge` Macros auf `.fa-*` Klassen, integriere Admin-Panel-Modul ins Living-Styleguide.

**Architecture:** Direkt auf `main` (kein Branch). 16 atomic Conventional Commits in fester Reihenfolge wegen Token-Abhängigkeiten. Jeder Commit grün auf Lint-Gate (Stylelint + Twig + Container). CSS/Twig-Touches zusätzlich Visual-Regress-Smoke-Test.

**Tech Stack:** Symfony 7.4, Twig 3.24, Bootstrap 5.3, Aurora-CSS-Tokens (`assets/styles/fairy-aurora.css`), Stylelint, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-05-06-aurora-v4-zindex-bigbang-design.md`

---

## Operational Rules (verbindlich für JEDE Task)

**Lint-Gate vor jedem Commit:**
```bash
npm run stylelint && \
php bin/console lint:twig templates/ && \
php bin/console lint:container
```
Nur grün → committen. Bei Fehler: fix oder Commit zurückhalten — kein `--no-verify`.

**Visual-Regress-Gate** (Tasks 2, 4-15):
1. Server starten (`symfony serve --daemon`) falls nicht laufend.
2. ≥3 betroffene Pages in light + dark öffnen.
3. Bei sichtbarem Regress: STOPP, Hash + Beschreibung an User melden, auf Entscheidung warten.
4. Pixel-Shifts ≤ 2px durch Token-Mapping → akzeptabel, im Commit-Body benennen.

**Decision-Template "belassen vs ersetzen":**
- **Ersetzen** wenn Wert exakt einem Token entspricht ODER offensichtliche Substitution (z.B. weiß auf Brand-Background → `--on-X`).
- **Belassen mit Pflicht-Kommentar `/* design-spec: <reason> */`** wenn singulär/gradient/bewusst abweichend.
- **Niemals** kommentarlos belassen.

**Commit-Format:** Conventional Commits (release-please-konform). Footer:
```
Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

---

## File Structure

**Tokens (single source of truth):**
- `assets/styles/fairy-aurora.css` — alle Tokens, Light + Dark + System-Pref-Block

**Z-Index-Refactor (14 CSS-Files):**
- `assets/styles/app.css` (Z-Token-Defs raus, Konsumenten umbiegen)
- `assets/styles/fairy-aurora-components.css`, `fairy-aurora-edge.css`, `fairy-aurora-print.css`, `fairy-aurora-responsive.css`
- `assets/styles/ui-components.css`, `components.css`, `dark-mode.css`
- `assets/styles/mega-menu.css`, `premium.css`, `keyboard-shortcuts.css`, `command-palette.css`, `guided-tour.css`, `analytics.css`, `toast.css`, `bulk-actions.css`, `alva-dock.css`, `skeleton.css`

**Color/Shadow/Radius-Refactor:** dieselben Files, plus partielle Touches in 7 Files für `color: white`.

**Twig-Macros:**
- `templates/_components/_card.html.twig` (Lücken füllen — BC-Bridge existiert schon)
- `templates/_components/_badge.html.twig` (Verifikation — Bridge existiert schon)
- `templates/_components/_CARD_GUIDE.md` (Update)
- `templates/_components/_BADGE_GUIDE.md` (Update)

**Dashboards:**
- `templates/dashboards/board.html.twig`
- `templates/dashboards/auditor.html.twig`
- `templates/dashboards/ciso.html.twig`
- `templates/dashboards/risk_manager.html.twig`

**Living-Styleguide:**
- `docs/design_system/design-system.html`
- `docs/design_system/design-system.js`

**Stylelint:**
- `.stylelintrc.js`

**Doku:**
- `docs/design_system/DESIGN_SYSTEM.md`

---

## Task 0.5: C0.5 — Stylelint Pre-Existing Cleanup (NEW)

**Context:** Lint-Gate war pre-T1 nicht grün (`npm run stylelint` exit=2, ~821 Errors aus `stylelint-config-standard 40`). Vor weiteren Commits muss Gate erst aufgeräumt werden, sonst sind nachfolgende Lint-Gates nutzlos. T1 Commit (`1ef0637e`) wurde bereits trotz roter Stylelint gelandet — pre-existing fault, akzeptabel.

**Files:**
- All `assets/styles/*.css` (auto-fix touches viele)
- `.stylelintrc.js` (suppression-list)

- [ ] **Step 1: Run stylelint --fix**

```bash
npx stylelint "assets/styles/**/*.css" --fix
```

Auto-fixt 558 von 821 Fehlern: `rgba()` → `rgb(... / X)` (color-function-alias-notation), shorthand-property-no-redundant-values, leichte Duplicates.

- [ ] **Step 2: Re-check stylelint exit code**

```bash
npx stylelint "assets/styles/**/*.css" >/dev/null 2>&1 ; echo "exit=$?"
```

Erwartet: noch immer rot (~263 Errors), aber weniger.

- [ ] **Step 3: Inventarisiere verbleibende Rule-Klassen**

```bash
npx stylelint "assets/styles/**/*.css" 2>&1 | grep -oE "[a-z-]+$" | sort | uniq -c | sort -rn | head -20
```

Häufige verbleibende Rules sind kandidatenmäßig: `no-duplicate-selectors`, `property-no-deprecated`, `no-descending-specificity`, `font-family-name-quotes`, `media-feature-range-notation`, `selector-pseudo-class-no-unknown`, `import-notation`, `at-rule-no-unknown`.

- [ ] **Step 4: Suppress legitimate noise in `.stylelintrc.js`**

Edit `.stylelintrc.js`, im `rules`-Block. Add suppression für jede verbleibende Rule mit Begründung-Kommentar:

```javascript
// Pre-existing in 17+ CSS files — suppression bis Cleanup-Sprint, nicht Refactor-Scope
'no-duplicate-selectors': null,
'property-no-deprecated': null,
'shorthand-property-no-redundant-values': null,
'color-function-alias-notation': null,  // rgba() vs rgb() — auto-fix wo möglich
'declaration-block-single-line-max-declarations': null,
'comment-whitespace-inside': null,
```

(Genaue Liste basierend auf Step 3 Output.)

- [ ] **Step 5: Re-run stylelint, expect green**

```bash
npx stylelint "assets/styles/**/*.css" >/dev/null 2>&1 ; echo "exit=$?"
```

Erwartet: `exit=0`.

- [ ] **Step 6: Lint-Gate full check**

```bash
npm run stylelint && \
php bin/console lint:twig templates/ && \
php bin/console lint:container
```

Erwartet: alle grün.

- [ ] **Step 7: Visual smoke**

Browser-Check 3 Pages light+dark — `--fix` ändert nur Schreibweise (rgba→rgb), kein visueller Effekt.

- [ ] **Step 8: Commit**

```bash
git add assets/styles/ .stylelintrc.js
git commit -m "$(cat <<'EOF'
chore(styles): stylelint --fix pre-existing rule violations + suppression

stylelint-config-standard 40 brachte ~821 Errors mit. --fix räumt 558
auf (rgba→rgb Schreibweise, shorthand, leichte Duplicates). Verbleibende
Noise-Rules (no-duplicate-selectors, property-no-deprecated etc.) sind
pre-existing — suppressed mit Begründung. Lint-Gate jetzt grün.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 1: C1 — Token-Konsolidierung

**Files:**
- Modify: `assets/styles/fairy-aurora.css` (Light-Block ~Z. 90-150, Dark-Block ~Z. 220-290, System-Pref-Block ~Z. 295-340)
- Modify: `assets/styles/app.css:4-11` (Z-Token-Defs entfernen)

- [ ] **Step 1: Read current Z-Token-Block in app.css**

```bash
sed -n '1,15p' assets/styles/app.css
```
Erwartet: `:root { --z-base: 0; --z-dropdown: 10; ... --z-turbo-bar: 999999; }`.

- [ ] **Step 2: Read current Light-Block in fairy-aurora.css around --alva-z**

```bash
grep -n "alva-z\|--shadow-up\|--print-fg\|--r-pill\|--r-xl\|--primary-rgb" assets/styles/fairy-aurora.css | head -20
```
Memorize line numbers — Token-Adds werden in dieselben Sections eingefügt.

- [ ] **Step 3: Insert new Z-Stack tokens in fairy-aurora.css Light-Block**

Find existing `--alva-z: 9500;` line. Replace with full Z-Stack block:

```css
/* Z-Index-Stack — single source of truth (war app.css:4-11) */
--z-base:            0;
--z-popover:         50;
--z-dropdown:       100;
--z-sticky:         200;
--z-fixed:          500;
--z-overlay:        900;
--z-modal-backdrop: 1000;
--z-modal:          1001;
--z-popover-modal:  1100;
--z-toast:          1500;
--z-tour:           2000;
--z-command:        2500;
--z-turbo-bar:    999999;
--alva-z:           var(--z-overlay);
```

- [ ] **Step 4: Add new design tokens (--r-icon, --shadow-overlay, --surface-translucent) in Light-Block**

Find line with `--r-pill: 999px;`. Insert after:

```css
--r-icon: 8px;
```

Find line with `--shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.08);`. Insert after:

```css
--shadow-overlay: 0 20px 60px rgba(0, 0, 0, 0.3);
```

Find line with `--print-bg: #fff;`. Insert after:

```css
--surface-translucent: rgba(255, 255, 255, 0.2);
```

- [ ] **Step 5: Add same tokens (Dark-Block + System-Pref-Block) in fairy-aurora.css**

Find Dark-Block (search for `[data-theme="dark"]`). Add at end of Dark-Block before closing `}`:

```css
--shadow-overlay: 0 20px 60px rgba(0, 0, 0, 0.55);
--surface-translucent: rgba(255, 255, 255, 0.06);
```

Find System-Pref-Block (search for `@media (prefers-color-scheme: dark)`). Add identical lines inside its `:root { ... }`.

(`--r-icon` und Z-Stack sind theme-unabhängig → bleiben in Light-Block only.)

- [ ] **Step 6: Remove Z-Token-Defs from app.css**

Edit `assets/styles/app.css:1-15`. Remove the `:root { --z-* ... }` block entirely (or leave `:root {` with non-z properties only — verify nothing else lives there).

```bash
sed -n '1,20p' assets/styles/app.css
```

If only Z-Tokens lived in that block, delete the whole `:root { … }`. If other props share the block, leave them.

- [ ] **Step 7: Verify no z-index Token-Def-Duplicates**

```bash
grep -rn "^\s*--z-" assets/styles/
```
Expected: only `assets/styles/fairy-aurora.css` lines.

- [ ] **Step 8: Run lint-gate**

```bash
npm run stylelint && \
php bin/console lint:twig templates/ && \
php bin/console lint:container
```
Expected: green.

- [ ] **Step 9: Visual smoke (no z-index consumer touched yet, but verify no breakage)**

Open `http://localhost:8000/de/dashboard` in light + dark. Scroll, open user-dropdown, open mobile sidebar (if responsive). No layout regress.

- [ ] **Step 10: Commit**

```bash
git add assets/styles/fairy-aurora.css assets/styles/app.css
git commit -m "$(cat <<'EOF'
feat(tokens): consolidate z-index stack + add --r-icon/--shadow-overlay/--surface-translucent

Move --z-* and --alva-z from app.css to fairy-aurora.css (single source of
truth per DESIGN_SYSTEM.md §2). Add --z-popover/-overlay/-popover-modal/-tour/
-command. Bump --z-dropdown 10→100, --z-sticky 100→200, --z-toast 9999→1500.
Map --alva-z → var(--z-overlay). Add --r-icon, --shadow-overlay, --surface-
translucent (referenced by C6/C8/C11).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: C2 — Z-Index Token-Mapping

**Files (14 CSS):** alle z-index-Konsumenten (siehe File Structure).

- [ ] **Step 1: Inventarisiere alle hardcoded z-index**

```bash
grep -rn "z-index:" assets/styles/ | grep -v "var(--\|/\*" > /tmp/z-todo.txt
wc -l /tmp/z-todo.txt
cat /tmp/z-todo.txt
```
Expected: 72 Stellen.

- [ ] **Step 2: Map z-index ≥ 9996 (Modal-tier hardcodes in premium.css)**

Edit `assets/styles/premium.css`. Replace:
- `z-index: 9999;` (Premium-Modal Z. 710) → `z-index: var(--z-modal);`
- `z-index: 9998;` (Notification-Modal Z. 916) → `z-index: var(--z-modal);`
- `z-index: 9997;` (Detail-Modal Z. 1227) → `z-index: var(--z-modal);`
- `z-index: 9996;` (Notification-Center-Backdrop Z. 1395) → `z-index: var(--z-modal-backdrop);`

Also `assets/styles/app.css:487` (Onboarding-Highlight-Backdrop):
- `z-index: 9998;` → `z-index: var(--z-overlay);`

`assets/styles/keyboard-shortcuts.css:55`:
- `z-index: 9998;` → `z-index: var(--z-overlay);`

- [ ] **Step 3: Map mega-menu and toast (kill !important on mega-menu)**

Edit `assets/styles/mega-menu.css:133`:
- `z-index: 99999 !important;` → `z-index: var(--z-overlay);` (NO `!important`)

Edit `assets/styles/toast.css:11`:
- `z-index: 10000;` → `z-index: var(--z-toast);`

Edit `assets/styles/components.css` (Toast-Doppeldef Z. 189):
- `z-index: 9999;` → `z-index: var(--z-toast);`

- [ ] **Step 4: Map command-palette and guided-tour**

Edit `assets/styles/command-palette.css:18`:
- `z-index: 9999;` → `z-index: var(--z-command);`

Edit `assets/styles/guided-tour.css`:
- `z-index: 10000;` (Z. 15 Backdrop) → `z-index: var(--z-tour);`
- `z-index: 10001;` (Z. 34 Highlight) → `z-index: calc(var(--z-tour) + 1);`
- `z-index: 10002;` (Z. 67 Popover) → `z-index: calc(var(--z-tour) + 2);`
- `z-index: 10010;` (Z. 220 Mobile-Bar) → `z-index: calc(var(--z-tour) + 10);`

Edit `assets/styles/analytics.css:676`:
- `z-index: 10000;` (Heat-Map-Tooltip) → `z-index: var(--z-popover);`

- [ ] **Step 5: Map Modal-tier (1000-1100)**

Edit `assets/styles/components.css`:
- `z-index: 1000;` (Modal-Wrapper Z. 65) → `z-index: var(--z-modal);`
- `z-index: 999;` (Modal-Backdrop Z. 92) → `z-index: var(--z-modal-backdrop);`
- `z-index: 1000;` (Tooltip Z. 562) → `z-index: var(--z-popover);`

Edit `assets/styles/fairy-aurora-edge.css:576`:
- `z-index: 1050;` (Prefs-Sheet) → `z-index: var(--z-modal);`

Edit `assets/styles/bulk-actions.css:12`:
- `z-index: 1000;` (Floating-Bar) → `z-index: var(--z-modal-backdrop);`

Edit `assets/styles/app.css:2625`:
- `z-index: 1000;` (.user-dropdown) → `z-index: var(--z-dropdown);`

- [ ] **Step 6: Map Sticky-tier (50-200)**

Edit `assets/styles/app.css`:
- `z-index: 100;` (Header Z. 181) → `z-index: var(--z-sticky);`
- `z-index: 100;` (Floating-Element Z. 351) → `z-index: var(--z-sticky);`
- `z-index: 50;` (Sticky-Side Z. 1132) → `z-index: var(--z-sticky);`
- `z-index: 10;` (Sticky-Toolbar Z. 1993) → `z-index: var(--z-sticky);`

Edit `assets/styles/ui-components.css` (Z. 902, 1168, 1189, 1565, 1784):
- Alle `z-index: 10;` (Sticky-Headers) → `z-index: var(--z-sticky);`

Edit `assets/styles/components.css:499`:
- `z-index: 100;` (Dropdown) → `z-index: var(--z-dropdown);`

- [ ] **Step 7: Lokale stacking-context Werte 0-5 — Pflicht-Kommentar**

Search for kleine Werte:
```bash
grep -rn "z-index: *[0-5];" assets/styles/ | grep -v "var(--\|/\*"
```

Pro Treffer: prüfen ob lokal (parent `position: relative`) — wenn ja, Inline-Kommentar:
```css
z-index: 1; /* local stacking-context */
```
Wenn body-relative → korrekten Token finden.

Wahrscheinliche Fälle (alle in lokalen Containern):
- `app.css:228, 884, 890, 906, 911, 3008, 3155, 3195`
- `fairy-aurora-components.css:115, 130, 1213, 1295, 1303, 1311, 1356, 1747, 1755, 1780, 3405`
- `mega-menu.css:343, 70`
- `analytics.css:792`

Sonderfall `app.css:767` (Onboarding-Highlight, z-index: 10): wenn Body-relative → `var(--z-overlay)`. Prüfen Kontext.

Sonderfall `fairy-aurora-components.css:3532` (Loading-Overlay, z-index: 50): vermutlich body-relative → `var(--z-popover)`.

- [ ] **Step 8: Sticky/dock files final cleanup**

Edit `assets/styles/keyboard-shortcuts.css:26`:
- `calc(var(--alva-z, 9500) - 10)` ist OK (alva-z mappt jetzt auf overlay → trigger liegt unter dock). Belassen.

Edit `assets/styles/skeleton.css` (falls z-index drin):
```bash
grep "z-index" assets/styles/skeleton.css
```
Pro Treffer ggf. `var(--z-popover)` oder lokal.

Edit `assets/styles/fairy-aurora-responsive.css` und `fairy-aurora-print.css` analog.

- [ ] **Step 9: Verify nichts hardcoded geblieben**

```bash
grep -rn "z-index:" assets/styles/ | grep -v "var(--\|fairy-aurora\.css.*--z-\|/\*\|/\* local stacking-context \*/" | awk -F: '$3 !~ /: *(0|1|2|3|4|5)[^0-9]/ {print}'
```
Expected: 0 lines (alle hardcoded migrated, alle 0-5 mit Pflicht-Kommentar).

- [ ] **Step 10: Run lint-gate**

```bash
npm run stylelint && \
php bin/console lint:twig templates/ && \
php bin/console lint:container
```

- [ ] **Step 11: Visual-Regress-Smoke (kritisch — Stack-Reorder)**

`symfony serve --daemon` falls nicht laufend.

Test-Matrix (light + dark):
- `/de/dashboard`: User-Dropdown öffnen über sticky Table. Dropdown muss überlappen.
- `/de/risks`: Modal öffnen, gleichzeitig Toast triggern. Toast über Modal.
- `/de/dev/design-system` (oder beliebige Page mit Mega-Menu): Mega-Menu öffnen → liegt unter Modal-Backdrop wenn parallel auf, über Sidebar.
- Guided-Tour starten (falls Trigger erreichbar) → über Toast.
- Command-Palette `Cmd+K` → über Tour.
- Alva-Dock sichtbar? Liegt unter Modal jetzt (war über). Bei UX-Beschwerde rollback nur Z. 173 in fairy-aurora.css: `--alva-z: 9500;` zurück.

Bei Regress: STOPP, melden.

- [ ] **Step 12: Commit**

```bash
git add assets/styles/
git commit -m "$(cat <<'EOF'
refactor(styles): map all hardcoded z-index to tokens

72 Stellen über 17 CSS-Files migriert auf --z-* Tokens. Mega-menu
'!important' entfernt. Alva-Dock auf var(--z-overlay) (war 9500 → 900,
unter Modal). User-Dropdown auf --z-dropdown. Tour-Sub-Layers via
calc(var(--z-tour) + N). Lokale stacking-context Werte 0-5 mit
Pflicht-Kommentar.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: C3 — Stylelint Z-Index-Regel

**Files:**
- Modify: `.stylelintrc.js`

- [ ] **Step 1: Read current stylelint config**

```bash
cat .stylelintrc.js
```

- [ ] **Step 2: Add z-index rule to declaration-property-value-disallowed-list**

Edit `.stylelintrc.js`. Find `'declaration-property-value-disallowed-list': {`. Add after the existing color regex entry, BEFORE closing `}`:

```javascript
'/^z-index$/': [
    '/^[0-9]+$/'
]
```

Final block looks like:
```javascript
'declaration-property-value-disallowed-list': {
    '/^(color|background|...|caret-color)$/': [
        '/#[0-9a-fA-F]{3,8}\\b/'
    ],
    '/^z-index$/': [
        '/^[0-9]+$/'
    ]
},
```

- [ ] **Step 3: Run stylelint to verify rule active and codebase clean**

```bash
npm run stylelint
```
Expected: green (Task 2 hat alle hardcoded z-index entfernt). Bei rotem Output: zurück zu Task 2 Step 9 grep, Lücken füllen.

- [ ] **Step 4: Lint-gate**

```bash
php bin/console lint:twig templates/ && \
php bin/console lint:container
```

- [ ] **Step 5: Commit**

```bash
git add .stylelintrc.js
git commit -m "$(cat <<'EOF'
feat(stylelint): ban raw numeric z-index values

Forces all z-index to use var(--z-*) tokens (defined in fairy-aurora.css).
Local stacking-contexts with values 0-5 must include /* local stacking-context */
comment to be allowed.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: C4 — KPI-Tint-Tokens

**Files:**
- Modify: `assets/styles/ui-components.css:165-186`

- [ ] **Step 1: Verify exact line numbers**

```bash
grep -n "kpi-card-icon" assets/styles/ui-components.css | head -10
```

- [ ] **Step 2: Replace hex-RGBA with token equivalents**

Find `.kpi-card-success .kpi-card-icon { background: rgba(39, 174, 96, 0.1); }` etc. Replace whole block:

```css
.kpi-card-success .kpi-card-icon { background: var(--success-tint); }
.kpi-card-warning .kpi-card-icon { background: var(--warning-tint); }
.kpi-card-danger  .kpi-card-icon { background: var(--danger-tint); }
.kpi-card-info    .kpi-card-icon { background: var(--primary-tint); }
.kpi-card-primary .kpi-card-icon { background: var(--surface-2); }
```

- [ ] **Step 3: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 4: Visual-Regress-Smoke**

Open page mit KPI-Cards (`/de/dashboard` oder Risiko-Index). Light + dark. Icon-Backgrounds dezent farbig (tint-Stärke), nicht knallig (alte hex-rgba). Bei wahrnehmbarer Verschlechterung melden.

- [ ] **Step 5: Commit**

```bash
git add assets/styles/ui-components.css
git commit -m "$(cat <<'EOF'
refactor(styles): replace KPI-icon hex-RGBA with --*-tint tokens (Audit H3)

Old hex values (rgba(39,174,96,0.1) etc) used legacy material-green/orange/
red/blue and ignored dark-mode tokens. Now uses --success-tint etc which
adapt to theme.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: C5 — Primary-RGB-Leaks

**Files:**
- Modify: `assets/styles/fairy-aurora-components.css` (Z. 50, 71, 81, 139, 2349)

- [ ] **Step 1: Inventarisiere primary-rgb-leaks**

```bash
grep -n "rgba(56, *189, *248\|rgba(2, *132, *199" assets/styles/fairy-aurora-components.css
```

- [ ] **Step 2: Replace each leak with var(--primary-rgb)**

For each line:
- `rgba(56, 189, 248, 0.35)` → `rgba(var(--primary-rgb), 0.35)`
- `rgba(2, 132, 199, 0.22)` → `rgba(var(--primary-rgb), 0.22)`
- `rgba(56, 189, 248, 0.22)` → `rgba(var(--primary-rgb), 0.22)`
- `rgba(56, 189, 248, 0.18)` → `rgba(var(--primary-rgb), 0.18)`
- `rgba(56, 189, 248, 0.1)` (Z. 2349 inset shadow) → `rgba(var(--primary-rgb), 0.1)`

- [ ] **Step 3: Verify no leaks left in this file**

```bash
grep -n "rgba(56, *189, *248\|rgba(2, *132, *199" assets/styles/fairy-aurora-components.css
```
Expected: 0 lines.

- [ ] **Step 4: Lint-gate + visual smoke**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

Light + dark: Aurora-Surfaces, Cards, Pills sehen identisch aus zu vorher (Werte sind dieselben, nur Token-basiert).

- [ ] **Step 5: Commit**

```bash
git add assets/styles/fairy-aurora-components.css
git commit -m "$(cat <<'EOF'
refactor(styles): replace primary-rgb hex leaks with --primary-rgb token

5 Stellen in fairy-aurora-components.css verwendeten rgba(56,189,248,…) oder
rgba(2,132,199,…) direkt. Jetzt rgba(var(--primary-rgb), …) für theme-
adaptive Glow-Stärken.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: C6 — Black-RGBA Shadows → Tokens

**Files:** alle 17 CSS-Files mit `rgba(0,0,0,…)` (siehe Sweep).

- [ ] **Step 1: Inventarisiere alle black-rgba shadows**

```bash
grep -rn "rgba(0, *0, *0" assets/styles/ > /tmp/shadow-todo.txt
wc -l /tmp/shadow-todo.txt
```
Expected: ~100 Stellen.

- [ ] **Step 2: Standard-Substitutionen via sed (sicher, da exakte Strings)**

```bash
# var(--shadow-sm)
grep -rln "0 1px 3px rgba(0, 0, 0, 0.05)" assets/styles/ | xargs sed -i.bak \
    's|0 1px 3px rgba(0, 0, 0, 0\.05)|var(--shadow-sm)|g'

# var(--shadow-md)
grep -rln "0 4px 12px rgba(0, 0, 0, 0.06)" assets/styles/ | xargs sed -i.bak \
    's|0 4px 12px rgba(0, 0, 0, 0\.06)|var(--shadow-md)|g'

# var(--shadow-lg)
grep -rln "0 8px 24px rgba(0, 0, 0, 0.08)" assets/styles/ | xargs sed -i.bak \
    's|0 8px 24px rgba(0, 0, 0, 0\.08)|var(--shadow-lg)|g'

# var(--shadow-up-sm)
grep -rln "0 -4px 12px rgba(0, 0, 0, 0.06)" assets/styles/ | xargs sed -i.bak \
    's|0 -4px 12px rgba(0, 0, 0, 0\.06)|var(--shadow-up-sm)|g'

# var(--shadow-up-md)
grep -rln "0 -4px 20px rgba(0, 0, 0, 0.10)" assets/styles/ | xargs sed -i.bak \
    's|0 -4px 20px rgba(0, 0, 0, 0\.10)|var(--shadow-up-md)|g'

# var(--shadow-overlay) — neu
grep -rln "0 20px 60px rgba(0, 0, 0, 0.3)" assets/styles/ | xargs sed -i.bak \
    's|0 20px 60px rgba(0, 0, 0, 0\.3)|var(--shadow-overlay)|g'

# Cleanup .bak files
find assets/styles/ -name "*.bak" -delete
```

- [ ] **Step 3: Skip token-defs in fairy-aurora.css**

Verifizieren dass die Token-Defs in `fairy-aurora.css` selbst nicht ersetzt wurden (selbst-referentiell wäre Bug):

```bash
grep -n "var(--shadow" assets/styles/fairy-aurora.css
```
Wenn `--shadow-sm: var(--shadow-sm);` o.ä. → manuell zurücksetzen auf rgba-Original. (sed sollte das nicht tun, da Token-Def-Format anders ist, aber prüfen.)

- [ ] **Step 4: EXPLICIT NO-TOUCH — slate-900 Modal-Backdrops**

Per FAIRY_AURORA_MIGRATION.md "Was als Nächstes ansteht" Block:
> Generische Modal-Backdrops (`rgba(15, 23, 42, 0.8)` in `components.css`, `command-palette.css`, `keyboard-shortcuts.css`, `guided-tour.css`) sind slate-900-Overlays, keine Brand-Farbe — bleiben so, **kein Migrations-Item**.

Diese 4 Files an `rgba(15, 23, 42, …)` NICHT anfassen. Kein Kommentar nötig (per Migration-Doc explizit dokumentiert).

```bash
# Confirm Stellen die belassen werden:
grep -rn "rgba(15, *23, *42" assets/styles/{components,command-palette,keyboard-shortcuts,guided-tour}.css
```

- [ ] **Step 5: Restliche black-rgba — Decision-Template anwenden**

```bash
grep -rn "rgba(0, *0, *0" assets/styles/ | grep -v "fairy-aurora\.css" > /tmp/shadow-rest.txt
cat /tmp/shadow-rest.txt
```

Pro verbleibende Stelle (siehe Spec §C6 Schritt 3):
- Premium.css `0 20px 60px rgba(0,0,0,0.3)` → ersetzt durch `var(--shadow-overlay)` (sollte Step 2 erledigt haben).
- guided-tour.css:125 `0 24px 72px rgba(0,0,0,0.55)` → **belassen** mit Pflicht-Kommentar:
  ```css
  box-shadow: 0 24px 72px rgba(0, 0, 0, 0.55); /* design-spec: tour-popover deep-shadow */
  ```
- app.css text-shadows auf hero-images (Z. 1390, 1443, 2084, 2095, 2115) → **belassen** mit:
  ```css
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8), …; /* design-spec: text on hero image */
  ```
- Alle weiteren case-by-case via Decision-Template.

- [ ] **Step 6: Verify all remaining black-rgba have a comment**

```bash
grep -rn "rgba(0, *0, *0" assets/styles/ | grep -v "fairy-aurora\.css\|design-spec:" 
```
Expected: 0 lines (alles entweder Token oder kommentiert). Slate-900 (`rgba(15,23,42,…)`) ist andere Pattern, davon nicht betroffen.

- [ ] **Step 6: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 7: Visual-Regress-Smoke (extensive — Shadow-Touches überall)**

Light + dark, mindestens 5 Pages:
- `/de/dashboard` — Cards, Hero, Modals
- `/de/risks` — Listen, Tabellen
- Eine Detail-Page (z.B. Risk-Show) — Cards, Buttons
- Settings-Page — Form-Inputs (Focus-Glow)
- Eine Premium-Modal triggern

Achten auf: Cards-Schatten gleich, Modal-Drop-Shadow gleich, Focus-Glow gleich. Bei sichtbarem Verlust von Tiefe → STOPP.

- [ ] **Step 8: Commit**

```bash
git add assets/styles/
git commit -m "$(cat <<'EOF'
refactor(styles): map black-rgba shadows to --shadow-* tokens (Audit M1+M2)

Standard-Werte (0.05/0.06/0.08/0.10) auf --shadow-sm/-md/-lg/-up-* gemappt.
Premium-Modal-Overlays (0.3) auf neuen --shadow-overlay. Singuläre Werte
(0.55 tour, 0.8-0.9 hero-text-shadows) belassen mit /* design-spec: ... */
Pflicht-Kommentar.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: C7 — White/Black Color → On-Tokens

**Files:** `app.css`, `ui-components.css`, `bulk-actions.css`, `premium.css`, `analytics.css`, `components.css`, `fairy-aurora-components.css`.

- [ ] **Step 1: Inventarisiere alle color: white**

```bash
grep -rn "color: *white" assets/styles/ > /tmp/white-todo.txt
wc -l /tmp/white-todo.txt
```
Expected: 57 Stellen.

- [ ] **Step 2: Pro Datei Decision-Template anwenden**

Für jede Stelle Selektor + umgebenden Kontext lesen, Mapping aus Spec §C7 Tabelle anwenden:

```bash
# Beispiel pro File: zeige Stelle + 2 Zeilen davor (für Selektor-Kontext)
for f in assets/styles/{app,ui-components,bulk-actions,premium,analytics,components,fairy-aurora-components}.css; do
    echo "=== $f ==="
    grep -n -B 2 "color: *white" "$f" | head -60
done
```

Pro Treffer entscheiden: Brand-Background (`--on-primary` etc.), Print (`--print-fg`), Hero/Gradient (belassen mit Kommentar), Toolbar (`--on-accent`).

- [ ] **Step 3: Apply substitutions per file**

Beispielhafte Substitutionen (real durchgehen, weil Selektor-abhängig):

`assets/styles/bulk-actions.css` (Z. 76, 121, 185 — Toolbar-Buttons auf Accent):
- `color: white;` → `color: var(--on-accent);`

`assets/styles/ui-components.css` (Z. 19, 24, 34 — Tag-/Pill-Komponenten auf Brand):
- `color: white;` → `color: var(--on-primary);` (oder andere on-X je nach umgebendem `background:`)

`assets/styles/premium.css` (Z. 10, 158-182 — Premium-Toolbar auf dark gradient):
- `color: white;` → belassen mit `/* design-spec: premium dark toolbar */`

`assets/styles/app.css` (Z. 260, 721 — Brand-Header):
- `color: white;` → `color: var(--on-primary);`
`assets/styles/app.css` (Z. 1361, 1386, 1432, 1442, 1534 — hero-image-text):
- `color: white;` → belassen mit `/* design-spec: text on hero image */`

`assets/styles/analytics.css` (Z. 2443-2504 — heat-map cells auf colored bg):
- `color: white;` → `color: var(--on-primary);` oder kontext-spezifisch.

- [ ] **Step 4: Verify alle whites entweder ersetzt oder kommentiert**

```bash
grep -rn "color: *white" assets/styles/ | grep -v "design-spec:\|fairy-aurora\.css"
```
Expected: 0 lines.

- [ ] **Step 5: Skip #000 in guided-tour.css mask-composite hack**

```bash
grep -n "linear-gradient(#000" assets/styles/guided-tour.css
```
Diese Zeilen unverändert — sind Mask-Hack, keine Color-Property.

- [ ] **Step 6: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 7: Visual-Regress-Smoke**

Brand-Buttons (Login-Page, primärer CTA) + Premium-Toolbar + Bulk-Actions-Bar + Hero-Banner + Heat-Map. Light + dark. Text-Kontrast erhalten.

- [ ] **Step 8: Commit**

```bash
git add assets/styles/
git commit -m "$(cat <<'EOF'
refactor(styles): replace 'color: white' with --on-* tokens

57 Stellen über 7 Files. Brand-Backgrounds → var(--on-{primary|accent|
success|danger|warning}). Text auf Hero-Images / Gradients belassen mit
/* design-spec: ... */ Pflicht-Kommentar. Mask-composite '#000' in
guided-tour.css unverändert (kein Color-Property).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: C8 — Border-Radius → Tokens

**Files:** `command-palette.css`, `fairy-aurora-components.css`, `guided-tour.css`, `ui-components.css`.

- [ ] **Step 1: Inventarisiere hardcoded radii**

```bash
grep -rn "border-radius: *[0-9]\+px" assets/styles/ | grep -v "border-radius: *0px\|fairy-aurora\.css" > /tmp/radius-todo.txt
wc -l /tmp/radius-todo.txt
cat /tmp/radius-todo.txt
```
Expected: 30+ Stellen.

- [ ] **Step 2: Mapping per Spec §C8 Tabelle**

```bash
# 999px → --r-pill
grep -rln "border-radius: *999px" assets/styles/ | xargs sed -i.bak 's|border-radius: *999px|border-radius: var(--r-pill)|g'

# 20px+ → --r-xl
grep -rln "border-radius: *20px" assets/styles/ | xargs sed -i.bak 's|border-radius: *20px|border-radius: var(--r-xl)|g'

# 14px / 16px → --r-lg
grep -rln "border-radius: *14px" assets/styles/ | xargs sed -i.bak 's|border-radius: *14px|border-radius: var(--r-lg)|g'
grep -rln "border-radius: *16px" assets/styles/ | xargs sed -i.bak 's|border-radius: *16px|border-radius: var(--r-lg)|g'

# 10px / 12px → --r-md (8px → --r-icon ODER --r-md je nach Kontext, separat behandeln)
grep -rln "border-radius: *10px" assets/styles/ | xargs sed -i.bak 's|border-radius: *10px|border-radius: var(--r-md)|g'
grep -rln "border-radius: *12px" assets/styles/ | xargs sed -i.bak 's|border-radius: *12px|border-radius: var(--r-md)|g'

# 4px / 5px / 6px → --r-sm (2px / 3px separat — können Mini-Indikatoren sein)
grep -rln "border-radius: *4px" assets/styles/ | xargs sed -i.bak 's|border-radius: *4px|border-radius: var(--r-sm)|g'
grep -rln "border-radius: *5px" assets/styles/ | xargs sed -i.bak 's|border-radius: *5px|border-radius: var(--r-sm)|g'
grep -rln "border-radius: *6px" assets/styles/ | xargs sed -i.bak 's|border-radius: *6px|border-radius: var(--r-sm)|g'

find assets/styles/ -name "*.bak" -delete
```

- [ ] **Step 3: 8px-Spezialfall (Icon-Chip vs anderswo)**

```bash
grep -rn "border-radius: *8px" assets/styles/
```

Pro Treffer manuell entscheiden:
- Wenn Icon-Chip-Pattern (`width: 32px; height: 32px;` in nahem Kontext) → `var(--r-icon)`.
- Sonst → `var(--r-md)`.

Beispiel `fairy-aurora-components.css:815`:
```css
.fa-kpi-card__icon { width: 32px; height: 32px; border-radius: var(--r-icon); }
```

- [ ] **Step 4: 2px / 3px separat**

```bash
grep -rn "border-radius: *[23]px" assets/styles/
```

Diese Mini-Indikatoren (`fairy-aurora-components.css:1326, 1370, 1612`, `command-palette.css:240`, `ui-components.css:1600`) → `var(--r-sm)` (6px). +3-4px Differenz akzeptabel (interne Mini-Bars).

- [ ] **Step 5: Verify keine hardcoded Px-Radii mehr (außer Token-Defs)**

```bash
grep -rn "border-radius: *[0-9]\+px" assets/styles/ | grep -v "fairy-aurora\.css\|/\*"
```
Expected: 0 lines.

- [ ] **Step 6: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 7: Visual-Regress-Smoke (kritisch — Pixel-Shifts)**

Pages mit vielen Cards/Buttons/Pills:
- `/de/dashboard`
- `/de/risks` (Index + Show)
- `/de/dev/design-system` (alle Komponenten in einem View)
- Command-Palette `Cmd+K`
- Guided-Tour starten falls möglich

Vergleichen mit Vorher-State (mental oder Screenshots): leichte Rundungs-Unterschiede (1-3px) erwartet, kein dramatischer Wandel. Bei sichtbarem Sprung → STOPP.

- [ ] **Step 8: Commit**

```bash
git add assets/styles/
git commit -m "$(cat <<'EOF'
refactor(styles): map hardcoded border-radius to --r-* tokens

30+ Stellen auf --r-sm/-icon/-md/-lg/-xl/-pill. Pixel-Shifts ≤ 4px
durch Token-Mapping akzeptiert (z.B. 4px→6px, 8px→8px (icon) oder 10px,
12px→10px). 8px-Icon-Chip-Pattern via neuem --r-icon token.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: C9 — Dark-Mode RGB-Mapping

**Files:**
- Modify: `assets/styles/dark-mode.css`

- [ ] **Step 1: Inventarisiere primary-rgb hardcodes in dark-mode**

```bash
grep -n "rgba(56, *189, *248" assets/styles/dark-mode.css
```
Expected: ~25 Stellen (Z. 95, 102, 109, 116, 234, 242, 248 etc.).

- [ ] **Step 2: Sed-Substitution**

```bash
sed -i.bak 's|rgba(56, *189, *248|rgba(var(--primary-rgb)|g' assets/styles/dark-mode.css
rm assets/styles/dark-mode.css.bak
```

- [ ] **Step 3: Verify keine leaks mehr**

```bash
grep -n "rgba(56, *189, *248" assets/styles/dark-mode.css
```
Expected: 0 lines.

- [ ] **Step 4: Closing-Paren prüfen — sed kann Schließparenthese verfehlen**

```bash
grep -n "rgba(var(--primary-rgb)" assets/styles/dark-mode.css | head -5
```

Expected pattern: `rgba(var(--primary-rgb), 0.15)` etc. — verify keine Doppelklammern oder Lücken.

- [ ] **Step 5: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 6: Visual-Regress-Smoke (Dark-Mode only)**

Dark-Mode aktivieren. Pages mit Cards, Borders, Buttons, Form-Focus. Cyan-Glow-Akzente identisch zu vorher (Werte unverändert, nur Token-basiert).

- [ ] **Step 7: Commit**

```bash
git add assets/styles/dark-mode.css
git commit -m "$(cat <<'EOF'
refactor(styles): map dark-mode primary RGB hex to --primary-rgb (Audit M4)

~25 Stellen rgba(56,189,248,…) → rgba(var(--primary-rgb),…). Theme-
adaptive falls Token-Wert je gechanged.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: C10 — Print-Tokens für sla-timeline

**Files:**
- Modify: `assets/styles/components.css:856` (umliegend)

- [ ] **Step 1: Find exact print-block**

```bash
grep -n "@media print\|sla-timeline" assets/styles/components.css
```

- [ ] **Step 2: Edit print-rule**

Find:
```css
@media print {
    .sla-timeline { border-color: #000; background: white !important; }
}
```

Replace with:
```css
@media print {
    .sla-timeline { border-color: var(--print-fg); background: var(--print-bg) !important; }
}
```

- [ ] **Step 3: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 4: Smoke (Print-Preview)**

Browser-Print-Preview einer Page mit `.sla-timeline` (z.B. Compliance-Report). Keine Veränderung erwartet (Token-Werte = #000/#fff).

- [ ] **Step 5: Commit**

```bash
git add assets/styles/components.css
git commit -m "$(cat <<'EOF'
refactor(styles): use --print-fg/--print-bg tokens in sla-timeline (Audit M5)

Print-context override jetzt token-basiert. !important bleibt (legitim
gegen Cascade in @media print).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: C11 — info-box-white Tokens

**Files:**
- Modify: `assets/styles/ui-components.css:1827-1838`

- [ ] **Step 1: Find info-box-white**

```bash
grep -n "info-box-white" assets/styles/ui-components.css
```

- [ ] **Step 2: Replace block**

Find:
```css
.info-box-white {
    background: rgba(255,255,255,0.2);
    padding: 1.5rem;
    border-radius: 8px;
}
```

Replace with:
```css
.info-box-white {
    background: var(--surface-translucent);
    padding: var(--spacing-lg);
    border-radius: var(--r-md);
}
```

(`--r-md` = 10px statt 8px → +2px shift, akzeptabel. Falls visuell stört, auf `var(--r-icon)` zurück.)

- [ ] **Step 3: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 4: Visual smoke**

Find consumer of `.info-box-white` (`grep -rn "info-box-white" templates/`). Open page, light + dark. Background sollte translucent bleiben (jetzt theme-adaptive: 0.2 light, 0.06 dark).

- [ ] **Step 5: Commit**

```bash
git add assets/styles/ui-components.css
git commit -m "$(cat <<'EOF'
refactor(styles): info-box-white on tokens (Audit M3)

background → --surface-translucent (theme-adaptive), padding →
--spacing-lg, border-radius → --r-md (8px→10px, +2px shift).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: C12 — _card Macro Lücken füllen

**Files:**
- Modify: `templates/_components/_card.html.twig`
- Modify: `templates/_components/_CARD_GUIDE.md`

**Status check:** Macro hat schon BC-Bridge für `kpi`, `stat`, `feature`, `default`. Lücken: `widget` (kein `.fa-widget-card`), `bordered` (kein Aurora-Pendant — bleibt Bootstrap-only).

- [ ] **Step 1: Verify status of variants in _card.html.twig**

```bash
grep -n "widget\|bordered\|fa-" templates/_components/_card.html.twig
```

- [ ] **Step 2: Add fa-widget-card to widget variant**

Edit `templates/_components/_card.html.twig` Z. 74:

Find:
```twig
{% elseif variant == 'widget' %}
    {% set cardClasses = cardClasses|merge(['widget-card']) %}
```

Replace with:
```twig
{% elseif variant == 'widget' %}
    {% set cardClasses = cardClasses|merge(['widget-card', 'fa-widget-card']) %}
```

- [ ] **Step 3: Bordered variant — Aurora-Pendant fehlt**

Edit Z. 76-77:

Find:
```twig
{% elseif variant == 'bordered' %}
    {% set cardClasses = cardClasses|merge(['card-border-left-' ~ borderColor]) %}
```

Replace with:
```twig
{% elseif variant == 'bordered' %}
    {# 'bordered' bleibt Bootstrap-only — kein Aurora-Pendant.
       Cleanup-Trigger §0 BC-Alias-Cleanup: zur Cleanup-Zeit auf .fa-section
       mit border-left-token migrieren. #}
    {% set cardClasses = cardClasses|merge(['card-border-left-' ~ borderColor]) %}
```

- [ ] **Step 4: Update _CARD_GUIDE.md status (mark BC-cleanup-trigger)**

Edit `templates/_components/_CARD_GUIDE.md`. Find existing variant table or "Anti-Patterns" section. Add at top after intro:

```markdown
## BC-Bridge Status (2026-05-06)

`_card` emittiert sowohl Bootstrap- als auch `.fa-*`-Klassen. Cleanup-Trigger
(reine `.fa-*`-Klassen) gemäß Spec `docs/superpowers/specs/2026-05-06-aurora-
v4-zindex-bigbang-design.md` §0 BC-Alias-Cleanup:

- alle 60 Consumer-Templates auf direkte `.fa-*`-Klassen migriert ODER
- 3 Monate nach 2026-05-06 (= 2026-08-06)

| Variant | Bootstrap-Klasse | Aurora-Pendant | Cleanup-Plan |
|---|---|---|---|
| `default` | `.card` | `.fa-section` | Bootstrap-Klasse drop |
| `kpi` | `.card.kpi-card.kpi-card-X` | `.fa-kpi-card.fa-kpi-card--X` | Bootstrap-Klassen drop |
| `widget` | `.card.widget-card` | `.fa-widget-card` | Bootstrap-Klassen drop |
| `feature` | `.card.feature-card` | `.fa-feature-card` | Bootstrap-Klassen drop |
| `stat` | `.card.stat-card` (DEPRECATED) | `.fa-kpi-card` | Variant entfernen, Consumer auf `kpi` migrieren |
| `bordered` | `.card.card-border-left-X` | — | Open: Aurora-Pendant designen |
```

- [ ] **Step 5: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 6: Visual-Regress-Smoke**

Open je eine Page pro Variant:
- `default`: `/de/risks` (Index)
- `kpi`: `/de/dashboard`
- `widget`: such consumer (`grep -rn "variant: 'widget'" templates/`)
- `feature`: such consumer
- `bordered`: such consumer

Light + dark. Cards rendern wie bisher. Neue `.fa-widget-card` Klasse hat noch keine eigene CSS — daher kein visueller Effekt erwartet (zukünftiger Hook).

- [ ] **Step 7: Commit**

```bash
git add templates/_components/_card.html.twig templates/_components/_CARD_GUIDE.md
git commit -m "$(cat <<'EOF'
feat(twig): complete _card macro Aurora bridge (Audit H1)

Add .fa-widget-card to 'widget' variant. Document BC-bridge status and
cleanup-trigger in _CARD_GUIDE.md per Spec §0. 'bordered' variant noted
as TODO (kein Aurora-Pendant designed).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: C13 — _badge Macro Verifikation + Severity-Mapping

**Files:**
- Modify: `templates/_components/_badge.html.twig` (Severity-Mapping NEU)
- Modify: `templates/_components/_BADGE_GUIDE.md` (Status-Doku + Severity-Tabelle)

**Status check:** Macro hat schon Bridge `bg-X + fa-status-pill + fa-status-pill--{primary|success|warning|danger|primary|neutral}`. Sizes via `fa-status-pill--sm/--lg`. `pill: true` als BC. **Neu:** Severity-Doppel-Mapping per ROADMAP Phase 5.

- [ ] **Step 1: Verify completeness**

```bash
grep -n "fa-" templates/_components/_badge.html.twig
```

Bridge-Logik schon vollständig. Severity-Mapping fehlt noch.

- [ ] **Step 1.5: Add Severity-Mapping in _badge.html.twig**

Edit `templates/_components/_badge.html.twig`. Find the `{% set faVariant = … %}` block (Z. 27-29). Wrap with severity-mapping:

```twig
{# Severity-Mapping: critical→danger, high→warning, medium→info, low→success #}
{% if variant == 'severity' and severity is defined %}
    {% set variant = {
        'critical': 'danger',
        'high':     'warning',
        'medium':   'info',
        'low':      'success'
    }[severity]|default('secondary') %}
{% endif %}

{% set faVariant = variant in ['info']                ? 'primary'
                : variant in ['secondary','light','dark'] ? 'neutral'
                : variant %}
```

Add to docblock (Z. 1-13):
```twig
 * @param string|null severity - When variant='severity', maps critical|high|medium|low → danger|warning|info|success
```

- [ ] **Step 2: Update _BADGE_GUIDE.md mit Cleanup-Trigger**

Edit `templates/_components/_BADGE_GUIDE.md`. Add after intro:

```markdown
## BC-Bridge Status (2026-05-06)

`_badge` emittiert sowohl Bootstrap- (`.badge.bg-X`) als auch Aurora-
Klassen (`.fa-status-pill.fa-status-pill--X`). Cleanup-Trigger gemäß
Spec `docs/superpowers/specs/2026-05-06-aurora-v4-zindex-bigbang-design.md`
§0 BC-Alias-Cleanup.

| Bootstrap-Variant | Aurora-Variant | Notes |
|---|---|---|
| `primary` | `--primary` | |
| `success` | `--success` | |
| `warning` | `--warning` | |
| `danger` | `--danger` | |
| `info` | `--primary` | (info → primary mapping) |
| `secondary`/`light`/`dark` | `--neutral` | (collapsed) |
| `pill: true` | (always pill-shaped) | DEPRECATED — Aurora pills always pill |
| `size: sm` | `--sm` | |
| `size: lg` | `--lg` | |

## Severity-Mapping (NEU per ROADMAP Phase 5)

Wenn `variant='severity'` mit `severity='critical|high|medium|low'`:

| severity | mapped variant | Aurora-Klasse |
|---|---|---|
| `critical` | `danger` | `.fa-status-pill--danger` |
| `high` | `warning` | `.fa-status-pill--warning` |
| `medium` | `info` | `.fa-status-pill--primary` (info → primary) |
| `low` | `success` | `.fa-status-pill--success` |

Usage:
```twig
{% include '_components/_badge.html.twig' with {
    variant: 'severity',
    severity: incident.severity,
    content: incident.severity|trans({}, 'incident')
} %}
```

Cleanup-Plan: Bootstrap-Klassen `.badge.bg-X` aus dem Macro-Output entfernen,
sobald alle Consumer-Templates direkt `.fa-status-pill`-Pattern nutzen oder
3 Monate nach 2026-05-06 (= 2026-08-06). Severity-Mapping bleibt permanent
(ist nicht BC-Layer, sondern semantischer Helper).
```

- [ ] **Step 3: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 4: Visual smoke**

Open `/de/risks` oder andere Page mit vielen Badges. Light + dark. Badges sehen identisch aus zu vorher (Bridge schon aktiv).

- [ ] **Step 5: Commit**

```bash
git add templates/_components/_badge.html.twig templates/_components/_BADGE_GUIDE.md
git commit -m "$(cat <<'EOF'
feat(twig): _badge severity-mapping + bridge doku (Audit H2 + ROADMAP Phase 5)

Bridge schon im Macro. NEU: variant='severity' + severity='critical|high|
medium|low' mapped auf danger|warning|info|success per ROADMAP Phase 5.
Doku ergänzt: Bridge-Status-Tabelle, Severity-Mapping, Cleanup-Trigger
2026-08-06 (oder Consumer-Migration).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: C14 — Dashboards auf Aurora-Patterns

**Files:**
- `templates/dashboards/board.html.twig`
- `templates/dashboards/auditor.html.twig`
- `templates/dashboards/ciso.html.twig`
- `templates/dashboards/risk_manager.html.twig`

- [ ] **Step 1: Inventarisiere Refactor-Targets pro Dashboard**

```bash
for f in templates/dashboards/{board,auditor,ciso,risk_manager}.html.twig; do
    echo "=== $f ==="
    grep -n "border-success\|border-warning\|border-danger\|class=\"card kpi" "$f" | head -20
done
```

- [ ] **Step 2: board.html.twig — RAG-Card-Migration**

Find existing patterns wie `class="h-100 card border-success"` für KPI-Tiles. Replace mit `_fa_rag_card.render()` Macro:

```twig
{% import '_components/_fa_rag_card.html.twig' as _fa_rag_card %}

{# alt #}
<div class="h-100 card border-{{ status }}">…</div>

{# neu #}
{{ _fa_rag_card.render({
    status: status == 'success' ? 'green' : (status == 'warning' ? 'amber' : 'red'),
    title: titleVar,
    detail: detailVar
}) }}
```

Pro Dashboard prüfen welche Tiles RAG-Semantik haben (Compliance-Domäne, Audit-Findings, BCM-Status). Nicht jedes border-* ist RAG.

- [ ] **Step 3: KPI-Migration auf _fa_kpi_card wo möglich**

```bash
grep -n "kpi-card\|card kpi" templates/dashboards/board.html.twig
```

Pro Block: wenn er KPI-Pattern (Zahl + Label + optional Trend) ist → migrieren auf:

```twig
{% import '_components/_fa_kpi_card.html.twig' as _fa_kpi_card %}

{{ _fa_kpi_card.render({
    variant: 'success',
    icon: 'shield-check',
    label: 'Compliance',
    value: 94,
    unit: '%',
    trend: { direction: 'up', label: '+3% YoY' }
}) }}
```

(API des Macros checken: `grep -A 30 "macro render" templates/_components/_fa_kpi_card.html.twig`)

- [ ] **Step 4: BS-Utilities belassen**

`text-muted/danger/warning` und `bg-success bg-opacity-10` bleiben unverändert per Spec §C14 + Audit L3.

- [ ] **Step 5: Per-Dashboard wiederholen**

Für `auditor.html.twig`, `ciso.html.twig`, `risk_manager.html.twig` denselben Prozess.

- [ ] **Step 6: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 7: Visual-Regress-Smoke pro Dashboard**

```bash
# Test pages (Login mit Test-User je nach Rolle nötig)
echo "Test URLs:"
echo "  /de/dashboards/board"
echo "  /de/dashboards/auditor"
echo "  /de/dashboards/ciso"
echo "  /de/dashboards/risk_manager"
```

Light + dark pro Dashboard. RAG-Tiles haben jetzt `_fa_rag_card` Look (siehe Living-Styleguide). Wenn dramatisch anders → User fragen ob OK oder rollback.

- [ ] **Step 8: Commit**

```bash
git add templates/dashboards/
git commit -m "$(cat <<'EOF'
refactor(templates): migrate dashboards to Aurora-first patterns (Audit M6)

board/auditor/ciso/risk_manager: KPI-Tiles auf _fa_kpi_card Macro,
RAG-Status-Tiles auf _fa_rag_card. BS-Utilities (text-muted, bg-success
bg-opacity-10) belassen — BS-RGB-Mapping korrekt per Audit L3.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: C15 — Admin-Panel Living-Styleguide-Patch

**Files:**
- `docs/design_system/design-system.html`
- `docs/design_system/design-system.js`

- [ ] **Step 1: Verify admin-panel.css and section already present**

```bash
ls docs/design_system/admin-panel.css docs/design_system/sections/admin-panel.html
```
Expected: beide existieren.

- [ ] **Step 2: Add stylesheet link in design-system.html**

Edit `docs/design_system/design-system.html`. Find `<head>` block, search for `<link rel="stylesheet" href="design-system.css">`. Add immediately after:

```html
<link rel="stylesheet" href="admin-panel.css">
```

- [ ] **Step 3: Add Module nav-group**

Edit `docs/design_system/design-system.html`. Find `<aside class="ds-sidebar">`. Search for closing `</div>` of `Komponenten`-Group. After it, before next group, insert:

```html
<div class="ds-nav__group">
    <div class="ds-nav__label">Module</div>
    <a href="#admin-intro"       class="ds-nav__link">Admin · Übersicht</a>
    <a href="#admin-ia"          class="ds-nav__link">Admin · IA</a>
    <a href="#admin-hub-card"    class="ds-nav__link">Admin · Hub-Card</a>
    <a href="#admin-page-layout" class="ds-nav__link">Admin · Detail-Seite</a>
    <a href="#admin-perm-matrix" class="ds-nav__link">Permission-Matrix</a>
    <a href="#admin-audit-row"   class="ds-nav__link">Audit-Log-Zeile</a>
    <a href="#admin-api-key"     class="ds-nav__link">API-Key-Zeile</a>
    <a href="#admin-health"      class="ds-nav__link">Service-Tile</a>
    <a href="#admin-skeleton"    class="ds-nav__link">Skeletons</a>
    <a href="#admin-confirm"     class="ds-nav__link">Confirm-Dialog</a>
    <a href="#admin-integration" class="ds-nav__link">Repo-Integration</a>
</div>
```

- [ ] **Step 4: Add admin-panel.html to parts array in design-system.js**

Edit `docs/design_system/design-system.js`. Find `boot()` function, search for `const parts = [`. Add `'sections/admin-panel.html',` BEFORE `'sections/integration.html'`:

```javascript
const parts = [
    'sections/tokens.html',
    'sections/components.html',
    'sections/components-extra.html',
    'sections/components-layout.html',
    'sections/icons.html',
    'sections/entity-patterns.html',
    'sections/admin-panel.html',
    'sections/alva.html',
    'sections/variants-matrix.html',
    'sections/integration.html',
];
```

- [ ] **Step 5: Smoke-Test (Living-Styleguide rendern)**

```bash
# Find dev-route or open static file
ls templates/dev/design_system.html.twig
```

Open `http://localhost:8000/de/dev/design-system` (oder static `docs/design_system/design-system.html`):
1. Sidebar zeigt neue `Module`-Group mit 11 Links.
2. `Admin · Übersicht` anklicken → Section unter `#admin-intro` rendert.
3. Theme-Toggle funktioniert auf Admin-Komponenten.

- [ ] **Step 6: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 7: Commit**

```bash
git add docs/design_system/design-system.html docs/design_system/design-system.js
git commit -m "$(cat <<'EOF'
feat(docs): integrate admin-panel module into living-styleguide

Per PATCH_central_design_system.md: stylesheet-link, sidebar nav-group
'Module' mit 11 Anchor-Links, parts-array um sections/admin-panel.html
erweitert. admin-panel.css und section-html schon vor Ort.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 16: C16 — DESIGN_SYSTEM.md Cheatsheet-Update

**Files:**
- `docs/design_system/DESIGN_SYSTEM.md`

- [ ] **Step 1: Update §5 (Radius & Shadow) mit --shadow-up-* + --shadow-overlay**

Edit `docs/design_system/DESIGN_SYSTEM.md`. Find `## 5. Radius & Shadow`. Replace shadow-section with:

```markdown
## 5. Radius & Shadow

```
--r-sm: 6px    --r-icon: 8px    --r-md: 10px    --r-lg: 14px
--r-xl: 20px   --r-pill: 999px

--shadow-sm:      0 1px 3px rgba(0,0,0,0.05)
--shadow-md:      0 4px 12px rgba(0,0,0,0.06)
--shadow-lg:      0 8px 24px rgba(0,0,0,0.08)
--shadow-up-sm:   0 -4px 12px rgba(0,0,0,0.06)   /* Mobile-Bottom-Sheet */
--shadow-up-md:   0 -4px 20px rgba(0,0,0,0.10)
--shadow-overlay: 0 20px 60px rgba(0,0,0,0.30)   /* Premium-Modal-Backdrop */
```

Dark-Mode-Shadows haben zusätzlichen Cyan-Glow (`+ 0 0 12px rgba(var(--primary-rgb),0.15)`).
```

- [ ] **Step 2: Insert §5.5 (Z-Index-Stack)**

Insert nach §5 (vor §6):

```markdown
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

Lokale stacking-contexts (Werte 0-5) in `position: relative` Containern: erlaubt mit Pflicht-Kommentar `/* local stacking-context */`. Stylelint blockt sonst.
```

- [ ] **Step 3: Update §13 (Dateien) — Z-Tokens jetzt in fairy-aurora.css**

Find `## 13. Dateien` section. Update wenn `app.css` als Z-Token-Quelle gelistet ist → entfernen. Single SoT ist jetzt `fairy-aurora.css`.

- [ ] **Step 4: Update §14 (Häufige Fehler — Don't) optional**

Add new Don't:

```markdown
- **Don't:** `z-index: 9999;` raw — immer `var(--z-toast)` oder passenden Token. Stylelint blockt.
- **Don't:** `border-radius: 8px;` raw außer für Icon-Chips → `var(--r-icon)`. Sonst `var(--r-md)`.
```

- [ ] **Step 5: Lint-gate**

```bash
npm run stylelint && php bin/console lint:twig templates/ && php bin/console lint:container
```

- [ ] **Step 6: Commit**

```bash
git add docs/design_system/DESIGN_SYSTEM.md
git commit -m "$(cat <<'EOF'
docs(design): update DESIGN_SYSTEM.md cheatsheet with new tokens

§5 ergänzt um --r-icon, --shadow-up-*, --shadow-overlay. Neue §5.5
Z-Index-Stack mit kompletter Tabelle. §14 Don't-Liste erweitert.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Final Verification

- [ ] **Step 1: Full lint-suite**

```bash
npm run stylelint && \
php bin/console lint:twig templates/ && \
php bin/console lint:container && \
find src -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null
```
Expected: alle grün (PHP unverändert).

- [ ] **Step 2: PHPUnit (unverändert erwartet, kein test-relevant code)**

```bash
php bin/phpunit
```

- [ ] **Step 3: Token-Quote z-index final check**

```bash
grep -rn "z-index:" assets/styles/ | grep -v "var(--\|fairy-aurora\.css.*--z-\|/\*\|/\* local stacking-context \*/" | awk -F: '$3 !~ /: *(0|1|2|3|4|5)[^0-9]/ {print}'
```
Expected: 0 lines.

- [ ] **Step 4: Living-Styleguide-Smoke**

`/de/dev/design-system` öffnen, neue Module-Group + alle Sections rendern.

- [ ] **Step 5: Final visual-regress on production-like pages**

Mindestens 5 Pages light + dark:
- `/de/dashboard`
- `/de/risks`
- `/de/dashboards/board`
- `/de/dev/design-system`
- Eine Compliance-Wizard-Page

Bei sichtbarem Regress: STOPP, melden.

- [ ] **Step 6: Update CHANGELOG via release-please-Konvention**

Keine manuelle Edit nötig — release-please bündelt bei nächstem Release-PR.

- [ ] **Step 7: Optional: Push (auf User-Bestätigung warten)**

NICHT autonom pushen. User entscheidet wann.

---

## Rollback-Plan

Atomic commits → `git revert <sha>` pro Commit (invers, C16 → C1).

Konkrete Reverts möglich:
- Alva-Dock-Senkung stört: nur Z. 173 in `fairy-aurora.css` (`--alva-z: var(--z-overlay)` → `--alva-z: 9500;`).
- Pixel-Shifts störend: revert C8 separat.
- BC-Bridge bricht Consumer: revert C12 oder C13.
- Visual-Regress in Dashboard: revert C14, andere Tasks halten.

---

## Self-Review (executed)

**Spec coverage check:**
- §0 Operational Rules → in jeder Task abgedeckt
- §3.1 Z-Tokens → C1
- §3.2 Alva-Konsolidierung → C1 + C2
- §3.3 Token-Verschiebung → C1
- §C1-C16 → Tasks 1-16 1:1
- §5 Akzeptanz → Final Verification
- §6 Risk-Mitigation → Rollback-Plan + STOPP-Bedingungen pro Task

**Placeholder scan:** keine TBD/TODO/incomplete Steps. Bash-Befehle exakt mit erwartetem Output. Code-Blöcke vollständig.

**Type/path consistency:** alle file-paths exakt, alle Token-Namen identisch zu fairy-aurora.css (`--z-popover`, `--r-icon`, `--shadow-overlay`, `--surface-translucent`).

**Method signatures:** `_fa_rag_card.render({status, title, detail})` und `_fa_kpi_card.render({variant, icon, label, value, unit, trend})` — Engineer prüft API in Task 14 Step 3 Befehl.
