# 09 — Anti-Patterns

Twelve named pitfalls extracted from `CLAUDE.md`. Each entry describes the
problem, gives the correct alternative, and cites the CI gate that catches
future regressions.

---

## 1. EntityManager Closes After Constraint Violation

**Problem:** A caught `UniqueConstraintViolationException` (or any DB
constraint error) marks the EntityManager as closed. Calling `$em->persist()`
or `$em->flush()` afterward raises `EntityManagerClosed`.

```php
// BAD — em is closed after the catch block
try {
    $em->flush();
} catch (UniqueConstraintViolationException $e) {
    // $em is now closed — any call below will throw
    $em->persist($fallback);  // RuntimeException!
}
```

**Fix:** Check `$em->isOpen()` before reusing, or open a new EntityManager
via `$managerRegistry->resetManager()`.

**Gate:** No automated gate — caught via code review and test failures.

---

## 2. Unique Constraint Conflicts

**Problem:** Inserting an entity without checking for an existing record by
unique fields causes a DB constraint violation and closes the EntityManager.

**Fix:** Before `persist()`, query by both ID and all unique fields:
```php
$existing = $repo->findOneBy(['tenantId' => $tid, 'slug' => $slug]);
if ($existing !== null) {
    // update $existing rather than inserting
}
```

**Gate:** `check_ddl_transactional.py` catches related migration issues;
unique-constraint logic is verified in `tests/Entity/` constraint tests.

---

## 3. Foreign Key Order in Restore / Seed

**Problem:** Restoring entities in the wrong order causes FK constraint
violations. Example: restoring `Risk` before its parent `Asset` fails.

**Fix:** `RestoreService` has a hand-maintained insertion order (lines 400-600).
When adding a new entity with FK references, insert it after all referenced
entities. Add a `// FK: EntityA → EntityB` comment at the insertion point.

**Gate:** `check_backup_entity_coverage.py` — fails if an entity is not
covered by `RestoreService`. Does not validate order; verify by running the
restore integration test.

---

## 4. Bootstrap Not Loaded (Async ES Module)

**Problem:** Bootstrap is loaded as an ES module. Inline scripts that run
before module resolution cannot access `window.bootstrap`.

```javascript
// BAD — may run before Bootstrap ES module resolves
const modal = new window.bootstrap.Modal(element);
```

**Fix:** Guard with an existence check, or use the Aurora `fa-modal`
Stimulus controller instead of calling Bootstrap Modal JS directly:

```javascript
// Safe guard (only needed outside Stimulus controllers)
if (window.bootstrap && window.bootstrap.Modal) {
    const modal = new window.bootstrap.Modal(element);
    modal.show();
}
```

For new modals, always use `fa-modal` (see `_fa_modal.html.twig`). Open from
JS via:
```javascript
document.dispatchEvent(new CustomEvent('fa-modal:request-open', {
    detail: { id: 'modal-id' }
}));
```

**Gate:** `check_aurora_legacy_markup.py` (Gate 62) rejects `data-bs-toggle="modal"` and
`bootstrap.Modal` where the `fa-modal` dispatcher applies.

---

## 5. Turbo Cache Issues

**Problem:** Turbo caches the previous page and serves a preview on back-
navigation. Dynamic pages (dashboards, wizard steps, one-time token pages)
show stale state.

**Fix:** Disable Turbo caching for dynamic pages:
```twig
<meta name="turbo-cache-control" content="no-cache">
```

Also use `turbo:load` instead of `DOMContentLoaded` for JS initialization:
```javascript
document.addEventListener('turbo:load', () => {
    // safe init
});
```

**Gate:** No automated gate. Caught by Playwright screenshot tests or manual
testing after Turbo-navigating back to a dynamic page.

---

## 6. PREPARE/EXECUTE Pattern in Migrations

**Problem:** The `SET @sql := IF(...); PREPARE stmt FROM @sql; EXECUTE stmt;
DEALLOCATE PREPARE stmt;` pattern in Doctrine migrations is recorded as
executed but the DDL never actually runs. Symptoms: `Column not found` on
the next deployment.

```sql
-- BAD — silently does nothing in Doctrine Migrations
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'risk' AND COLUMN_NAME = 'foo') = 0,
    'ALTER TABLE risk ADD COLUMN foo VARCHAR(255)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

**Fix:** Use plain DDL directly:
```sql
-- GOOD — Doctrine Migrations executes this reliably
ALTER TABLE risk ADD COLUMN foo VARCHAR(255) NULL;
```

**Gate:** `check_no_prepare_execute_migrations.py` — fails on any migration
file containing `PREPARE` or `EXECUTE` keywords. Baseline:
`scripts/quality/baselines/no_prepare_execute_migrations.txt`.

---

## 7. DDL Migrations Without `isTransactional(): false`

**Problem:** MySQL commits implicitly on `ALTER TABLE` / `CREATE TABLE`,
invalidating Doctrine's `SAVEPOINT`. Running more than one DDL migration in a
single `migrate` call fails with `SAVEPOINT DOCTRINE_X does not exist`.

**Fix:** Add to every migration containing DDL:
```php
public function isTransactional(): bool
{
    return false;
}
```

`doctrine:migrations:diff` does **not** add this automatically.

**Gate:** `check_migration_transactional.sh` — scans migration files for
`ALTER TABLE` / `CREATE TABLE` without `isTransactional`. Baseline:
`scripts/quality/baselines/ddl_transactional.txt`.

---

## 8. Bootstrap `bg-*` / `text-white` on `.card` or `.card-header`

**Problem:** Aurora's `.card` sets `background: var(--surface)` and
`.card > .card-header` sets `background: var(--surface-2)`. Bootstrap
utility classes on those elements are silently ignored because Aurora wins
on load order with equal specificity.

```twig
{# BAD — the blue colour will not show #}
<div class="card bg-primary text-white">
```

**Fix:** For KPI/hero tiles use the `fa-feature-card` macro with
`borderColor: 'primary'`. For coloured indicators use `text-<colour>` on
inner elements like `.badge`, `.progress-bar`, `.btn`, or `.alert`.

**Gate:** `check_aurora_anti_patterns.py` — detects `bg-*` and `text-white`
on `.card` and `.card-header`. Full anti-pattern list in
`templates/_components/_CARD_GUIDE.md`.

---

## 9. Bootstrap `--bs-*-rgb` Companions Missing

**Problem:** Bootstrap 5.3 utilities like `.bg-body-secondary` render as
`rgba(var(--bs-secondary-bg-rgb), var(--bs-bg-opacity))`. Without the
`-rgb` companion token, Bootstrap falls back to hardcoded defaults and
ignores any Aurora mapping.

**Fix:** When defining a color token in Aurora, always define both:
```css
--bs-my-color: #3a6ea8;
--bs-my-color-rgb: 58, 110, 168;  /* RGB triplet, no rgba() wrapper */
```

Both light and dark theme forks need the pair.

**Gate:** `check_aurora_utility_misuse.py` — catches missing `-rgb` companions
for mapped tokens. Baseline: `scripts/quality/baselines/aurora_utility_misuse.txt`.

---

## 10. Twig Macro-Import Scope Lost in `{% embed %}`

**Problem:** `{% embed %}` creates a new template scope. Macro imports at
the file level are **not** visible inside embed blocks.

```twig
{# File scope import #}
{% import '_components/_fa_feature_card.html.twig' as _fa_feature_card %}

{% embed '_partials/layout.html.twig' %}
    {% block content %}
        {# FAILS — _fa_feature_card is not in scope #}
        {{ _fa_feature_card.render({title: 'Test'}) }}
    {% endblock %}
{% endembed %}
```

**Fix:** Re-import at the top of the embed block:
```twig
{% embed '_partials/layout.html.twig' %}
    {% block content %}
        {% import '_components/_fa_feature_card.html.twig' as _fa_feature_card %}
        {{ _fa_feature_card.render({title: 'Test'}) }}
    {% endblock %}
{% endembed %}
```

Regular `{% block %}` under `{% extends %}` inherits file-scope imports — only
`{% embed %}` is the trap.

**Gate:** `check_twig_macro_scope.py` — CI gate since v3.5. Direct fail.

---

## 10b. `trans_default_domain` Scope Lost in `{% embed %}`

**Problem:** A file-level `{% trans_default_domain 'X' %}` is not inherited
inside `{% embed %}` blocks. Translation keys silently resolve against the
`messages` fallback domain.

**Fix:** Repeat the directive inside every embed block, or use the explicit
domain parameter on every `|trans` call:
```twig
{% embed '_partials/layout.html.twig' %}
    {% block content %}
        {% trans_default_domain 'crypto' %}
        {{ 'crypto.key_type'|trans }}
        {# or explicitly: #}
        {{ 'crypto.key_type'|trans({}, 'crypto') }}
    {% endblock %}
{% endembed %}
```

**Gate:** `check_twig_embed_domain.py` — direct fail.

---

## 11. Direct `setStatus()` Bypasses Lifecycle

**Problem:** Calling `$entity->setStatus('approved')` directly bypasses
the `LifecycleService` facade, skipping the audit log, RBAC guard, 4-eyes
check, and workflow event dispatch.

```php
// BAD — zero audit trail, no RBAC check
$document->setStatus('approved');
$em->flush();
```

**Fix:** Always use the LifecycleService facade:
```php
$lifecycleService->transition(
    $document,
    'document_lifecycle',
    'approve',
    $currentUser,
    'Approved in quarterly review'
);
```

**Gate:** PHPStan rule `tests/PHPStan/Rule/NoDirectSetStatusRule.php` (entity list from
`EntityTypeRegistry::managedEntityClasses()`). `check_status_enum_yaml_parity.py` (Gate 42) only checks
that status enum cases match the workflow YAML places.
