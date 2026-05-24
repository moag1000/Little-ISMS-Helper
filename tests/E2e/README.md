# Browser E2E Test Suite (Playwright)

Functional happy-path browser tests for Little ISMS Helper. Foundation laid in
PR `feat(e2e): Playwright test suite foundation`.

## Layout

```
tests/e2e/
├── fixtures/          # auth helpers, test-data builders
│   ├── auth.ts        # loginAs(role) — wraps the Symfony login form
│   └── data.ts        # unique entity-name builders
├── page-objects/      # one file per main entity
│   ├── AssetPage.ts
│   ├── RiskPage.ts
│   ├── IncidentPage.ts
│   └── DataBreachPage.ts
├── specs/             # 15 happy-path specs, numbered for ordering
│   ├── 01-login.spec.ts
│   ├── 02-asset-crud.spec.ts
│   └── ...
├── tsconfig.json      # TypeScript options scoped to e2e
└── README.md          # you are here
```

## Running locally

Prereqs (one-time):

```bash
npm install
npx playwright install --with-deps chromium
```

Boot the dev server + seed user, then run:

```bash
# 1. Start the Symfony server (in a separate shell or with -d)
symfony serve -d

# 2. Seed the screenshot/E2E user
php bin/console app:create-screenshot-user

# 3. Run the suite
npm run e2e

# Optional helpers
npm run e2e:headed    # show the browser
npm run e2e:debug     # open Playwright Inspector
npm run e2e:list      # list every test the suite would run (smoke)
npm run e2e:report    # open the last HTML report
```

By default `playwright.config.ts` boots `symfony serve --no-tls --port=8000` on
demand. If you already have a server running on a different port, point at it:

```bash
E2E_BASE_URL=http://127.0.0.1:9000 E2E_NO_WEBSERVER=1 npm run e2e
```

## Credentials

The suite shares the screenshot-user fixture (see
`src/Command/CreateScreenshotUserCommand.php`):

| Var               | Default                          |
|-------------------|----------------------------------|
| `E2E_USER`        | `screenshots@local.test`         |
| `E2E_PASS`        | `Screenshots-Aurora-2026!`       |
| `E2E_BASE_URL`    | `http://127.0.0.1:8000`          |
| `E2E_NO_WEBSERVER`| _unset_ — set `1` to skip boot   |

That user has `SUPER_ADMIN + ADMIN + MANAGER + AUDITOR + DPO`, so the `role`
parameter on `loginAs(page, 'admin' | 'manager' | 'auditor' | ...)` is currently
advisory. When per-role isolation matters, swap to a fresh `app:create-screenshot-user
--email=...` per role.

## Persona system

The suite reuses the persona model from `scripts/screenshots/personas.yaml`:
each persona is a role-set + a list of canonical screens. For E2E tests we
borrow the same login user but assert against the role-specific UI elements
(e.g. the SoA spec uses the ISB persona's screens; the BC-Exercise spec uses
the BCM-Manager persona's screens).

If you need a role that the screenshot user does not cover (e.g. a
`ROLE_GROUP_CISO`-only flow), provision a dedicated user with
`app:create-screenshot-user --email=ciso@local.test --tenant-code=ciso-e2e` and
override the credentials in the spec via `loginAs(page, 'admin', { email,
password })`.

## Test-data lifecycle

**Today (foundation pass):** tests run against the shared screenshot-user
tenant. Each test creates entities with a unique-per-run suffix
(`testEntityName(...)` from `fixtures/data.ts`), so concurrent or repeated
runs do not collide on unique constraints. Stale rows accumulate; clean them
out periodically via:

```sql
DELETE FROM asset       WHERE name      LIKE 'E2E-%';
DELETE FROM risk        WHERE title     LIKE 'E2E-%';
DELETE FROM incident    WHERE title     LIKE 'E2E-%';
DELETE FROM data_breach WHERE title     LIKE 'E2E-%';
-- etc.
```

**Planned upgrade:** per-test ephemeral tenants via
`app:create-screenshot-user --tenant-code=e2e-<uuid>` + a teardown command
(`app:drop-tenant <code>`). Out of scope for the foundation pass — see
the open-questions block at the bottom of this README.

## Debugging

```bash
# Pause at the first failure and open the Inspector
npm run e2e:debug

# Re-run a single test file
npx playwright test tests/e2e/specs/02-asset-crud.spec.ts

# Re-run a single test by name
npx playwright test -g "admin can create"

# View the last HTML report (traces, screenshots, videos)
npm run e2e:report
```

Failure artefacts land in `var/playwright-results/` (per-test) and
`var/playwright-report/` (rolled-up HTML report).

## Visual regression

Out of scope for this foundation. No snapshots are stored. All assertions are
functional (URL, DOM, text content). If we add visual regression later, use
`expect(page).toHaveScreenshot(...)` with a dedicated `playwright-visual.yml`
workflow and a baseline branch (the noise floor of a multi-tenant app is too
high for it to live in the main e2e pipeline).

## CI

Triggered on every PR and push to `main` via `.github/workflows/e2e.yml`.
HTML report is uploaded as an artefact on failure.

## Open questions / deferred items

- [ ] Per-test tenant isolation (currently shared screenshot-user tenant — see
      "Test-data lifecycle" above).
- [ ] Cleanup teardown command for stale `E2E-%` rows.
- [ ] Visual-regression baseline (out of scope here).
- [ ] Multi-role isolation (per-role login users).
- [ ] CI MySQL service container parity with `ci.yml` (currently the workflow
      mirrors that exact recipe; deviation would risk schema-drift between PHPUnit
      and E2E runs).
- [ ] Add specs for the missing skipped flows (SoA edit modal,
      CAPA closure, supplier-cloud-template).
