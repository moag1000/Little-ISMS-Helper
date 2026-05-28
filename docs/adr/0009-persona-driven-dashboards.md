# ADR-0009: Dedicated Per-Persona Dashboard Templates

**Status:** Accepted  
**Date:** 2026-03-01  
**Deciders:** moag1000  
**Tags:** ux, personas, dashboards, rbac, templates

---

## Context

The application has five distinct user personas with genuinely different information needs:

| Persona | Role | Primary concern |
|---|---|---|
| CISO | Strategic security leadership | Risk posture, board-level KPIs, framework coverage |
| Risk Manager | Operational risk | Open risks, treatment plans, residual risk trend |
| DPO | Data protection | GDPR processing activities, DPIA backlog, DSR SLA |
| Compliance Manager | Framework orchestration | Control gaps, SoA progress, audit preparation |
| ISB | Day-to-day security officer | Incidents, asset inventory, open findings, patch status |

Early implementation used a single shared dashboard template with `{% if %}` conditionals gating
individual widgets per persona role. By Sprint 4, this pattern had produced:

- 42 nested `{% if is_granted('ROLE_CISO') %}` / `{% if is_granted('ROLE_DPO') %}` conditionals
  in one template file
- 7 different data queries loaded on every dashboard request regardless of persona (Twig rendering
  skipped the widget, but the PHP controller still executed the query)
- "The dashboard shows the wrong widgets" became a recurring bug category (3 bug-reports in Q1
  2026 alone)
- New persona-specific widgets required modifying the shared template, risking regressions for
  other personas

### Alternative: single template with conditionals (rejected)

The conditional approach would require either (a) loading all data for all personas on every
request (wasteful), or (b) a complex "lazy panel" mechanism that deferred queries but kept all
logic in one controller. Neither approach addressed the cognitive overhead of editing one 800-line
template that serves five distinct use cases.

---

## Decision

**Create dedicated dashboard controller + template pairs for each persona at
`/dashboards/<persona>`.** Each persona has its own:

- Route: `/{_locale}/dashboards/ciso`, `/dashboards/risk-manager`, `/dashboards/dpo`, etc.
- Controller: `src/Controller/Dashboard/<Persona>DashboardController.php` — loads only the
  data needed for that persona.
- Template: `templates/dashboards/<persona>/index.html.twig` — no cross-persona conditionals.
- Guard: `#[IsGranted('ROLE_<PERSONA>')]` attribute on the controller class.

Shared widget components (KPI tiles, risk matrix thumbnails, recent-activity feed) are extracted
into Aurora macro files (`_fa_persona_cockpit_card.html.twig`, etc.) and imported per-template
rather than embedded in a monolithic shared template.

The main `/dashboard` route redirects to the persona-specific dashboard based on the user's highest
persona role. Users without a persona role see the default RBAC-appropriate dashboard (a simplified
view showing only their own assigned tasks and open items).

**Persona roles are additive:** a user with both `ROLE_CISO` and `ROLE_DPO` can switch between
dashboards via a dashboard-selector in the navigation. They always land on their "primary" persona
dashboard first.

---

## Consequences

### Positive

- **No query waste:** Each persona controller loads exactly the data its dashboard widgets consume.
  CISO dashboard does not run DPO-specific DPIA backlog queries.
- **Template clarity:** Each template is 100–200 lines serving one persona. Reviews are fast;
  changes to the DPO dashboard cannot accidentally break the Risk Manager dashboard.
- **Persona ownership:** In a multi-contributor model, persona-specific templates can be owned by
  different contributors with minimal merge conflict risk.
- **Screenshotting:** Playwright screenshot runs (`npm run screenshots`) use a `personas.yaml` to
  define which routes to capture per persona — this directly maps to the persona dashboard URLs.

### Negative

- **Code duplication potential:** Common widgets (recent activity, open findings) may be duplicated
  in 2–3 persona templates. Mitigated by Aurora macro extraction, but discipline required.
- **Navigation complexity:** A user with multiple persona roles needs a clear UI affordance for
  switching dashboards. The mega-menu quick-actions section (`fa-quick-action-button` macro with
  `persona` gate) handles this but adds nav-tree complexity.
- **5× maintenance on shared changes:** A change to how "open findings count" is computed must be
  applied in up to 5 persona controllers/templates. A shared `PersonaDashboardDataService` reduces
  this risk but has not been fully extracted yet.

---

## References

- `src/Controller/Dashboard/` — one controller per persona
- `templates/dashboards/` — per-persona template directories
- `templates/_components/_fa_persona_cockpit_card.html.twig` — reusable cockpit card macro
- `templates/_components/_fa_quick_action_button.html.twig` — nav quick-action button
- `config/routes/dashboards.yaml` — dashboard route definitions
- `docs/onboarding/07-personas-and-skills.md` — persona roles + skill descriptions
- CLAUDE.md §"RBAC" — persona-role list
