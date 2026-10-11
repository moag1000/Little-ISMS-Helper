---
name: feedback_gate26_line_baseline_shift
description: Inserting a translation line shifts Gate-26 baselined top-keys → regenerate translation_nesting baseline
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8524ab1e-7912-42ad-be81-bb1c9410a3e9
---

Gate 26 (`check_translation_nesting.py`) keys its baseline by **line number**
(`translations/<file>:<line>:<key>`). Inserting ANY line into a translation
file with pre-existing baselined foreign top-level keys (e.g. risk.de.yaml has
`iso_9001_bridge`, `severity_choice`, `dora`, `fair`, `risk_management`) shifts
those keys' line numbers → baseline entries lose their match → CI Code-Quality
fails with N "new" violations that are really just the old ones at new lines.

**Why:** baseline is line-anchored, brittle to insertions above. Hit when adding
`risk.help.concepts_intro` for glossary tooltips (PR #785).

**How to apply:** after editing a translation file that sits above baselined
top-keys, regenerate:
`python3 scripts/quality/check_translation_nesting.py --write-baseline scripts/quality/baselines/translation_nesting.txt`
then verify `--baseline ... --quiet` exits 0. Diff should be pure line-number
shifts (no new keys). Commit the baseline bump with the translation change.

Run this locally pre-push — it is NOT in the default local gate sweep.
**TRAP (hit again #898):** running the bare `check_translation_nesting.py`
WITHOUT `--baseline` gives a false-pass (rc=0, prints help-ish) — it only
detects the line-shift WITH the `--baseline <file> --quiet` flags, exactly as
CI runs it. Always invoke with the baseline flag locally, or you ship the RED.
Also triggered by `dashboards.{de,en}.yaml` (baselined cross-domain keys
dpo/isb/bcm_officer/tisax) when inserting `board.table.no_history`.
Related: [[feedback_pre_pr_local_checks]] · [[feedback_ci_gate_on_success]]
