# 06 — Quality Gates

Every push runs the numbered gates in the `code-quality` job of `.github/workflows/ci.yml`. Understanding these gates prevents
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

The numbered list of gates (number → script → what it checks) lives in
[`scripts/README.md`](../../scripts/README.md#gate-übersicht), derived from the `Gate N:` step names in
`.github/workflows/ci.yml`. The step name and its `run:` line are the authority; that table and the
per-script sections below it are the explanation. Scripts in `scripts/quality/` without a CI step
(`check_translations.py`, `verify_translations_v2.py`, `check_missing_translations.sh`,
`check_aurora_icons_only.py`, `check_aurora_v4.sh`, `audit_eu_mappings.py`) are manual tools, not gates.

---

## Largest Baselines

Current sizes: `wc -l scripts/quality/baselines/*.txt | sort -rn | head`.

| Baseline file | Lines | Meaning |
|---|---|---|
| `em_writes_in_controller.txt` | many | Known controller flush calls (brownfield; do not add more) |
| `translation_nesting.txt` | many | Deeply nested YAML keys to flatten over time |
| `flash_domain.txt` | many | Flash calls without explicit domain (migrate as you touch files) |
| `notblank_on_not_null.txt` | many | NOT NULL columns without Assert\NotBlank (add when you edit the entity) |
| `auto_form_field_whitelist.txt` | many | Whitelisted `_auto_form` fields (add new fields after review) |

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
   - name: "Gate <next free number>: my new rule"
     run: python3 scripts/quality/check_my_new_rule.py
   ```

4. **Add a `lint:twig` equivalent** if the rule applies to Twig — run it in
   the same job as other Twig checks.

5. **Document the gate** in `scripts/README.md` (row in the gate table plus a section with
   purpose, usage and baseline).

---

## CI Workflow Location

All gates are wired in `.github/workflows/ci.yml`, as sequential steps of the `code-quality` job
(the other jobs run migrations, PHPUnit, JS tests, security and Docker builds).
