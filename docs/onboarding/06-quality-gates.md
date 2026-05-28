# 06 — Quality Gates

Every push runs 48+ automated checks. Understanding these gates prevents
wasted CI cycles. This document explains the gate system, lists all active
gates, and shows how to add a new one.

---

## The Baseline Ratchet Pattern

Most gates work on the ratchet principle:

1. A baseline file in `scripts/quality/baselines/` captures the **current**
   state of known violations.
2. On each CI run the gate re-scans the codebase and compares against the
   baseline.
3. If the scan finds **more** violations than the baseline records, CI fails.
4. If the scan finds **fewer** violations, CI passes — and you should remove
   the resolved entries from the baseline to prevent future regressions.

This allows us to gate new violations without requiring the entire codebase to
be clean first ("brownfield ratchet").

---

## Running Gates Locally

```bash
# Individual gate (Python)
python3 scripts/quality/check_god_class_size.py

# Individual gate (shell)
bash scripts/quality/check_migration_transactional.sh

# PHP DQL check
php scripts/quality/check-dql-mismatches.php

# All Python gates (convenience loop)
for f in scripts/quality/check_*.py; do
    echo "=== $f ===" && python3 "$f" || true
done
```

---

## Gate Inventory

### Architecture and Design

| Script | What it checks | Baseline |
|---|---|---|
| `check_god_class_size.py` | Service LOC > 1500 or deps > 15; Controller LOC > 600 | `god_class_size.txt` |
| `check_em_writes_in_controller.py` | `$em->flush()` / `->persist()` calls in controllers (should be in services) | `em_writes_in_controller.txt` |
| `check_nested_forms.py` | FormType nesting depth > 2 (renders inconsistently) | `nested_forms.txt` |
| `check_auto_form_field_whitelist.py` | Fields rendered by `_auto_form.html.twig` that are not whitelisted | `auto_form_field_whitelist.txt` |
| `check_form_sections.py` | FormTypes with > 6 fields missing `SectionMapInterface` | _(direct fail)_ |
| `check_form_render_completeness.py` | FormTypes with fields not rendered in their template | `form_render_completeness.txt` |
| `check_form_template_fields.py` | Template renders fields not declared in the FormType | `form_template_fields.txt` |
| `check_nested_twig_in_string.py` | Twig logic embedded in PHP string literals | `nested_twig_in_string.txt` |
| `check_no_generic_throws.py` | `throw new \Exception(` (should use domain exceptions) | `no_generic_throws.txt` |
| `check_no_bi_classes.py` | Bootstrap helper classes used where Aurora macros exist | `no_bi_classes.txt` |

### Multi-Tenancy and Security

| Script | What it checks | Baseline |
|---|---|---|
| `check_audit_log_tenant.py` | Entities with audit log calls missing tenant scoping | `audit_log_tenant.txt` |
| `check_admin_role_scope.py` | Admin controllers missing `ROLE_ADMIN` gate | `admin_role_scope.txt` |
| `check_module_gating.py` | Feature code referencing optional-module entities without module gate | `module_gating.txt` |
| `check_version_column_explicit.py` | Entities missing `#[ORM\Version]` on optimistic-lock column | _(direct fail)_ |
| `check_entity_reserved_words.py` | Entity column names that are SQL reserved words | `entity_reserved_words.txt` |

### Database and Migration Safety

| Script | What it checks | Baseline |
|---|---|---|
| `check_no_prepare_execute_migrations.py` | PREPARE/EXECUTE pattern in migration files | `no_prepare_execute_migrations.txt` |
| `check_migration_transactional.sh` | DDL migrations without `isTransactional(): false` | _(direct fail)_ |
| `check_migration_reserved_words.sh` | Migration files using SQL reserved column names | _(direct fail)_ |
| `check_ddl_transactional.py` | Broader DDL transactional override check | `ddl_transactional.txt` |
| `check-dql-mismatches.php` | DQL queries referencing non-existent entity fields | _(direct fail)_ |
| `check_dql_non_portable.py` | Non-portable DQL (MySQL-only functions) | `dql_non_portable.txt` |

### Frontend and Templates

| Script | What it checks | Baseline |
|---|---|---|
| `check_twig_macro_scope.py` | Macro imported at file scope but used inside `{% embed %}` | _(direct fail — CI gate since v3.5)_ |
| `check_twig_embed_domain.py` | `trans_default_domain` missing inside embed blocks | _(direct fail)_ |
| `check_twig_macro_imports.py` | Macro calls for macros not imported in the template | _(direct fail)_ |
| `check_twig_unsupported_tags.py` | Twig tags unsupported by the project's Twig version | _(direct fail)_ |
| `check_aurora_anti_patterns.py` | Bootstrap `bg-*`/`text-white` on `.card` or `.card-header` | `aurora_utility_misuse.txt` |
| `check_aurora_utility_misuse.py` | Aurora utility class misuse patterns | `aurora_utility_misuse.txt` |
| `check_aurora_v4.sh` | Aurora v4 macro usage convention checks | _(direct fail)_ |
| `check_aurora_icon_names.py` | Icon names not in the approved Aurora icon set | `aurora_icon_names.txt` |
| `check_aurora_icons_only.py` | Raw Font Awesome class usage outside approved patterns | _(direct fail)_ |
| `check_macro_arg_arity.py` | Macro call sites passing wrong number of arguments | `macro_arg_arity.txt` |
| `check_double_locale_prefix.py` | Routes with double locale prefix (`/{_locale}/{_locale}/`) | _(direct fail)_ |
| `check_legacy_route_import.py` | `#[Route]` annotations (not attributes) still in use | `legacy_route_import.txt` |
| `check_route_methods.py` | Routes without explicit HTTP method constraints | `route_methods.txt` |
| `check_route_trailing_slash.py` | Routes with trailing slash (causes redirect loops) | `route_trailing_slash.txt` |
| `check_route_wildcard_collisions.py` | Route wildcards colliding with static segments | _(direct fail)_ |

### Translations

| Script | What it checks | Baseline |
|---|---|---|
| `check_missing_translations.py` | Keys used in templates missing from translation files | _(direct fail)_ |
| `check_missing_translations.sh` | Shell-based translation key scan | _(direct fail)_ |
| `check_translation_issues.py` | Hardcoded text, missing domain params, untranslated attributes | _(report only)_ |
| `check_translation_dynamic_keys.py` | Dynamic translation keys (not statically analysable) | `translation_dynamic_keys.*` |
| `check_translation_nesting.py` | Deeply nested YAML keys in translation files | `translation_nesting.txt` |
| `check_translations.py` | Cross-domain translation consistency | _(direct fail)_ |
| `check_yaml_duplicates.py` | Duplicate keys in YAML translation files | _(direct fail)_ |
| `check_flash_domain.py` | `addFlash()` calls without explicit domain | `flash_domain.txt` |

### Code Quality

| Script | What it checks | Baseline |
|---|---|---|
| `check_bool_accessor_usage.py` | `is*()` / `get*()` accessor naming convention | `bool_accessor_usage.txt` |
| `check_setter_nullability.py` | Setters accepting `null` on non-nullable columns | `setter_nullability.txt` |
| `check_notblank_on_not_null.py` | `NOT NULL` columns missing `#[Assert\NotBlank]` | `notblank_on_not_null.txt` |
| `check_disabled_mapped_pair.py` | `disabled: true` FormType fields that are also `mapped: true` | `disabled_mapped_pair.txt` |
| `check_raw_json_textarea.py` | Direct JSON textarea rendering (must use builder macro) | `raw_json_textarea.txt` |
| `check_enum_to_json_unwrap.py` | Enum `->value` missing when serialising to JSON | `enum_to_json_unwrap.txt` |
| `check_status_enum_yaml_parity.py` | Status enum cases not matching workflow YAML places | _(direct fail)_ |
| `check_freetext_legacy.py` | Legacy freetext fields not yet migrated to taxonomy | `freetext_legacy.txt` |
| `check_currentuser_test_args.py` | `CurrentUser` annotation args in tests | `currentuser_test_args.txt` |
| `check_alva_hint_placeholders.py` | AlvaHint rules with placeholder content | _(direct fail)_ |
| `check_backup_entity_coverage.py` | Entities not covered by `RestoreService` | _(direct fail)_ |

### Content and Compliance

| Script | What it checks | Baseline |
|---|---|---|
| `check_no_competitor_names.sh` | Competitor product names in source files | _(direct fail)_ |

---

## Top 5 Baselines by Line Count

| Baseline file | Lines | Meaning |
|---|---|---|
| `em_writes_in_controller.txt` | 470 | 470 known controller flush calls (brownfield; do not add more) |
| `translation_nesting.txt` | 375 | 375 deeply nested YAML keys to flatten over time |
| `flash_domain.txt` | 129 | 129 flash calls without explicit domain (migrate as you touch files) |
| `notblank_on_not_null.txt` | 87 | 87 NOT NULL columns without Assert\NotBlank (add when you edit the entity) |
| `auto_form_field_whitelist.txt` | 65 | 65 whitelisted `_auto_form` fields (add new fields after review) |

---

## How to Add a New Gate

1. **Write the check script** in `scripts/quality/`:
   ```python
   # scripts/quality/check_my_new_rule.py
   #!/usr/bin/env python3
   """Description of what this gate enforces."""
   import sys
   from pathlib import Path

   ROOT = Path(__file__).resolve().parents[2]
   BASELINE = ROOT / 'scripts/quality/baselines/my_new_rule.txt'

   def scan() -> list[str]:
       violations = []
       # ... scan logic ...
       return violations

   if __name__ == '__main__':
       violations = scan()
       baseline = BASELINE.read_text().splitlines() if BASELINE.exists() else []
       new = [v for v in violations if v not in baseline]
       if new:
           print('FAIL — new violations:')
           for v in new:
               print(f'  {v}')
           sys.exit(1)
       print(f'PASS ({len(violations)} known, 0 new)')
   ```

2. **Create the baseline** with the current violations:
   ```bash
   python3 scripts/quality/check_my_new_rule.py > /dev/null
   # If there are existing violations, capture them:
   python3 scripts/quality/check_my_new_rule.py 2>&1 | grep -v FAIL \
     > scripts/quality/baselines/my_new_rule.txt
   ```

3. **Wire into CI** in `.github/workflows/ci.yml`:
   ```yaml
   - name: My New Rule
     run: python3 scripts/quality/check_my_new_rule.py
   ```

4. **Add a `lint:twig` equivalent** if the rule applies to Twig — run it in
   the same job as other Twig checks.

5. **Document the gate** in this file (`06-quality-gates.md`) and include the
   baseline file description.

---

## CI Workflow Location

All gates are wired in `.github/workflows/ci.yml`. The workflow runs on push
to any branch and on pull requests to `main`. Gate jobs are parallelised by
category (PHP, Twig, Translation, Architecture) to keep total CI time under
10 minutes.
