# Browser-Coverage (L1 Smoke)

Persona-driven Playwright route-smoke. Navigates every GET-able route per
persona, captures HTTP status + console-errors + screenshots on failure,
and produces a single HTML report.

## Why

The existing `tests/E2e/specs/` suite covers 15 critical golden-paths
(login, asset CRUD, risk create, …). It does NOT cover the breadth of the
app — a new bug in `/de/training/123/edit` lands in production unnoticed.
L1 closes that gap with a cheap, broad sweep: every navigable route,
every persona, every night.

## What it produces

```
var/browser-coverage/
├── routes.json              # all GET-able routes (from PHP export)
├── results/
│   ├── full-sweep.json      # raw results per persona run
│   ├── isb-practitioner.json
│   └── …
└── report.html              # aggregated HTML view
```

For each route the report records: HTTP status, page-errors,
console-errors, "Modul nicht aktiv" banner-sightings, navigation latency.

## Run locally

```bash
# 1. Start Symfony + seed the screenshot-user
symfony serve -d
php bin/console app:create-screenshot-user

# 2. Run the full sweep (all personas)
npm run e2e:smoke

# 3. Open the report
open var/browser-coverage/report.html
```

Single persona only:

```bash
npm run e2e:smoke -- ciso-executive
```

Skip the route-export step (re-using an existing routes.json):

```bash
BROWSER_COVERAGE_SKIP_EXPORT=1 npm run e2e:smoke
```

## Adding a new persona

Edit `tests/E2e/coverage/persona-routes.yaml`:

```yaml
personas:
  my-new-persona:
    label: "Description shown in the report"
    expects: 200
    allow_patterns:
      - "/dashboard"
      - "/some-area"
```

`allow_patterns` are substring matches against the rendered route path
(after `_locale` is resolved to `de`). `deny_patterns` overrides allow.
The exporter already drops parametric routes (`/risk/{id}`), so you don't
have to worry about them in the persona config.

## Adding a new route to the sweep

Nothing to do — the route shows up automatically once you add the
`#[Route]` attribute. Re-run `php bin/console app:browser-coverage:export-routes`
to refresh `routes.json`.

## CI

`.github/workflows/browser-coverage.yml` runs nightly at 02:00 UTC and
on PRs labelled `browser-coverage`. The job is `continue-on-error: true`
— never blocks. The HTML report uploads as the
`browser-coverage-report` artifact (retained 14 days).

## Limitations (by design, for L1)

- **GET-only**: no form-fills, no POSTs, no multi-step flows. Those come
  in L2 (`tests/E2e/coverage/scenarios/*.yaml`).
- **No parametric routes**: `/risk/{id}` needs seed data — out of scope.
- **Single shared SUPER_ADMIN account**: persona-fidelity comes from
  scoping (`allow_patterns`), not from RBAC. Once tenant isolation lands
  we will split into per-persona accounts.
- **5xx is the only hard fail**: console-errors + module-banners are
  reported but don't block the test. The HTML report makes them visible
  so a human can triage.

## L2 Scenario Form-Fill

`tests/E2e/coverage/scenarios/*.yaml` declares form-submit flows. Each
scenario navigates, fills declared fields, submits, then asserts the
post-submit state. Run:

```bash
npm run e2e:scenarios                      # all personas, all scenarios
npm run e2e:scenarios -- ciso-executive    # single persona
open var/browser-coverage/scenario-report.html
```

Schema reference: `tests/E2e/coverage/scenarios/_schema.yaml`.

Available scenarios:
| File | Scenarios |
|---|---|
| `risk.yaml` | risk_create_minimal, risk_quick_create |
| `asset.yaml` | asset_create_minimal |
| `incident.yaml` | incident_report_high_severity |
| `document.yaml` | document_create_minimal |
| `supplier.yaml` | supplier_onboard_minimal (gated on `suppliers` module) |
| `objective.yaml` | objective_create_minimal |
| `training.yaml` | training_create_minimal |
| `data_breach.yaml` | databreach_init_minimal (gated on `privacy`) |
| `audit_finding.yaml` | audit_finding_create_minor |
| `corrective_action.yaml` | capa_create_minimal |

Adding a scenario: drop a new file under `tests/E2e/coverage/scenarios/`
following `_schema.yaml`. The spec auto-discovers via `readdirSync`. No
code changes needed — only YAML.

## Roadmap

- **L3** (planned): multi-step action-flows (login → create-risk →
  treatment → approve → export) composed from L2 bricks.
- **PR-comment bot**: posts diff of failing routes between base and HEAD
  on labelled PRs.
- **Per-persona accounts**: replace shared SUPER_ADMIN once tenant
  isolation lands so persona-fidelity comes from RBAC, not path-scope.
