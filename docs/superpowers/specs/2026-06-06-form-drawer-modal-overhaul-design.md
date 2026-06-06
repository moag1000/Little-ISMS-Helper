# In-Page Forms: Drawer & Großform-Modal Overhaul — Design

**Status:** Approved (brainstorming, persona + UI/UX reviewed) · **Date:** 2026-06-06

## Goal

Replace the classic full-page **Detail / Edit / New** navigation for entity CRUD
with **in-page surfaces** that open over the current list, per the design-system
rule (`docs/design_system/sections/layout-form-table.html`, §"Forms öffnen in-page"):

- **Drawer** (`fa-drawer`, slide-in right) for small/medium forms and read-only
  detail: **≤ ~12 fields, one context** (Maßnahme, Asset, Kontakt, Quick-Edit, Detail).
- **Großform-Modal** (`fa-form-modal` wrapping `fa-form-layout--modal`, centered
  overlay with outline-rail) for **15+ fields / multiple sections / mandatory
  validation**: DPIA, SoA-Edit, Policy-Edit, Data-Breach, Risk-Assessment.
- **Never** a standalone Edit/New/Detail *page* with back-navigation.

The list context stays visible; users stop "clicking back" out of detail pages.

## Decision rule (which surface)

Applied **per entity form** during the sweep, by form size — not by entity identity:

| Form characteristics | Surface |
|---|---|
| ≤ ~12 fields, single context, no multi-section outline | **`fa-drawer`** |
| 15+ fields, OR ≥4 sections / outline-rail, OR step-wise mandatory validation | **`fa-form-modal`** |
| Multi-step regulatory wizard (Incident, Data-Breach) | **`fa-form-modal` (wizard mode)** |

**Surface consistency (review H3):** for a large entity classified as form-modal,
its **read-only detail ALSO opens in the form-modal**, not the drawer — so the
"Edit" action never has to switch surfaces (drawer→modal mid-flow). The **drawer
is used only for entities whose edit form is itself a drawer**. Small-entity
detail and edit both live in the drawer; large-entity detail and edit both live
in the form-modal.

## Architecture

### Loading mechanism — Turbo Frames

Turbo Drive is already on; `business_process` is the in-repo precedent for
frame/stream partial CRUD; `new`/`edit` already return **HTTP 422** on invalid
submit and **PRG-redirect** on success — both Turbo-Frame-native.

- Drawer body and form-modal body each wrap a `<turbo-frame>` (`id="fa-drawer"`,
  `id="fa-form-modal"`). List triggers target the frame via `data-turbo-frame`.
- Invalid submit → controller re-renders the form partial with 422 → Turbo swaps
  the frame content (errors inline).
- Valid submit → controller returns a **Turbo Stream** that (a) replaces the
  affected list row, (b) refreshes dependent aggregate fragments where present
  (heatmap tile, appetite-breach counter, KPI — review: avoid list-vs-dashboard
  desync), and (c) closes the surface.
- **Fallback:** direct URL hit (bookmark, no-JS, new tab) renders the **full
  page** (`extends base.html.twig`) as today. Routes are kept; only in-app
  navigation changes. Preserves deep-linking + accessibility + auditor evidence
  references.

### Frame-aware rendering

Each `show`/`edit`/`new` action detects an in-frame request (the `Turbo-Frame`
request header) via a shared helper (`isTurboFrameRequest(Request)`):
- In-frame → render the **slim shell** (`_layouts/_drawer_shell.html.twig` or
  `_layouts/_form_modal_shell.html.twig`).
- Full request → render the existing full-page template.

### Components — PORT existing design-system assets, don't invent

The visuals are **already designed** in `docs/design_system/assets/` and shown as
examples in `docs/design_system/sections/`. The app's
`assets/styles/fairy-aurora-components.css` is *behind* that reference — port the
missing class blocks/JS, then add minimal Turbo glue. Do NOT author new component CSS.

1. **`fa-drawer`** — CSS already in the app; example in
   `layout-form-table.html` (lines 70-106) + `planning-roadmap.html`. Nothing to build.
2. **`fa-form-modal` + `fa-form-layout--modal`** — CSS in the reference bundle,
   not yet in app → port into `assets/styles/fairy-aurora-components.css`.
   Markup: `layout-form-table.html` lines 108-180.
3. **`fa-savebar` + `_fa-savebar.js`** (`window.FASaveBar`) — port CSS + driver.
   **Used ONLY for inline (non-overlay) dirty edits** — NOT as the save control
   inside a drawer/modal (review B1: its `z-index: --z-fixed (500)` renders it
   *behind* the overlay at `--z-modal` 1001-1070).
4. **Open/close + Turbo glue** (the only new app code): a small Stimulus
   controller toggles `.is-open` + backdrop, traps focus, handles ESC/backdrop/
   return-focus — reusing `fa_modal_controller.js` mechanics. Opening is Turbo
   loading the route into the host `<turbo-frame>` plus an id-matched
   `fa-drawer:request-open` event for non-link triggers. The three ad-hoc drawer
   controllers (`reuse_`, `help_`, `bestandsaufnahme_`) stay as-is (debt; document
   the divergence so testers don't file false consistency bugs).
5. **Host elements** — one persistent drawer host + one form-modal host in
   `base.html.twig` (like `modal-manager`), each with its empty `<turbo-frame>`.

### Overlay lifecycle (review B1/B2/H4/M1/M2/M3 — resolve before pilots)

- **Save model (B1/H2-redundancy):** drawer/modal use their **own sticky footer**
  Save/Cancel (in-flow, no z-index conflict, visually bound to the form). The
  global `fa-savebar` is reserved for inline non-overlay edits. One save
  affordance per surface.
- **Dirty-form guard (B2 — CRITICAL):** a `change`/`input` listener sets a dirty
  flag. EVERY dismiss path (ESC, backdrop, close-X, switching rows, in-frame
  navigate) routes through `window.faConfirm` when dirty; **backdrop-dismiss is
  disabled while dirty**. (Pull the wizard's native-`confirm()`→`faConfirm` fix
  forward from P3 into the shared shell.)
- **Back-button (H4):** opening a surface pushes a history entry; `popstate`
  closes the surface (Back closes the overlay, doesn't navigate away from the list).
- **Loading state (M1):** the shell opens immediately with a skeleton/`aria-busy`
  state inside the `<turbo-frame>` while the partial fetches.
- **Validation visibility (M2):** on 422 the shell scrolls to + focuses the first
  invalid field; required markers (`*`) and field hints render in the drawer too
  (not only the modal). Drawer adds a top "N Pflichtfeld(er) offen" jump line.
- **Nested overlays (M3):** define an incrementing overlay z-stack (each opened
  layer above the previous) and a focus-trap that hands off to the topmost layer
  and restores on close; `inert`/`aria-hidden` applies to everything below the top
  layer. Covers quick-create opened from inside a drawer/modal and the GDPR helper
  inside the Incident wizard.
- **Focus return (L2):** return focus to the trigger; if the list row was replaced
  by a success stream, fall back to the updated row / list container (never `<body>`).
- **Mobile (M4):** both surfaces become a full-screen sheet on narrow viewports;
  the form-modal drops the outline-rail for a collapsed-section accordion.

### List integration, row interaction & bulk-ops (review H1/H2/M5)

- **Rows are genuinely interactive + keyboard-accessible:** the title cell is a
  real `<a>` (`data-turbo-frame`, `cursor:pointer`, hover state) — not a JS-only
  `<tr>` click — so keyboard + screen-reader users get it (WCAG 2.1.1). Eye icon
  → always detail; pencil icon → always edit.
- **Row-click default action is an ORG-WIDE (admin, per-tenant) setting**, not
  per-user (review H1: per-user toggle is a hidden-mode trap / support burden).
  `row_click_action ∈ {detail, edit}`, default `detail`, set by an admin for the
  whole tenant. Stored with tenant settings; surfaced in admin settings UI with a
  fully-German label ("Klick auf Listenzeile öffnet: Detail / Bearbeiten").
- **Bulk-ops coexistence (H2):** the checkbox-select column + `_bulk_action_bar`
  remain. Row-click opens a drawer/modal **only when no rows are selected**;
  selecting a checkbox arms the bulk bar and suppresses row-click-open. **Bulk
  *edit*** (change status/owner on N rows) opens a **small modal of just the
  shared editable fields** — not a per-row drawer. Bulk reassessment/approval/
  tag/status flows (ISO 27001 Cl. 8.2 / 7.5.3) are preserved.
- **New** button → drawer or form-modal per the entity's size class.
- **"Speichern & Neu" (review — high-volume entities):** Asset/Control/Location/
  Contact drawer footers offer save-and-add-another (keeps surface open, clears
  form, refocuses first field) for fast onboarding/migration data entry.

### Detail content (review ISB — auditor-complete)

The detail partial is condensed but **must always carry the audit-load-bearing
fields**: owner, lifecycle status + available transitions, review-date,
last-modified/who, treatment/decision, residual values, evidence links. When the
condensed view can't fit everything, include a "Vollansicht öffnen" link to the
full-page route. Never silently drop evidence links for layout (ISO 27001 7.5.3).
The detail drawer/modal **header shows a status pill** (`fa-entity-badge`) so
lifecycle visibility matches the list.

### Permissions, 4-eyes, freeze (review ISB)

- Edit trigger gated by **both** the domain `canEdit` flag (inheritance/tenant)
  **and** `is_granted`. `not canEdit` → disabled/locked Edit button.
- **4-eyes / segregation-of-duties** approval forms (lifecycle `four_eyes=true`,
  `reason_required`) render as **form-modal or full-page, never a drawer** — they
  need the reason field + diff + `_isms_approval_stages.html.twig` (ISO 27001 A.5.3).
- **Freeze/cutoff:** frozen entities (`audit_freeze`) open **detail-only** (pencil
  hidden, `canEdit=false`); no autosave/draft writes against a frozen baseline.

### Draft / autosave semantics (review ISB + Consultant — H6)

Autosave of partially-filled forms (esp. regulatory: DataBreach Art. 33 72h,
DPIA) is **client-side draft only** (browser) until an **explicit Save**. Nothing
enters the entity / audit log / reports and **no `postUpdate` workflow
auto-progression or SLA timer fires** until explicit submit. (Prevents a
half-filled breach mis-firing `FieldCompletionAutoTransition`.)

### Field-criticality validation tiers (review H5 — important)

Forms distinguish two required-levels:
- **Legal-required** — statutory fields with liability exposure (e.g. NIS2/DORA
  notification fields **only when the tenant is NIS2/DORA-obligated**). These are
  a **hard gate: validated before persist regardless of which wizard step/section
  was reached.** Cannot save without them.
- **Business-required** — fields that are an organisational/risk/leadership
  decision. These are a **soft prompt** (warning, can save anyway), not a hard block.

The form layer marks each required field with its tier; the server enforces the
hard gate for legal-required, surfaces business-required as non-blocking warnings.

## The two wizards

- **Datenschutzmeldung (DataBreach)** — already a canonical `fa-modal--wizard`
  (4 steps, `DataBreachType`). Re-home into the unified `fa-form-modal` (wizard
  mode); behavior unchanged. Low effort.
- **Sicherheitsvorfall (Incident)** — today ONE ~525-line `IncidentType`.
  Re-present as a **`fa-form-modal` — wizard by default, with a user/tenant-
  configurable "lange Form" (single-scroll) alternative** (review H5: experts
  prefer one long form; novices prefer steps). `IncidentType` stays intact —
  only presentation changes. Whichever mode: **legal-required validation runs
  before persist** (NIS2/DORA fields hard-gated *only when obligated*);
  business-required fields are soft warnings. The GDPR-breach assessment helper
  stays as a sub-step/launch (creates nothing server-side). Replace the wizard
  controller's native `window.confirm()` cancel-guard with `window.faConfirm`.
  Own phase (P3).

## Accessibility & fallbacks

- `role="dialog"`, focus-trap, ESC, return-focus, `inert`/`aria-hidden` (mirrors
  `fa-modal`); nested-overlay handoff per §Overlay lifecycle.
- No-JS / direct-URL → full-page route (progressive enhancement).
- `prefers-reduced-motion` respected (slide, backdrop, savebar).
- Module-gating + tenant-scoping unchanged (server-rendered, same controllers,
  same `AuditLogger` write-path).

## Out of scope (review Consultant)

Bulk **import/migration review** does NOT route through per-row drawers — it uses
the existing delta/diff pattern (`fa-diff-row`). Explicitly excluded so nobody
forces 200-row review through CRUD drawers.

## Phasing

- **P1 — Infrastructure + 2 pilots** (first plan):
  - Port `fa-form-modal` + `fa-form-layout--modal` + `fa-savebar` CSS and
    `_fa-savebar.js`; the open/close Stimulus glue + 2 host elements + overlay
    lifecycle (save-model, dirty-guard+faConfirm, back-button, skeleton,
    scroll-to-error, nested z-stack); 2 slim shell layouts; `isTurboFrameRequest()`;
    list-row-update + aggregate-refresh Turbo Stream convention; keyboard-clickable
    rows; the org-wide `row_click_action` admin setting.
  - **Drawer pilot: Location.** **Modal pilot: DPIA** (already outline-rail).
  - Review gate before sweep.
- **P2 — Sweep** the ~30 entity-CRUD controllers in batches, classified by the
  decision rule; per batch: frame-aware rendering + list-update stream + bulk-ops
  coexistence + tests.
- **P3 — Incident** re-presentation (wizard default + long-form option, legal/
  business validation tiers) + Data-Breach re-home + `faConfirm` fix.

## Testing

- **Functional (WebTestCase):** (a) full request → full page; (b) `Turbo-Frame`
  header → slim partial (no `<html>`, contains `fa-drawer`/`fa-form-modal`);
  (c) invalid submit → 422 + inline errors; (d) valid submit → Turbo-Stream with
  updated row; **(e) audit-log entry IS written on drawer/modal edit** (not just
  partial renders correctly — ISO 27001 7.5.3); **(f) legal-required validation
  blocks persist from any step** while business-required does not (only when the
  obligation applies, e.g. NIS2).
- **Stimulus (vitest):** drawer/form-modal open/close/ESC/focus-return; dirty-guard
  routes through faConfirm; back-button closes; nested-overlay focus handoff.
- **Gates:** existing CQ battery.
- Pilots validate end-to-end before the sweep.

## Risks / open points

- **Detail reflow** — condensed-but-auditor-complete partials per entity.
- **Turbo-Stream "close surface"** convention — prototype in P1.
- **Aggregate refresh** scope — which dependent fragments each entity refreshes.
- **`business_process`** already uses frames/streams — align opportunistically.
- **Mode-consistency** — even the org-wide row-click setting means two tenants
  behave differently; mitigate with always-visible eye/pencil affordances.
