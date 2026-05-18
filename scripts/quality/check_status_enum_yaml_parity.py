#!/usr/bin/env python3
"""
check_status_enum_yaml_parity.py — Symfony Workflow YAML places must match
Status-Enum cases.

THE CONTRACT
------------
For every workflow YAML under `config/workflows/` (entity lifecycles only —
the `regulatory/` subtree is excluded because those workflows all support
`App\\Entity\\WorkflowInstance` which is covered separately), the script
verifies a 1:1 mapping between:

  - the workflow's `places:` list, AND
  - the cases of the matching `App\\Enum\\<EntityShortName>Status` enum

The "matching enum" is derived from the workflow's `supports:` FQCN:
  App\\Entity\\Asset      → src/Enum/AssetStatus.php
  App\\Entity\\ISMSObjective → src/Enum/ISMSObjectiveStatus.php
  App\\Entity\\DataProtectionImpactAssessment → src/Enum/DpiaStatus.php
  ...

If the matching enum file does not exist, the workflow is reported as INFO
and skipped (e.g. legacy workflows that have not yet adopted typed enums).

Drift kinds:
  - `yaml-only:<place>`    place declared in YAML but no matching enum case
  - `enum-only:<value>`    enum case backing-value with no YAML place

CLI
---
    --baseline <path>          known-violation file (`exit 0` if all match)
    --write-baseline <path>    regenerate baseline from current state
    --quiet                    one-line success output

Exit codes: 0 clean / baselined, 1 violations, 2 I/O error.

WHY THIS GATE EXISTS
--------------------
Forms using `ChoiceType` with hardcoded string choices for a `status` field
drift away from the YAML workflow `places:` and the Status enum cases over
time. Forms migrated to `EnumType` + `class => XStatus::class` are drift-
proof by construction. This gate catches the YAML↔Enum half of the drift —
the FormType↔Enum half is enforced via PHPStan / code review.

The companion ADR / spec lives in `docs/decisions/` under the
`status-enum-yaml-parity` slug (release notes).
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

try:
    import yaml  # type: ignore
except ImportError as e:  # pragma: no cover - dev box should have PyYAML
    print(f"ERROR: PyYAML is required ({e})", file=sys.stderr)
    sys.exit(2)

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS_DIR = ROOT / "config" / "workflows"
ENUM_DIR = ROOT / "src" / "Enum"

# Map of workflow-supported FQCN short-name → enum-class short-name
# (without the trailing "Status"). Most entities map by identity; this map
# only contains the few that diverge (acronym entities, abbreviated enums).
ENUM_OVERRIDES: dict[str, str] = {
    "DataProtectionImpactAssessment": "Dpia",
}

# Workflows excluded from parity checks. Regulatory workflows under
# `regulatory/` all support `WorkflowInstance` and share one enum — they are
# covered by the workflow_instance lifecycle check.
EXCLUDED_RELDIRS: frozenset[str] = frozenset({"regulatory"})

RE_ENUM_CASE = re.compile(r"^\s*case\s+\w+\s*=\s*'([^']+)'\s*;", re.MULTILINE)


def discover_workflow_files() -> list[Path]:
    if not WORKFLOWS_DIR.is_dir():
        return []
    out: list[Path] = []
    for f in sorted(WORKFLOWS_DIR.rglob("*.yaml")):
        try:
            rel = f.relative_to(WORKFLOWS_DIR)
        except ValueError:
            continue
        # Exclude regulatory/ subtree (one shared entity)
        top = rel.parts[0] if rel.parts else ""
        if top in EXCLUDED_RELDIRS:
            continue
        out.append(f)
    return out


def parse_workflow(yaml_path: Path) -> list[tuple[str, str, list[str]]]:
    """Return a list of (workflow_name, entity_fqcn, sorted_places) tuples.

    A YAML file may declare multiple workflows under
    `framework.workflows.<name>` but in this repo it's one-per-file.
    """
    try:
        data = yaml.safe_load(yaml_path.read_text(encoding="utf-8"))
    except (OSError, yaml.YAMLError):
        return []
    if not isinstance(data, dict):
        return []
    wfs = (data.get("framework") or {}).get("workflows") or {}
    if not isinstance(wfs, dict):
        return []
    result: list[tuple[str, str, list[str]]] = []
    for wf_name, wf_def in wfs.items():
        if not isinstance(wf_def, dict):
            continue
        supports = wf_def.get("supports") or []
        if isinstance(supports, str):
            supports = [supports]
        places = wf_def.get("places") or []
        if not isinstance(places, list):
            continue
        for entity_fqcn in supports:
            result.append((str(wf_name), str(entity_fqcn), sorted(str(p) for p in places)))
    return result


def enum_short_name_for_entity(entity_fqcn: str) -> str:
    short = entity_fqcn.rsplit("\\", 1)[-1]
    return ENUM_OVERRIDES.get(short, short) + "Status"


def parse_enum_cases(enum_file: Path) -> list[str]:
    try:
        text = enum_file.read_text(encoding="utf-8")
    except OSError:
        return []
    return sorted(RE_ENUM_CASE.findall(text))


def _rel(p: Path) -> Path:
    try:
        return p.relative_to(ROOT)
    except ValueError:
        return Path(p.name)


def scan() -> tuple[list[tuple[Path, str, str, str]], list[str]]:
    """Return (violations, info) where:
      - violations: list of (yaml_path, workflow_name, kind, description)
      - info:       informational lines (skipped, etc.)
    """
    violations: list[tuple[Path, str, str, str]] = []
    info: list[str] = []
    for yaml_path in discover_workflow_files():
        for wf_name, entity_fqcn, places in parse_workflow(yaml_path):
            enum_short = enum_short_name_for_entity(entity_fqcn)
            enum_file = ENUM_DIR / f"{enum_short}.php"
            if not enum_file.is_file():
                info.append(
                    f"INFO {_rel(yaml_path)}: workflow={wf_name} entity={entity_fqcn} "
                    f"enum={enum_short} (no enum file — skipped)"
                )
                continue
            cases = parse_enum_cases(enum_file)
            place_set = set(places)
            case_set = set(cases)
            yaml_only = place_set - case_set
            enum_only = case_set - place_set
            for p in sorted(yaml_only):
                violations.append((
                    yaml_path, wf_name,
                    f"yaml-only:{p}",
                    f"place '{p}' declared in YAML but missing from {enum_short}",
                ))
            for v in sorted(enum_only):
                violations.append((
                    yaml_path, wf_name,
                    f"enum-only:{v}",
                    f"case value '{v}' in {enum_short} has no matching YAML place",
                ))
    return violations, info


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out: set[str] = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        s = raw.strip()
        if s and not s.startswith("#"):
            out.add(s)
    return out


def violation_key(yaml_path: Path, wf_name: str, kind: str) -> str:
    return f"{_rel(yaml_path)}::{wf_name}::{kind}"


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Symfony Workflow YAML places must match Status-Enum "
                    "cases (drift-proof state-machine SoT).",
    )
    ap.add_argument("--baseline", type=Path, default=None,
                    help="path to baseline file (violations listed here pass)")
    ap.add_argument("--write-baseline", type=Path, default=None,
                    help="regenerate baseline from current state and exit 0")
    ap.add_argument("--quiet", action="store_true",
                    help="one-line success output")
    args = ap.parse_args()

    if not WORKFLOWS_DIR.is_dir():
        print(f"ERROR: {WORKFLOWS_DIR} not found", file=sys.stderr)
        return 2

    violations, info = scan()

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_status_enum_yaml_parity.py baseline\n")
            fh.write("# Format: <relative-yaml-path>::<workflow-name>::<kind>\n")
            fh.write("# Drop a line and ship the PR after reconciling that "
                     "workflow's places with its enum's cases.\n")
            for path, wf, kind, _desc in violations:
                fh.write(violation_key(path, wf, kind) + "\n")
        print(f"check_status_enum_yaml_parity: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if violation_key(v[0], v[1], v[2]) not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_status_enum_yaml_parity: OK — {total} violation(s), "
                  f"{baselined} baselined, {len(info)} info.")
            for line in info:
                print(line)
        else:
            print(f"check_status_enum_yaml_parity: OK ({total} total, all baselined)")
        return 0

    print("check_status_enum_yaml_parity: VIOLATIONS\n")
    for path, wf, kind, desc in new[:200]:
        print(f"FAIL {_rel(path)} [{wf}] [{kind}] {desc}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(f"\ncheck_status_enum_yaml_parity: {len(new)} new violation(s) "
          f"({baselined} baselined, {total} total).")
    print("Fix: either add the missing case to src/Enum/<X>Status.php or add "
          "the missing place to config/workflows/<x>.yaml. Prefer ADDITIVE "
          "reconciliation (add to whichever side is missing).")
    return 1


if __name__ == "__main__":
    sys.exit(main())
