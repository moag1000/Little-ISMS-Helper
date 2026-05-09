# Sprint 0 — Foundations (CC1-CC4)

Vor F1+F2 Implementation. Blockiert nichts danach. Atomic-Commits direkt
auf `main`. Lint-Gate + Tests pro Task.

## Tasks

### Task 0.1 — Translation-Domain Skeletons (CC2)

Anlegen 10 neue Translation-Domains × DE+EN = 20 leere YAML-Dateien
mit Standard-Sektion-Skeleton.

| Domain | Feature | Notiz |
|---|---|---|
| `sso` | F1 | OIDC/LDAP-Wizard |
| `notifications` | F3 | Rules + Channels + In-App |
| `oscal` | F5/F12 | NIST-Importer |
| `eu_authorities` | F25/F26/F29/F30/F36 | Behörden-Reporting-Hub |
| `tia` | F13 | Transfer Impact Assessment |
| `tisax_isa` | F28 | TISAX-Self-Assessment |
| `ai_act` | F33 | EU-AI-Act-Klassifizierung |
| `cra_sbom` | F34 | Cyber-Resilience-Act SBOM |
| `bsi_200_4_exercise` | F27 | BSI-Übungs-Logbuch |
| `procedures` | F17 | Verfahrens-Authoring |

DPA-Template (`dpa_template`), MCP-Server (`mcp_server`), EBIOS-RM
(`ebios_rm`) → erst bei Implementation, nicht Sprint-0.

**Standard-Sektionen pro Skeleton:** `nav`, `page`, `field`, `error`,
`help`, `audit`, `success`, `warning`.

**Update:** `scripts/quality/check_translation_issues.py` Domains-
Whitelist erweitern.

**Test-Gate:** `php bin/console debug:translation --only-missing` für
neue Domains zeigt Konsistenz DE↔EN.

### Task 0.2 — Module-Keys-Audit (CC4)

Ergänze `config/modules.yaml` um:
- `notifications` (F3)
- `eu_authority_reporting` (F25/F26/F29/F30/F36)
- `tisax_isa` (F28)
- `ai_act` (F33)
- `cra_sbom` (F34)
- `procedures` (F17)

Test-Mocks in 7 Controller-Tests (BCM/Privacy) ergänzen:
`in_array($key, [...])` Liste um neue Keys erweitern.

**Lint-Gate:** `php bin/console lint:container` clean.

### Task 0.3 — AuditLogger::logBulk-Helper (CC3)

`src/Service/AuditLogger.php` erweitern:

```php
public function logBulk(
    string $eventType,
    array $batchData,
    array $perEntityData,
    ?string $description = null,
): string  // returns batch_id (UUID)
```

Pattern: 1 batch-entry mit `batch_id` + N per-entity-entries
referenzieren `batch_id`. HMAC-Chain bleibt monotonic.

**Tests:** `tests/Service/AuditLoggerBulkTest.php`
- testEmittsBatchEntry
- testEmittsPerEntityEntriesWithBatchId
- testHmacChainRemainsMonotonic
- testReturnsBatchId

### Task 0.4 — Aurora-Macros (CC1)

3 neue Macros unter `templates/_components/`:

#### `_fa_stepper.html.twig`
```twig
{% macro render(steps, currentIndex, options = {}) %}
  {# steps: array of {label, href|null, status: pending|active|completed|error} #}
  {# A11y: role="navigation", aria-label, aria-current="step" on active #}
{% endmacro %}
```

#### `_fa_diff_row.html.twig`
```twig
{% macro render(oldValue, newValue, options = {}) %}
  {# options.label, options.changeType: added|modified|removed|unchanged #}
{% endmacro %}

{% macro cell_diff(left, right) %}
  {# inline left→right cell-pair with strikethrough on left #}
{% endmacro %}
```

#### `_fa_condition_builder.html.twig`
```twig
{% macro render(fields, operators, conditions, options = {}) %}
  {# conditions: array of {field, operator, value} #}
  {# A11y: aria-live="polite" für Add/Remove #}
{% endmacro %}

{% macro chip(field, op, value, removable = true) %}
  {# Single-Condition-Chip #}
{% endmacro %}
```

**Live-Preview:** `/dev/design-system` registrieren (existing dev-Route).

**Twig-Lint:** `php bin/console lint:twig templates/_components/`

**Tests:** `tests/Twig/Component/{FaStepperTest,FaDiffRowTest,FaConditionBuilderTest}.php`

## Definition-of-Done Sprint 0

- [ ] 20 neue Translation-YAML existieren mit Standard-Sektionen
- [ ] `config/modules.yaml` enthält 6 neue Keys (notifications,
      eu_authority_reporting, tisax_isa, ai_act, cra_sbom, procedures)
- [ ] `AuditLogger::logBulk()` implementiert + 4 Tests grün
- [ ] 3 Aurora-Macros existieren + Live-Preview registriert
- [ ] Macro-Tests grün
- [ ] `find src tests -name "*.php" -print0 | xargs -0 -n1 php -l` = 0 errors
- [ ] `php bin/console lint:container` = clean
- [ ] `php bin/console lint:twig templates/` = all valid
- [ ] CLAUDE.md Updates:
  - Aurora-Macro-Liste erweitert
  - Bulk-Audit-Pattern dokumentiert
  - Module-Keys-Liste auf 28 erweitert

## Sequenzierung Tasks innerhalb Sprint-0

1. **Task 0.2** zuerst (Module-Keys) — kleine Foundation, blockt nichts
2. **Task 0.1** (Translations) — parallel zu 0.2, blockt nichts
3. **Task 0.3** (AuditLogger-Bulk) — vor F2-Implementation Pflicht
4. **Task 0.4** (Aurora-Macros) — vor F1-Wizard Pflicht

Aufwand-Schätzung:
- 0.1: 1h (Skeleton-Generation)
- 0.2: 30min
- 0.3: 1.5h (mit Tests)
- 0.4: 3h (3 Macros + Tests + Dev-Preview-Wiring)

**Total Sprint 0: ~6h** Solo-Dev.
