---
name: pre-pr-local-checks
description: Vor JEDEM PR-Push lokale CI-Checks an geänderten Files laufen — reduziert GitHub-CI-Fails drastisch.
metadata: 
  node_type: memory
  type: feedback
  originSessionId: df58c764-60e6-489c-9ac8-c65766309351
---

Vor jedem `git push` der einen PR triggert: lokale Quality-Gates an geänderten Files ausführen. Branch-Diff vs main:

```bash
CHANGED_PHP=$(git diff --name-only origin/main...HEAD | grep '\.php$')
CHANGED_TWIG=$(git diff --name-only origin/main...HEAD | grep '\.twig$')
CHANGED_YAML=$(git diff --name-only origin/main...HEAD | grep '^translations/.*\.yaml$')

# PHP syntax
[ -n "$CHANGED_PHP" ] && echo "$CHANGED_PHP" | xargs -n1 php -l

# Twig lint
[ -n "$CHANGED_TWIG" ] && php bin/console lint:twig $CHANGED_TWIG

# Translation gates (am wahrscheinlichsten fail)
python3 scripts/quality/check_translation_nesting.py
python3 scripts/quality/check_translation_key_registry.py  # dynamic_key_prefixes
python3 scripts/quality/check_translation_issues.py

# God-Class / em-writes / form-sections (relevant je nach Change)
python3 scripts/quality/check_god_class_size.py
python3 scripts/quality/check_em_writes_in_controller.py
python3 scripts/quality/check_form_sections.py
python3 scripts/quality/check_twig_macro_scope.py
python3 scripts/quality/check_route_methods.py
```

Bei Translation-Adds NEUE top-level Keys: baseline-Update + `dynamic_key_prefixes.txt` direkt mitcommitten.

**BESSER — die KOMPLETTE Code-Quality-Gate-Suite aus ci.yml lokal fahren** (es
gibt ~50 nummerierte Gates; die obige Handvoll ist unvollständig). Sonst-Falle:
einzelne Gates wie `check_aurora_icon_names.py` (Gate 15), `check_god_class_size.py`
(Gate 25), `check_currentuser_test_args.py` (Gate 28) übersieht man und kassiert
CI-Fails. One-liner der ALLE Gate-Commands aus ci.yml extrahiert + ausführt:

```bash
awk '/name: "Gate [0-9]/{getline; if ($0 ~ /run:/){sub(/.*run: /,""); print}}' .github/workflows/ci.yml > /tmp/gates.txt
while IFS= read -r cmd; do [ -z "$cmd" ] && continue; out=$(eval "$cmd" 2>&1) || { echo "FAIL: $cmd"; echo "$out"|tail -4; }; done < /tmp/gates.txt
# Plus die Nicht-Gate-Steps: phpstan, a11y_contrast_audit.py, check_twig_macro_scope.py,
# check-dql-mismatches.php, check_twig_entity_properties.py
vendor/bin/phpstan analyse --configuration phpstan.dist.neon --no-progress
```

Viele Gates haben `--baseline scripts/quality/baselines/<x>.txt`. Bei ECHTEM
False-Positive (z.B. `$session->save()` triggert Gate-28 #[CurrentUser]-Heuristik):
mit `--write-baseline <pfad>` regenerieren + mitcommitten — das ist der vorgesehene
Escape. Bei Code-Smell (God-Class) NICHT baselinen → refactoren (Sub-Service extrahieren).

**Wichtig — Auto-Merge mergt VOR Tests-Abschluss:** `gh pr merge --auto` mergt sobald
die *required* Checks grün sind; "Code Quality" ist NICHT required → ein Gate-Fail
landet trotzdem auf main, dann Fix-Forward direkt auf main nötig. Drum Gates lokal
abklopfen, nicht auf CI verlassen.

**Why:** GitHub-CI-Fails kosten 5-10min round-trip pro Iteration. Lokale Checks <30s. Erste 3 PRs heute alle wegen trivialer baseline/registry/nesting fails durchgereicht — vermeidbar. (TISAX-Reifegrad-PR #749: 3 CI-Runden verloren an Gate 15/25/28 weil nur Lint+Tests+PHPStan lokal lief.)

**How to apply:** IMMER vor `git push origin <branch>` für PR-Branches. Ausnahme: triviale fixup-commits zu offenem PR wo CI bereits fail.

Siehe auch: [[release-version-bump]], [[targeted-tests]].
