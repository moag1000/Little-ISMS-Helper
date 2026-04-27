# Little ISMS Helper — ISMS Web App UI Kit

A high-fidelity recreation of the web app's key screens. All styling is derived from the upstream CSS and token files in the root design system.

## Files

- `index.html` — clickable prototype: sign-in → CISO dashboard → Risk Register → Control Detail, with light/dark toggle
- `AppShell.jsx` — header (gradient night + cyan bottom border), sidebar, ⌘K search, theme toggle
- `Dashboard.jsx` — CISO dashboard with stat cards, compliance progress, audit log feed
- `ControlsList.jsx` — ISO 27001 Annex A table with severity & mapping badges
- `RiskMatrix.jsx` — 5×5 risk matrix
- `components/` — Button, Badge, StatCard, WidgetCard, Fairy (sparkle + mascot)

## Notes

- Uses Bootstrap Icons from CDN (`bi-*`) matching the upstream app.
- Uses tokens from `../../colors_and_type.css`.
- Dark mode is the canonical state — toggle in the header to see the lighter variant.
