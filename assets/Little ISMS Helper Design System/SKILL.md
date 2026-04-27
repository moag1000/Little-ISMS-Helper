---
name: little-isms-helper-design
description: Use this skill to generate well-branded interfaces and assets for Little ISMS Helper, either for production or throwaway prototypes/mocks/etc. Contains essential design guidelines, colors, type, fonts, assets, and UI kit components for prototyping.
user-invocable: true
---

Read the README.md file within this skill, and explore the other available files.
If creating visual artifacts (slides, mocks, throwaway prototypes, etc), copy assets out and create static HTML files for the user to view. If working on production code, you can copy assets and read the rules here to become an expert in designing with this brand.
If the user invokes this skill without any other guidance, ask them what they want to build or design, ask some questions, and act as an expert designer who outputs HTML artifacts _or_ production code, depending on the need.

## Quick orientation

- **Product:** Little ISMS Helper — a multi-tenant SaaS ISMS tool for ISO 27001, ISO 27002, BSI IT-Grundschutz, NIS-2, TISAX.
- **Stack hints (for prod):** Symfony 7.4 + Bootstrap 5.3 + Stimulus. CSS custom properties for theming. No heavy JS framework for styling.
- **Aesthetic:** Corporate Cyberpunk — dark slate base, cyan primary, pink as the "fairy" spark, purple mystical accent. Professional enough for a CISO's boardroom, technical enough for a server room. Glow effects are subtle, never gamer.
- **Language:** German (de-DE). Formal ("Sie", not "Du"). Technical, precise. Sparing humor via the ISMS-Fee mascot.
- **Theme:** Dark mode is the canonical identity; light mode is restrained and daytime/audit-friendly.

## Files in this skill

| File | What it contains |
|---|---|
| `README.md` | Full design guide: visual foundations, content fundamentals, iconography, layout rules |
| `colors_and_type.css` | All CSS custom properties (colors, type, spacing, radii, shadows, glow) |
| `assets/` | Logos (SVG), fairy mascot, favicon |
| `preview/*.html` | Standalone cards demonstrating each token/component |
| `ui_kits/isms_app/` | Pixel-faithful React recreation of the app (sign-in, dashboard, controls, risk register, control detail drawer) |

## When asked to design something new

1. **Start with the tokens** in `colors_and_type.css`. Do not invent new colors — pick from the Cyberpunk palette or the semantic set.
2. **Dark by default** unless the use case is print, audit screen-share, or the user explicitly says light.
3. **Match the voice.** Read the Content Fundamentals section of the README. German, formal Sie-form, ISO clause references as monospace inline chips.
4. **Use Bootstrap Icons** (`bi bi-*`) from the CDN unless given a different icon source.
5. **Include the Fairy sparingly.** A single `✦` in pink, or a subtle logo watermark. Never more than one fairy moment per screen.
6. **Lift a component** from `ui_kits/isms_app/` rather than hand-rolling a new button, badge, or card style.

## Component quick-reference

- Buttons: `.btn.btn-primary` (cyan gradient + glow), `.btn-secondary` (outline), `.btn-ghost`
- Badges: Bootstrap-style `.badge.bg-*` or `.sev.sev-{critical,high,medium,low}` for audit severities
- Stat card: `.stat` with `.icon.i-{cyan,green,pink,amber}` gradient tile + faint ✨ in corner
- Widget card: `.widget` with top gradient hover line (cyan→pink→purple)
- Fairy suggestion: `.fairy-suggestion` — dashed pink border, dark-to-cyan tint

## Common tasks

- **New dashboard screen** → copy `ui_kits/isms_app/Dashboard.jsx` layout: page-head with eyebrow + H1 + sub, optional fairy-suggestion row, 4-up stats, 2-col grid of widgets.
- **New data table** → `.table-card` + `table.data` with `.ctrl-code` (cyan mono), `.ctrl-name`, `.ctrl-clause` (muted mono).
- **New detail page** → right-side drawer pattern in `ControlDrawer.jsx` — cyan left border, 560px width, framework-mapping list, evidence list.
- **New mock company data** → German names (M. Schubert, J. Krämer, S. Klein), ISO clause codes (`A.5.16`, `A.8.7`), R-YYYY-NNN risk IDs, 24h timestamps.

## Do not

- Do not use emoji-heavy UI. The ✦ and ✨ are fairy-mascot signals only.
- Do not use purple+blue generic SaaS gradients. Use cyan→pink or cyan→purple with low opacity.
- Do not round corners more than 12px on large cards, 8px on buttons/inputs, 6px on badges.
- Do not use Inter for display sizes if a display-class alternative exists; Inter is the body/UI workhorse only.
- Do not invent new iconography — use Bootstrap Icons unless a real SVG exists in `assets/`.
