# Multi-Tenant User Assignment — Sister-Org CISO Case

**Status:** Draft (design only — no implementation in this PR)
**Date:** 2026-05-26
**Trigger:** Holding-Use-Case feedback. The current `User.tenant` M2O + `Tenant.parent` hierarchy covers the *vertical* Konzern case (Holding-CISO sees own + all descendants), but breaks down for *non-hierarchical* sister-organisations where one human CISO must operate across two or more tenants that share no common parent in the tree.
**Estimated implementation effort:** ~6 dev-days across two PRs (schema + shim in PR-1, callsite-sweep in PR-2). This spec PR ships docs + entity skeleton only.

---

## 1. Problem Statement

### 1.1 Current model (recap)

- `User.tenant` is a single `ManyToOne → Tenant` (`src/Entity/User.php:202-204`).
- `Tenant.parent` is a self-referential `ManyToOne → Tenant` (`src/Entity/Tenant.php:124-129`) — gives an arbitrary-depth tree (Holding → Subsidiary → Sub-Subsidiary).
- `TenantContext::getAccessibleTenants()` returns `[currentTenant, ...currentTenant.allDescendants]` (`src/Service/TenantContext.php:199-211`).
- `ROLE_GROUP_CISO` modifier role grants **read-only** across the holding subtree under the user's own tenant — implemented via `HoldingTreeAccessTrait::canReadAcrossHoldingTree()` (`src/Security/Voter/HoldingTreeAccessTrait.php`).
- `TenantContext::canAccessTenant($target)` is the central gate — returns `true` iff `$target === currentTenant || $target->isChildOf(currentTenant)`.

### 1.2 The gap — concrete example

```
                ┌──────────────────────┐
                │  Holding "Mythos AG" │   (corporate-parent, optional)
                └─────────┬────────────┘
                          │
        ┌─────────────────┼──────────────────┐
        │                 │                  │
   ┌────▼─────┐     ┌─────▼─────┐      ┌─────▼─────┐
   │ Tochter A│     │ Tochter B │      │ Tochter C │
   │ (DACH)   │     │ (BeNeLux) │      │ (Nordics) │
   └──────────┘     └───────────┘      └───────────┘
```

**Scenario 1 (currently supported):** A Holding-CISO sits on `Mythos AG` with `ROLE_GROUP_CISO`. She sees A, B, C (all descendants of her tenant). ✓

**Scenario 2 (BROKEN today):** Erika Mustermann is the *operational* CISO for **Tochter A + Tochter C only** (e.g. because Tochter B is governed by a contracted external CISO). Erika needs **write access** to A and C, **no access** to B, and must NOT see the Holding-level data.

Options today, all unsatisfactory:

- **(a) Single user on Holding with `ROLE_GROUP_CISO`** — wrong: gives *read-only* access (not write), exposes Holding data she should not see, includes Tochter B.
- **(b) Two separate User-Accounts (`erika+a@…`, `erika+c@…`)** — duplicates MFA-Tokens, password resets, SSO-linkage, login history, audit-trail-attribution. Hard NO from `dpo-specialist` & `persona-isb-practitioner`: violates ISO 27001 §A.5.16 (Identity-Management — one human, one identity).
- **(c) Promote Erika to `ROLE_SUPER_ADMIN`** — catastrophically over-broad: gives access to ALL tenants in the system including unrelated customers.

**Scenario 3 (BROKEN today, separate variant):** Independent sister-orgs without a Holding row at all. Two legally separate companies share an outsourced ISB. Neither wants a synthetic `Mythos AG` parent in the data model — they are not a Konzern. Today's model forces them to either invent a fake parent or duplicate the user.

### 1.3 Why this matters now

The compliance manager persona has flagged this for three concurrent use-cases:

- Group BCM Officer covering 3 of 5 sister entities (DORA Art. 17 — entity-level scope must be explicit).
- Fractional / outsourced DPO contracted by multiple unrelated SMBs (GDPR Art. 37 — same human, multiple controllers).
- Internal-audit pool where one auditor covers a curated subset of subsidiaries per audit cycle (ISO 19011 §5.4 — auditor independence + scope traceability).

All three blocked by `User.tenant` being singular.

---

## 2. Options

### Option A — `UserTenantAssignment` join entity (ManyToMany with per-row metadata)

New entity `UserTenantAssignment(user_id, tenant_id, role_scope, is_primary, valid_from, valid_to, granted_by, granted_at, revoked_at)`. `User` gets `Collection<UserTenantAssignment> $tenantAssignments`. One row per (user, tenant) pair.

**Pros**
- Most flexible — covers every scenario (sister-orgs with/without Holding, time-bounded assignments for audit-pool, per-tenant role differentiation).
- Industry-standard pattern (Microsoft Entra "Multi-Tenant Organisation", Okta "User Groups across Orgs", Auth0 "Organisations"). No surprise for compliance reviewers.
- Per-row audit trail of who granted access when. Maps cleanly to ISO 27001 §A.5.18 (Access Rights) lifecycle.
- Time-bounded (`valid_from`/`valid_to`) enables audit-pool rotation without code changes.
- Optional `role_scope` JSON field lets us assign *different* roles per tenant (Erika = `ROLE_MANAGER` on A but `ROLE_AUDITOR` on C).

**Cons**
- Largest blast radius: ~89 PHP callsites of `$user->getTenant()` in `src/` plus ~60 in `templates/` need either (i) the BC-shim to keep returning the *primary* tenant or (ii) explicit migration to the multi-tenant resolver.
- `TenantContext` must learn the concept of "current active tenant" within a multi-tenant session — needs a tenant-switcher UI (or a header-based hint). Holding-CISO precedent (`getAccessibleTenants()`) already deals with the read-side; write-side requires per-request resolution.
- Two-layer security: `IsGranted` checks must consider the role *for the current tenant* not the union of roles across all tenants.

### Option B — Virtual Parent ("Konzerngruppe")

Add a synthetic non-user-facing `Tenant` row (`is_virtual_group = true`, hidden from list views) and parent both Tochter A and C to it. Erika gets `ROLE_MANAGER` on the synthetic parent → `ROLE_GROUP_CISO`-like reach grants write down the subtree.

**Pros**
- Zero schema-change beyond a single `is_virtual_group` boolean. Zero callsite-sweep.
- Reuses the existing hierarchy + voter logic as-is.
- Implementation could ship in <1 dev-day.

**Cons**
- **Semantically wrong.** Erika is not a *manager of a Konzerngruppe*; she's an *operational CISO for two unrelated entities*. Reports, dashboards, KPIs all get a phantom "group" row that exists only for permission-routing.
- **Breaks Scenario 3 entirely** — independent sister-orgs that explicitly are NOT a Konzern get forced into one. DORA Art. 28 LEI reporting, NIS2 §28 entity classification, and the `isCorporateParent` ROI/balance-sheet aggregation all assume `parent` is a real legal entity.
- **Per-Tochter role differentiation is impossible** — the synthetic parent grants one role-set that applies to every descendant. The Group-BCM-Officer-on-3-of-5-entities case becomes "officer on all 5".
- **Holding-CISO precedent already broken by this** — `ROLE_GROUP_CISO` semantics depend on "user tenant + descendants". Virtual parents would silently widen Holding-CISO access too.
- Future overrides (e.g. "Erika is manager on A but auditor on C") still impossible without bolt-ons.

### Option C — `Tenant.peerTenants` ManyToMany (Self-Referential)

Add a `peer_tenants` self-referential ManyToMany on `Tenant`. Erika still has a single primary `User.tenant = Tochter A`; the *Tenant* gets a peers-list, and `TenantContext::getAccessibleTenants()` walks peers in addition to descendants.

**Pros**
- Schema change is minimal (one join-table `tenant_peers`).
- Read-side `canAccessTenant()` extension is one `in_array($peerIds)` line.
- Reuses single `User.tenant` — no callsite sweep for `getTenant()` / templates.

**Cons**
- **Per-user reach is implicit, not explicit.** Marking Tochter A and Tochter C as peers means *every user* on either side gets cross-access — not what we want. Erika is the only sister-org CISO; her colleague in Tochter A IT-Support should NOT see Tochter C.
- A workaround would require an additional `User.peerAccessEnabled` flag, then we are back to per-user wiring — the join-table just moved to the wrong entity.
- Cycles in the peer graph (A↔B, B↔C → does A reach C?) require explicit semantics. Transitive vs. non-transitive becomes a multi-day spec on its own.
- Doesn't address Scenario 3 (no shared parent) cleanly — peer-only mode without any Holding still feels like a workaround.
- Time-bounded access (audit-pool) and per-tenant role differentiation: not addressable.

---

## 3. Recommendation — Option A

**Option A wins.** Rationale:

1. **Only option that solves all three driver scenarios** (sister-org CISO, fractional DPO, audit-pool rotation) without bolt-ons.
2. **Compliance-ready out of the gate.** ISO 27001 §A.5.16/§A.5.18 require per-identity per-resource access provisioning with audit trail. Option A's `granted_by`/`granted_at`/`revoked_at` columns are the access-control record. B and C cannot produce that record without inventing tertiary tables.
3. **Industry-aligned** — matches Entra Multi-Tenant Organisation and Okta multi-org patterns. Familiar pattern for any reviewer who has seen modern IAM.
4. **Backwards-compatible via shim.** `User::getTenant()` keeps working by returning the row marked `is_primary = true`. All ~149 callsites continue to function without same-PR changes. Migration to multi-tenant-aware accessors happens module-by-module on a deprecation timeline.
5. **Disruption is bounded.** The big-bang risk is the `TenantContext::getCurrentTenant()` semantics in a multi-tenant session — solved by a tenant-switcher UI gated behind `count($user->getTenants()) > 1` and a session-scoped active-tenant cookie. Most users have one assignment → switcher never appears → no UX change.

B is a hack that creates worse data hygiene. C is a workaround that requires a second wiring to express per-user reach, which is the actual unit of authorization.

---

## 4. Migration Plan (Option A)

### 4.1 Schema (PR-1)

Single migration, `isTransactional() = false` per CLAUDE.md DDL rules.

```sql
CREATE TABLE user_tenant_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    role_scope JSON DEFAULT NULL COMMENT 'optional per-tenant role override; NULL = inherit User.roles',
    valid_from DATETIME NOT NULL,
    valid_to DATETIME DEFAULT NULL,
    granted_by_user_id INT DEFAULT NULL,
    granted_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL,
    revoke_reason VARCHAR(255) DEFAULT NULL,
    lock_version INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,

    UNIQUE KEY uniq_user_tenant_active (user_id, tenant_id, revoked_at),
    INDEX idx_user_active (user_id, revoked_at, valid_to),
    INDEX idx_tenant_active (tenant_id, revoked_at, valid_to),

    CONSTRAINT fk_uta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uta_tenant FOREIGN KEY (tenant_id) REFERENCES tenant(id) ON DELETE CASCADE,
    CONSTRAINT fk_uta_granted_by FOREIGN KEY (granted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Partial unique on primary-tenant — at most one is_primary=1 per user
-- (MySQL ≥ 8.0: emulate via generated column + unique key)
ALTER TABLE user_tenant_assignments
  ADD COLUMN primary_user_id INT AS (CASE WHEN is_primary = 1 AND revoked_at IS NULL THEN user_id ELSE NULL END) STORED,
  ADD UNIQUE KEY uniq_primary_per_user (primary_user_id);
```

**Note:** `User.tenant_id` column STAYS for backwards-compat through the deprecation window (planned: 6 months / 1 LTS-cycle). Both columns are kept in sync by `UserTenantAssignmentService::ensurePrimary()` on every assignment write.

### 4.2 Data Backfill (PR-1, separate migration `isTransactional() = true`)

```sql
-- Mirror every existing User.tenant into a primary assignment row.
INSERT INTO user_tenant_assignments
    (user_id, tenant_id, is_primary, valid_from, granted_at, created_at)
SELECT id, tenant_id, 1, COALESCE(created_at, NOW()), COALESCE(created_at, NOW()), NOW()
FROM users
WHERE tenant_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM user_tenant_assignments u WHERE u.user_id = users.id AND u.is_primary = 1
  );
```

After backfill, every existing single-tenant user has exactly one `is_primary = 1` row → the BC-shim `User::getTenant()` returns the same value as before. Additional cross-tenant assignments are added via the new admin UI on a per-user basis post-deployment.

### 4.3 Entity / Code Touch Inventory

**New files (PR-1, design-stub already in this PR):**
- `src/Entity/UserTenantAssignment.php` — join entity (skeleton in this PR)
- `src/Repository/UserTenantAssignmentRepository.php` — query helpers
- `src/Service/UserTenantAssignmentService.php` — `assign()`, `revoke()`, `setPrimary()`, `getActiveTenants(User)`, `hasActiveAssignment(User, Tenant)`
- `src/Security/Voter/UserTenantAssignmentVoter.php` — gates ASSIGN / REVOKE / SET_PRIMARY
- `src/Controller/Admin/UserTenantAssignmentController.php` — admin CRUD UI
- `src/Form/UserTenantAssignmentType.php` — FormType with tenant-picker + valid-from/to + role-scope overrides
- `migrations/Version2026052700000X.php` (schema, `isTransactional()=false`)
- `migrations/Version2026052700000Y.php` (data backfill, `isTransactional()=true`)
- `templates/admin/users/_tenant_assignments.html.twig` — included in `admin/users/edit.html.twig`
- `assets/controllers/tenant_switcher_controller.js` — Stimulus controller for the header switcher
- `templates/_components/_tenant_switcher.html.twig` — header dropdown, only rendered when `app.user.tenants|length > 1`
- `tests/Entity/UserTenantAssignmentTest.php`, `tests/Service/UserTenantAssignmentServiceTest.php`, `tests/Security/Voter/UserTenantAssignmentVoterTest.php`, `tests/Functional/Admin/UserTenantAssignmentControllerTest.php`

**Modified files (PR-1):**
- `src/Entity/User.php` — add `Collection<UserTenantAssignment> $tenantAssignments`, add `getTenants(): array<Tenant>` and `getActiveTenants(\DateTimeInterface $at = null): array<Tenant>`. **Keep `getTenant(): ?Tenant` unchanged as BC-shim — returns the primary assignment's tenant.** Add `@deprecated since 3.7 use getTenants() / getActiveTenants() instead`.
- `src/Service/TenantContext.php`:
  - `initialize()` now reads from `UserTenantAssignmentService::resolvePrimary()` instead of `User.tenant` directly.
  - New `setActiveTenant(Tenant)` writes the user's choice to the session (validated against `getActiveTenants()`); switcher controller hits this via POST.
  - `getAccessibleTenants()` becomes: `union(activeTenant + activeTenant.descendants if ROLE_GROUP_CISO, all assigned tenants)`. **Critically: write-access is per-active-tenant**; the union is only the *reachable read-set* for nav/picker rendering.
  - New `getAssignedTenants(): array<Tenant>` — exact list of user's assignments, no descendants expansion.

**Touched-by-grep (PR-2 — separate sweep PR, NOT in PR-1):**
- ~89 PHP callsites of `$user->getTenant()` across `src/` (44 voters, 22 services, 15 controllers, 8 repositories — see grep below) → audit each to decide whether (a) "user's primary tenant" semantics are still correct (keep) or (b) "the entity-being-acted-on's tenant" semantics meant (switch to `$entity->getTenant()`).
- ~60 Twig `app.user.tenant` references → most are sidebar/profile context; sweep to `app.user.activeTenant` (new `app.user.activeTenant` Twig global rendered from `TenantContext::getCurrentTenant()` not from `User` directly).
- All `TenantContext::canAccessTenant()` callers — semantics unchanged (still "may the user reach this tenant"), but internally now consults `getAssignedTenants()` + descendants instead of `currentTenant` + descendants.
- `ApiTenantVoter`, `HoldingTreeAccessTrait`, all `*Voter` files — sanity-check that per-tenant role-scope (the `role_scope` JSON column) is respected. **Voters MUST read role from `UserTenantAssignmentService::getRolesForTenant($user, $activeTenant)` not from `$user->getRoles()` directly when a per-tenant override exists.**

Grep targets to scan in PR-2:
```bash
grep -rn '\->getTenant()' src/ | grep -v 'tests/' | wc -l          # ~89 callsites
grep -rn 'app\.user\.tenant\b' templates/ | wc -l                   # ~60 callsites
grep -rln 'getAccessibleTenants\|canAccessTenant' src/              # voter + service consumers
```

### 4.4 Backwards-Compat Shim

| API | Behaviour after PR-1 |
|---|---|
| `User::getTenant(): ?Tenant` | Returns the tenant of the user's `is_primary = 1` active assignment. Identical result for any user that has only one assignment (i.e. every existing user post-backfill). `@deprecated since 3.7`. |
| `User::setTenant(?Tenant): static` | **Behaviour change:** updates the primary assignment (deletes old primary, inserts new). Keeps the old `User.tenant_id` column in sync via service-layer write. Throws when called on a user with no existing assignments (use the new `UserTenantAssignmentService::assign()` instead). `@deprecated since 3.7`. |
| `User::getTenants(): array<Tenant>` | NEW. All non-revoked assignments (no date filter). |
| `User::getActiveTenants(?DateTimeInterface $at = null): array<Tenant>` | NEW. Filters by `valid_from ≤ $at AND ($at ≤ valid_to OR valid_to IS NULL) AND revoked_at IS NULL`. Default `$at = now()`. |
| `TenantContext::getCurrentTenant(): ?Tenant` | Unchanged signature. Internally returns the session-scoped active-tenant if set + valid, else the user's primary. |
| `TenantContext::getAssignedTenants(): array<Tenant>` | NEW. The full set the user may switch to. |
| `TenantContext::canAccessTenant(Tenant): bool` | Unchanged signature. Now: `true` iff the candidate is in `getAssignedTenants()` OR is a descendant of an assigned tenant that the user holds ROLE_GROUP_CISO on. |

The shim guarantees PR-1 ships green CI without any caller changes — only the 4 unit tests on `User::setTenant()` semantics need updates.

### 4.5 Tenant-Switcher UX

- Header-bar dropdown component `_tenant_switcher.html.twig` — only renders when `app.user.tenants|length > 1`.
- Stimulus controller `tenant_switcher_controller.js` POSTs to `/api/tenant/switch` with the target tenant-id; server validates against `UserTenantAssignmentService::hasActiveAssignment()` and writes to session.
- Visual indicator on every page header: badge showing "Aktiver Mandant: Tochter A".
- Keyboard shortcut `⌘⇧T` for power-users (consistent with `⌘P` command palette).
- Per-tenant Alva-mood: switcher animates Alva pose-change on switch (FairyAurora v4 detail — out of scope for PR-1 but in the design-system backlog).

### 4.6 Audit-Trail Wiring

Every `assign()` / `revoke()` / `setPrimary()` call in `UserTenantAssignmentService` emits an `AuditLogger::log()` entry with `action ∈ {tenant_assignment_grant, tenant_assignment_revoke, tenant_primary_change}`, `entity_type = 'UserTenantAssignment'`, full `old_values`/`new_values`. Required by ISO 27001 §A.5.18 (Access Rights — provisioning + de-provisioning evidence) and §A.8.16 (Monitoring).

Every `TenantContext::setActiveTenant()` (session-level switch, NOT an authorization grant) is NOT audit-logged by default — would flood the log. Optional opt-in via `app.audit.log_tenant_switches = true` for high-assurance tenants.

---

## 5. Open Questions

### 5.1 `ROLE_GROUP_CISO` semantics — what changes?

**Question:** Today `ROLE_GROUP_CISO` modifier grants read-across-descendants of the user's *single* tenant. After Option A: read across descendants of *which* tenant? The currently-active one? All assigned ones?

**Tentative answer:** Read across descendants of **every assigned tenant** where the user holds `ROLE_GROUP_CISO`. So a Holding-CISO assigned to both `Mythos AG` (with descendants A, B, C) and `Standalone GmbH` (no descendants) sees `{Mythos AG, A, B, C, Standalone GmbH}`. The active-tenant choice only affects the *write* scope, not the holding-tree read overlay.

**Decision needed before PR-1:** Confirm with `persona-ciso-executive` + `persona-isb-practitioner` skills. Pre-implementation review.

### 5.2 Per-tenant role differentiation — store where?

**Question:** Should `role_scope` JSON column on `UserTenantAssignment` *override* `User.roles` for the active tenant, or *intersect* with it?

- **Override:** Erika has `User.roles = [ROLE_MANAGER]` globally; on Tenant C she has `role_scope = ['ROLE_AUDITOR']` → on C she is only AUDITOR (loses MANAGER).
- **Intersect:** On C she is `ROLE_MANAGER ∩ ROLE_AUDITOR = ROLE_AUDITOR` (effectively, the lower of the two via role-hierarchy).
- **Union:** On C she gets `ROLE_MANAGER + ROLE_AUDITOR` — almost certainly wrong (privilege escalation per-tenant).

**Tentative answer:** **Override.** A NULL `role_scope` means "inherit `User.roles`"; a non-NULL value fully replaces it for the active tenant. Matches the principle of least surprise — admins configure each assignment explicitly.

**Decision needed:** Confirm with `pentester-specialist` (lateral-movement risk if union accidentally implemented).

### 5.3 SSO ↔ tenant assignment mapping

**Question:** How does an Azure AD group-membership map to a `UserTenantAssignment`? Today `User.tenant` is set once during SSO provisioning. With multi-tenant, the SSO provisioning flow needs to know which assignment(s) to create.

**Tentative answer:** Add an `IdentityProvider.assignment_strategy` enum: `single_primary` (legacy — set user's primary tenant by IdP claim), `claim_to_tenants` (parse an `app_roles` / `groups` claim into N assignments), `manual` (SSO creates user, admin creates assignments). Default for existing IdPs: `single_primary` — no behaviour change.

**Decision needed:** Cross-check with `App\Security\Authentication\AzureProvisioner` and existing `azure_metadata` JSON payload structure. Possibly defer SSO multi-assignment to PR-3.

### 5.4 Tenant-deletion / suspension cascade

**Question:** When `Tenant.status` transitions to `terminated` or `archived` (see `Tenant.php:25-29`), what happens to active `UserTenantAssignment` rows?

**Tentative answer:** Auto-`revoke()` all active assignments with `revoke_reason = 'tenant_terminated'`. If the revoked assignment was the user's primary, AND the user has other active assignments, the oldest remaining active assignment becomes the new primary. If no other assignments exist, `User.tenant_id` becomes NULL (matches current `onDelete: SET NULL`). Event-listener on `Tenant` lifecycle transitions handles this.

### 5.5 API-consumer impact

**Question:** API Platform endpoints currently scope by `User.tenant`. Does a multi-tenant API consumer need to send a tenant-hint header?

**Tentative answer:** Yes. New `X-Tenant-Id` HTTP header convention; server validates against the API-key's owning user's assigned tenants. Omitted header → use the user's primary tenant (legacy behaviour). API-tokens stay 1:1 with users; the header is the per-request switcher equivalent.

### 5.6 Tenant-Setup Wizard interaction

**Question:** The setup wizard creates the first User-Tenant pair via the bootstrap controller. Does the wizard need updates for PR-1?

**Tentative answer:** No. The wizard still creates `User.tenant_id` (legacy column). The post-deploy `UserTenantAssignmentService::ensurePrimary()` migration ensures the row exists in the new table too. Wizard-level multi-tenant assignment is out of scope (an admin-after-setup task).

---

## 6. Out of Scope (deferred)

- **Per-tenant module gating.** Today `ModuleConfigurationService` is per-tenant. The active-tenant resolution must respect this — but that's a TenantContext-implementation detail, not a separate feature.
- **Multi-tenant API tokens.** API tokens stay 1:1 with users; multi-tenant API access uses the `X-Tenant-Id` header (§5.5).
- **Cross-tenant relationship integrity.** If Erika creates an Asset on Tochter A and references a Supplier on Tochter C, what happens? Today: blocked by tenant-scoped voters. After PR-1: still blocked — cross-tenant entity references remain forbidden. Cross-tenant data is a separate (rejected) design.
- **Holding-aggregated reporting under multi-tenant.** Group-Report controller assumes the user is on a Holding-tenant. After PR-1: the controller stays the same, but the active-tenant must be a Holding for the report to make sense. UI hint to switch tenant if no Holding is currently active.
- **Per-assignment 4-eyes / approval workflow.** Today an admin grants any role directly. A future enhancement: grants requiring approval (4-eyes) when the target role ≥ ROLE_MANAGER. Defer to a follow-up spec.

---

## 7. Implementation Sequence

| PR | Scope | Risk | Effort |
|---|---|---|---|
| **PR-spec (this one)** | Docs + `UserTenantAssignment` entity skeleton (no schema, no logic) | Zero — text only | 0.5 d |
| **PR-1** | Schema migration + backfill + new entity full + service + voter + tenant-switcher UI + BC-shim on `User::getTenant/setTenant` + unit tests | Medium — schema change + session-scoped active-tenant introduces new state. All callsites stay green via shim. | 3 d |
| **PR-2** | Callsite-sweep: audit ~89 PHP + ~60 Twig sites of `getTenant()` / `app.user.tenant` and migrate to multi-tenant-aware accessors where semantics shifted. Drop `@deprecated` BC-shims after sweep. | Medium — large diff but mechanical. | 2 d |
| **PR-3 (future)** | SSO multi-assignment strategies (§5.3), API `X-Tenant-Id` header (§5.5), per-assignment approval workflow | Lower priority — opt-in features | 1.5 d |

Total to GA: ~6 dev-days across PR-1 + PR-2. PR-3 features are roadmap items, not gating.

---

## 8. References

- `src/Entity/User.php` — current `User.tenant` M2O
- `src/Entity/Tenant.php` — current `Tenant.parent` hierarchy + lifecycle
- `src/Service/TenantContext.php` — current resolution path + `getAccessibleTenants()`
- `src/Security/Voter/HoldingTreeAccessTrait.php` — `ROLE_GROUP_CISO` read-across-tree precedent
- `docs/CORPORATE_STRUCTURE.md` — vertical (parent/child) hierarchy documentation
- `docs/superpowers/specs/2026-05-18-role-scope-architecture.md` — admin-scope contract that this spec builds on
- ISO 27001:2022 §A.5.16, §A.5.18 (Identity & Access Management lifecycle)
- ISO 19011 §5.4 (auditor scope independence)
- DORA Art. 28 / NIS2 BSIG §28 (per-legal-entity scope requirements)
