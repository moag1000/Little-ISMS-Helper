#!/usr/bin/env python3
"""
check_requirement_controls_scope.py — Gate 86: controls behind a requirement come from the engine.

ComplianceRequirement is a global catalogue row; its getMappedControls()
collection holds the controls of EVERY organisation. Inside an HTTP request the
Doctrine tenant filter hides the foreign ones, in jobs, commands and workers it
does not — and an export then lists other organisations' controls. The sweep of
2026-11 found the data-reuse and gap exports reading it directly.

The tenant-scoped answer is FulfillmentRuleEngine::controlsFor($requirement,
$tenant) (ComplianceMappingService::controlsFor() for the current organisation,
RequirementEvidence for documents). This gate counts getMappedControls() calls
per file in src/ (outside src/Entity/ and src/Service/Compliance/Fulfillment/)
and fails when a file is new or its count grows. The baseline may only shrink.

    python3 scripts/quality/check_requirement_controls_scope.py
    python3 scripts/quality/check_requirement_controls_scope.py --write-baseline
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"
BASELINE = ROOT / "scripts" / "quality" / "baselines" / "requirement_controls_scope.txt"
ALLOWED = (SRC / "Entity", SRC / "Service" / "Compliance" / "Fulfillment")
# The getter (any spacing, first-class callable) and its name as a string (callable arrays, PropertyAccessor
# paths); in Twig the property path `requirement.mappedControls`. (DQL joins by control id are scoped by the
# control and stay allowed.)
CALL = re.compile(r"->\s*getMappedControls\s*\(|['\"]getMappedControls['\"]|\b\w+\.mappedControls\b(?!['\"])")
PHP_CALL = re.compile(r"->\s*getMappedControls\s*\(|['\"](?:get)?[Mm]appedControls['\"]")
COMMENT = re.compile(r"^\s*(?:\*|//|/\*)")


def counts() -> dict[str, int]:
    found: dict[str, int] = {}
    files = sorted(SRC.rglob("*.php")) + sorted((ROOT / "templates").rglob("*.twig"))
    for path in files:
        if any(str(path).startswith(str(a)) for a in ALLOWED):
            continue
        pattern = CALL if path.suffix == ".twig" else PHP_CALL
        # Whole file (a call may be split over lines), comment lines blanked.
        text = "\n".join("" if COMMENT.match(line) else line
                         for line in path.read_text(encoding="utf-8").splitlines())
        n = len(pattern.findall(text))
        if n:
            found[str(path.relative_to(ROOT))] = n
    return found


def read_baseline() -> dict[str, int]:
    if not BASELINE.exists():
        return {}
    result: dict[str, int] = {}
    for line in BASELINE.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            path, _, n = line.rpartition(" ")
            result[path] = int(n)
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--write-baseline", action="store_true")
    args = parser.parse_args()
    current = counts()
    if args.write_baseline:
        BASELINE.write_text(
            "# check_requirement_controls_scope.py baseline (Gate 86) — may only shrink.\n"
            "# <path> <number of getMappedControls() calls>\n"
            + "".join(f"{p} {n}\n" for p, n in sorted(current.items())),
            encoding="utf-8",
        )
        print(f"baseline written: {len(current)} file(s)")
        return 0

    baseline = read_baseline()
    failures = [f"{p}: {n} getMappedControls() call(s), baseline {baseline.get(p, 0)}"
                for p, n in current.items() if n > baseline.get(p, 0)]
    stale = [p for p, n in baseline.items() if current.get(p, 0) < n]
    if failures:
        for f in failures:
            print("FAIL " + f)
        print("\nUse FulfillmentRuleEngine::controlsFor($requirement, $tenant) or "
              "ComplianceMappingService::controlsFor() — never the global requirement's collection.")
        return 1
    if stale:
        for p in stale:
            print(f"STALE {p}: fewer calls than baselined — run with --write-baseline")
        return 1
    print(f"check_requirement_controls_scope: OK ({sum(current.values())} baselined call(s))")
    return 0


if __name__ == "__main__":
    sys.exit(main())
