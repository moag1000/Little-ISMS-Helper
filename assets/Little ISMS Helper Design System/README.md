# Little ISMS Helper — Design System

**Brand:** Little ISMS Helper (🛡️ + 🧚‍♀️)
**Category:** Enterprise ISMS / ISO 27001:2022 compliance SaaS
**Tech:** Symfony 7.4 · PHP 8.4 · Bootstrap 5.3 · Stimulus · Turbo · Twig
**Languages:** DE (primary), EN
**Accessibility:** WCAG 2.2 AA

---

## What is Little ISMS Helper?

Little ISMS Helper is a multi-tenant SaaS tool for running an Information Security Management System against **ISO/IEC 27001:2022** and 22+ adjacent frameworks (BSI IT-Grundschutz, NIS2, DORA, TISAX, C5, GDPR/BDSG, EU AI Act, SOC 2, NIST CSF, CIS Controls, …). The audience is ISBs (Informationssicherheitsbeauftragte), CISOs, compliance managers, and ISO-9001-trained QMBs.

The product pairs a very serious scope — 93 Annex A controls, cross-framework mappings, tamper-evident audit logs, 72h GDPR breach timelines — with an unusually opinionated visual voice: **"Cyberpunk Fairy"**. The mascot is a small cyan-and-pink fairy carrying a security shield. She appears dezent throughout the UI — never a gaming-neon avatar, always a quiet wink.

### Why "Cyberpunk Fairy"?

Compliance software is boring by default. If the tool looks like every other enterprise grey-on-grey, ISBs open it only when they must and close it as fast as possible. The biggest UX threat isn't complexity — it's tedium. So the product borrows the visual language its audience already lives in (dark-mode IDEs, terminal UIs, monitoring dashboards): cyan glow on deep slate, pink accent for "magic" (automation / auto-derived data), monospace timestamps, subtle grid backdrops. Corporate Cyberpunk — professional enough for the boardroom, technical enough for the server room. The fairy whispers "security is actually kind of cool" without ever shouting it.

**Light mode** is restrained — boardroom-safe, screen-share-safe.
**Dark mode** is the reward — deeper glow, more pink shimmer, more "this is mine".

---

## Sources

| Asset | Location |
|---|---|
| Upstream repository | `github.com/moag1000/Little-ISMS-Helper` (branch `main`) |
| Core tokens / CSS | `assets/styles/app.css`, `assets/styles/dark-mode.css` (imported) |
| Fairy-magic CSS (`.fairy-*` patterns) | `assets/styles/app.css` lines 200–1000 |
| Premium components (hero, stat-card, widget-card) | `assets/styles/premium.css` |
| Badge / card guides | `templates/_components/_BADGE_GUIDE.md`, `_CARD_GUIDE.md` |
| Logos + favicons | `public/logo*.svg`, `public/favicon*.svg`, `public/fairy_helper_v1.svg` |
| PWA icons | `public/icons/icon-{72…512}.png` |

The reader does not need access to these — everything needed for designing with this brand is copied into this project.

---

## Index

```
/
├── README.md                     ← you are here
├── SKILL.md                      ← Claude Code skill entry point
├── colors_and_type.css           ← all tokens + semantic type (source of truth)
│
├── assets/                       ← logos, favicons, fairy mascot
│   ├── logo.svg                  ← full circular badge + wordmark
│   ├── logo_v2.svg               ← compact variant
│   ├── favicon.svg               ← favicon
│   └── fairy_helper.svg          ← standalone fairy, no wordmark
│
├── preview/                      ← Design System tab cards (~700×* each)
│   ├── brand-logo.html
│   ├── colors-primary.html
│   ├── colors-semantic.html
│   ├── colors-tints.html
│   ├── type-display.html
│   ├── type-body.html
│   ├── type-mono.html
│   ├── spacing-scale.html
│   ├── radii-shadows.html
│   ├── glow-system.html
│   ├── buttons.html
│   ├── badges-status.html
│   ├── badges-severity.html
│   ├── form-fields.html
│   ├── stat-card.html
│   ├── widget-card.html
│   ├── fairy-patterns.html
│   └── alerts.html
│
└── ui_kits/
    └── isms_app/                 ← the only product surface (web app)
        ├── README.md
        ├── index.html            ← clickable prototype entry point
        ├── app.css               ← UI-kit-specific styles on top of tokens
        ├── App.jsx               ← top-level state: sign-in, route, theme, drawer
        ├── Components.jsx        ← Header, Sidebar, Badge, Severity, Stat, Button, FairySpark
        ├── Dashboard.jsx         ← CISO dashboard (stats, compliance, audit feed, top controls)
        ├── RisksPage.jsx         ← 5×5 risk matrix + ISO 27001 Annex A table
        └── ControlDrawer.jsx     ← right-side detail drawer for a single control
```

The prototype flow: **Sign-in → Dashboard → (click any control row) → Control Drawer**. Sidebar switches between Dashboard / Controls / Risks. Theme toggle in the header flips dark↔light.

---

## Content Fundamentals

### Language

- **Primary: German.** Backend UI, navigation labels, form labels, error messages, audit-log entries — all DE. English is a second-class translation (≈3500 keys, 97 domains). Every new copy ships with a DE string first.
- **Formality:** Sie-Form (formal "you"). ISBs and CISOs. Never "Du".
- **Compound-nouns are welcome:** "Schutzbedarfsvererbung", "Audit-Freeze", "Cross-Framework-Mapping". Don't hyphen-break them into English-style phrases.

### Tone

- **Technical, precise, calm.** Compliance is serious. Copy reads like a good internal memo from a competent ISB — not like marketing, not like a gaming trailer.
- **Norms and clause numbers are first-class content.** Show "ISO 27001 Klausel 9.2", "Annex A 5.34", "NIS2 §28 BSIG", "GDPR Art. 33" inline. They anchor trust.
- **Numbers carry weight.** "93 Controls", "790+ Mappings", "23 Frameworks", "~10,5 Stunden gespart pro Audit-Zyklus (95%)" — concrete, not fluffy.
- **The fairy wink is rare.** Fairy copy (`✦ Priorisiert`, `✦ Auto-übernommen`) appears only next to genuinely automated or suggested content. Never in headings, never in error states, never in legal text.

### Casing

- **Sentence case** for UI labels, menu items, button copy, and headings in most contexts. ("Risiken neu bewerten", not "Risiken Neu Bewerten".)
- **Title Case** only for the product wordmark "Little ISMS Helper" and proper nouns ("Annex A", "Cross-Framework-Mappings").
- **UPPERCASE + letter-spacing** for severity pills ("KRITISCH", "HOCH") and eyebrow labels above stat cards.

### Emoji & Icons

- **Emoji are sparingly part of the brand.** Only these survive in production:
  - 🛡️ — brand icon, paired with the wordmark in the README header
  - 🧚‍♀️ — the fairy, used once per page at most (hero watermark, empty-state hint)
  - ✅ ⚠️ ❌ 🚧 — in README / docs only, never in the app chrome itself
- **Sparkle ✦ (U+2726)** is the fairy's calling card — appears as a CSS pseudo-element for suggestions, priority hints, auto-correction, bulk-action confirmations. **Never decorative in running copy** — always a signal.
- Bootstrap Icons (`bi-*`) are the actual icon system — see ICONOGRAPHY below.

### Specific examples

- Feature headline: **"Intelligente Datenwiederverwendung"** — not "Smart Data Reuse" in the DE build.
- Button: **"ISMS-Health-Score neu berechnen"** — verb + domain noun, no "please", no exclamation.
- Empty state: **"Noch keine Findings erfasst. Neues Finding anlegen →"** — plain, action-oriented.
- Fairy suggestion (inline, dashed border, cyan-to-pink gradient bg):
  **"✦ Dieser Asset ähnelt 3 bestehenden aus dem HR-Bereich — Schutzbedarf übernehmen?"**
- Severity badge copy: **"KRITISCH"** / **"HOCH"** / **"MITTEL"** / **"NIEDRIG"**.
- Audit-log timestamps on fresh entries: **"vor 2 Min"** with a subtle `✦` pseudo-element (`.fairy-timestamp-fresh`).

---

## Visual Foundations

### Palette philosophy

Two dominant colors carry the brand everywhere:

- **Cyan `#06b6d4`** — the primary, the "safe" color. Active states, focus rings, key data, "security is on". In dark mode it brightens to `#22d3ee`.
- **Pink `#ec4899`** — the fairy. Strictly reserved for **automation, suggestions, and auto-filled content**. Never a status, never an error. If you see pink, it means "the tool did this for you".

Purple `#8b5cf6` bridges the two (gradients, mystical aura on hover), and semantic (success/warning/danger) colors follow the standard Tailwind palette (emerald, amber, red) so severity is instantly legible.

### Backgrounds

- **Light mode:** flat `#ffffff` cards on `#f8fafc` page, with a **1px neon top-border that appears on card hover** (`widget-card::before`, 3px bar with the full fairy gradient `cyan → pink → purple`).
- **Dark mode:** `#0f172a` page (slate-900, "night sky") with `#1e293b` cards (slate-800). Cards gain a 20px cyan glow on hover (`0 0 20px rgba(6,182,212,0.2)`).
- **Hero sections** use the full 135° night gradient `#0f172a → #1e293b → #06b6d4` with an SVG dot-grid overlay (20% opacity) and a faint 🧚‍♀️ at 15% opacity top-right.
- **No hand-drawn illustrations. No real photography.** All imagery is vector (the fairy, logos, favicons). Keep it that way.

### Animation & easing

- **Standard timing:** `0.15s` (fast), `0.2s` (normal), `0.3s` (slow). Default ease. No bounces, no overshoot.
- **One bouncy exception:** the header logo uses `cubic-bezier(0.34, 1.56, 0.64, 1)` on hover for a slight sparkle-lift.
- **Fairy animations** are slow (2–3s loop), low amplitude, always paired with `@media (prefers-reduced-motion: reduce) { animation: none }`. Named: `fairy-shimmer` (background slide), `fairy-pulse` (scale 1→1.05 + opacity), `fairy-sparkle` (drop-shadow swap), `fairy-session-pulse`, `fairy-workflow-sweep`.
- **Never jitter.** No marquee, no typewriter, no glitch.

### Hover & press states

- **Buttons:** `translateY(-1px)` + deeper shadow + keep color, don't darken.
- **Cards:** `translateY(-5px)` on stat-card, plus cyan glow appears, plus top-border gradient fades in.
- **Icons on stat cards:** glow shadow matches the icon's semantic color (cyan, pink, green, amber, red).
- **Press/active:** remove the translate, shorten the shadow. No color shift.

### Borders

- **1px** for most elements, with `--border-1: #e2e8f0` (light) / `#334155` (dark).
- **2px** for card hover state and the header bottom (always cyan `#06b6d4`).
- **3px left-accent** only for the `.fairy-field-automatic` pattern (pink) and the widget-card top-gradient on hover.
- **No colored-left-border-on-otherwise-white-card pattern** — we use a full top-gradient or a glow halo instead, because the cheap accent-border is the canonical AI-slop tell.

### Shadows

Two systems stacked:
1. **Base shadow** — standard drop: `--shadow-sm` (4px/8% black), `--shadow-md` (8px/12%), `--shadow-lg` (16px/16%).
2. **Neon glow** — concentric cyan/pink/purple/green halos, applied on hover, on focus, on "fresh" audit entries.
   - `--glow-cyan: 0 0 10px rgba(6,182,212,0.3), 0 0 20px rgba(6,182,212,0.2)`
   - `--glow-pink`, `--glow-purple`, `--glow-green` follow the same formula.

In dark mode the base shadows themselves pick up a faint cyan halo — `0 4px 12px rgba(0,0,0,0.5), 0 0 12px rgba(6,182,212,0.15)` — so the glow reads even in ambient dark.

### Transparency & blur

- **Tints:** `rgba(color, 0.15)` for alert backgrounds, `rgba(color, 0.08–0.12)` for fairy-pattern backgrounds.
- **No backdrop-filter blur** in the base system. Modals use solid `--overlay-bg: rgba(15,23,42,0.9)` (dark) / `rgba(15,23,42,0.5)` (light).

### Corner radii

- **4px** small (badges, code, kbd)
- **8px** default (cards, inputs, buttons)
- **12px** large (stat-card, widget-card, hero image, stat-icon tiles)
- **100px** pills (`.fairy-badge`, `.fairy-bulk-action`, chips)

### Cards

- `.card` / `.stat-card` / `.widget-card`: `border-radius: 12px`, `padding: 25px`, `border: 2px solid var(--border-1)`, `box-shadow: 0 2px 10px rgba(0,0,0,0.05)`.
- On hover: lift 5px, border turns cyan, shadow gains cyan glow, optional top-gradient bar fades in.
- Stat cards have a `✨` pseudo-element at 20% opacity in the top-right corner — the fairy's signature, easy to miss.

### Layout rules

- **Desktop-first.** Mobile works but the power user is at a desk.
- **Sticky header** at `min-width: 768px`, `z-index: 100`, always at least `80px` tall, `2px` cyan bottom-border, linear gradient `#0f172a → #1e293b` background.
- **Sidebar / mega-menu** — hierarchical multi-column, dark even in light mode.
- **Command palette ⌘K** — universally available, discoverable via the eyebrow text "⌘K" in the search box.
- **Content max-width** `≈ 1400px` on wide screens, padded `--space-lg` on desktop, `--space-md` on mobile.

### Focus states (WCAG 2.2 AA)

- **3px solid outline** with 2px offset, cyan in light, bright cyan `#22d3ee` in dark, **pink** for primary buttons specifically (`0 0 0 3px rgba(236, 72, 153, 0.3)`). Always `:focus-visible`, never on pure mouse click.

---

## Iconography

- **Primary icon system: Bootstrap Icons 1.11+** (`bi bi-*`). Loaded via CDN in the live app. Line weight, filled/outlined variants, and iconography conventions all follow upstream Bootstrap Icons. Icons are always paired with `aria-hidden="true"` when next to visible text.
- **Fallback for this design system:** we link Bootstrap Icons from CDN in preview files:
  `<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">`
  Flagged as a CDN substitute — the production app bundles the font locally.
- **Custom brand vectors** are in `assets/`:
  - `logo.svg` — 400×400 circular badge, fairy + shield + wings + wordmark "LITTLE ISMS / HELPER" at the bottom. Uses `url(#neonGlow)` and `url(#strongGlow)` SVG filters for the signature halo.
  - `logo_v2.svg` — compact variant without the wordmark
  - `fairy_helper.svg` — standalone fairy only (no shield), good for watermarks
  - `favicon.svg` — simplified mark
- **Emoji fallbacks** are intentional in a few very specific places: `✨` top-right of stat-cards (corner decoration), `🧚‍♀️` in the hero section (low-opacity watermark), `✦` (U+2726) everywhere the fairy suggests / auto-fills — implemented as CSS `content:` pseudo-elements so they never show up in content extraction.
- **Never hand-draw new SVG iconography.** If Bootstrap Icons doesn't have it, ask before adding.

### Font substitution flag ⚠️

The upstream codebase uses the **native system font stack** (`-apple-system, BlinkMacSystemFont, "Segoe UI", …`) — not a custom webfont. For design consistency inside this design system's previews, `colors_and_type.css` declares **Inter** as the preferred display/body face and **JetBrains Mono** as the mono face. Previews pull both from Google Fonts:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
```

**Action for the user:** if you want production parity, either (a) keep the system-font stack and strip Inter from `colors_and_type.css`, or (b) adopt Inter app-wide and self-host it under `fonts/`.
