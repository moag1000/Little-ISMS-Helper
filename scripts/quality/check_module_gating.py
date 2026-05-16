#!/usr/bin/env python3
"""
check_module_gating.py — Audit-S5 / S2 Foundation P-6 ModuleGate-Disziplin CI-gate.

Scans src/Form/**/*Type.php for fields whose name signals a regulatory framework
(DORA / LkSG / MaRisk / NIS2 / TISAX / GDPR / DPIA / privacy-Art-XX, etc.) and
verifies that each such field is wired through one of the approved gates:

  (a) Sits inside an `if ($this->isModuleActive('X')) { ... }` block.
  (b) Sits inside an `if ($this->isAnyModuleActive('A', 'B', ...))` block.
  (c) Is added via `$this->addModuleGatedField($builder, 'X', ...)`.
  (d) Sits inside a private helper method that is itself only called from
      one of the above gated contexts. Convention: helper methods named
      `addPrivacyFields`, `addDoraFields`, `addLksgFields`, `addMaRiskFields`,
      `addNis2Fields`, `addTisaxFields`, `addGdprFields`, `addDpiaFields`
      are considered safe-zone if invoked at least once from a gated block.
  (e) The line carries an inline comment annotation
        // @no-module-gate-required: <reason>
      directly above the `->add(...)` call.

Background — S2 P-6 (var/junior-isb-audit/SOLUTIONS_FOUNDATION.md):
  DORA (15 fields), LkSG, MaRisk, NIS2, TISAX, GDPR-sections without
  `isModuleActive()` leaked into Risk/Supplier/Document/Asset/Incident
  FormTypes. Tenants who never activated those modules saw irrelevant
  regulatory fields. This CI-gate freezes the helper-pattern.

Exit-codes:
  0 — no violations (or all violations baselined)
  1 — at least one violation outside the baseline
  2 — parse / I/O error

Usage:
    python3 scripts/quality/check_module_gating.py
    python3 scripts/quality/check_module_gating.py --paths src/Form/SupplierType.php
    python3 scripts/quality/check_module_gating.py --baseline \
        scripts/quality/baselines/module_gating.txt
    python3 scripts/quality/check_module_gating.py --quiet
    python3 scripts/quality/check_module_gating.py --verbose
    python3 scripts/quality/check_module_gating.py --write-baseline \
        scripts/quality/baselines/module_gating.txt
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORM_DIR = ROOT / "src" / "Form"

# Regulatory field-name patterns. Substring match against the literal field-name
# passed to `->add('<name>', ...)`. Case-insensitive.
REGULATORY_PATTERNS: list[re.Pattern[str]] = [
    re.compile(r"^dora", re.IGNORECASE),
    re.compile(r"dora", re.IGNORECASE),
    re.compile(r"^lksg", re.IGNORECASE),
    re.compile(r"lksg", re.IGNORECASE),
    re.compile(r"^marisk", re.IGNORECASE),
    re.compile(r"marisk", re.IGNORECASE),
    re.compile(r"^nis2", re.IGNORECASE),
    re.compile(r"nis2", re.IGNORECASE),
    re.compile(r"^tisax", re.IGNORECASE),
    re.compile(r"tisax", re.IGNORECASE),
    re.compile(r"^gdpr", re.IGNORECASE),
    re.compile(r"gdpr", re.IGNORECASE),
    re.compile(r"^bafin", re.IGNORECASE),
    re.compile(r"^isDoraRelevant$"),
    re.compile(r"^leiCode$"),
    re.compile(r"^naceCode$"),
    re.compile(r"^requiresDPIA$", re.IGNORECASE),
    re.compile(r"^requiresDpia$"),
    re.compile(r"^legalBasis", re.IGNORECASE),
    re.compile(r"^processingActivities$"),
    re.compile(r"^outsourcingClassification$"),
    re.compile(r"^outsourcingDueDiligence", re.IGNORECASE),
    re.compile(r"^outsourcingExitStrategy$"),
    re.compile(r"^riskBearingCapacityImpact$"),
    re.compile(r"^boardLevelRiskAcceptance$"),
    re.compile(r"^complianceFunctionInvolvement$"),
    re.compile(r"^internalAuditFunctionInvolvement$"),
]

# Helper-methods that count as "module-gated safe-zone" when their bodies host
# regulatory `->add(...)` calls AND when the method is invoked at least once
# from a gated context. Matched against the bare method name.
SAFE_HELPERS: set[str] = {
    "addPrivacyFields",
    "addDoraFields",
    "addLksgFields",
    "addMaRiskFields",
    "addMariskFields",
    "addNis2Fields",
    "addTisaxFields",
    "addGdprFields",
    "addDpiaFields",
    "addBafinFields",
}

# Regex patterns ─────────────────────────────────────────────────────────────

RE_BUILDER_ADD = re.compile(
    r"->add\(\s*['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]"
)
RE_IF_MODULE_ACTIVE = re.compile(
    r"\bif\s*\(\s*\$this->isModuleActive\("
)
RE_IF_ANY_MODULE_ACTIVE = re.compile(
    r"\bif\s*\(\s*\$this->isAnyModuleActive\("
)
RE_ADD_MODULE_GATED = re.compile(
    r"\$this->addModuleGatedField\("
)
RE_PRIVATE_FUNCTION = re.compile(
    r"\b(?:private|protected)\s+function\s+(\w+)\s*\("
)
RE_HELPER_CALL = re.compile(
    r"\$this->(\w+)\s*\("
)
RE_ANNOTATION = re.compile(
    r"//\s*@no-module-gate-required(?::\s*(.+))?"
)

# Token used to find an `if (...)` opening brace anywhere on the line.
RE_LINE_HAS_BRACE_OPEN = re.compile(r"\{")
RE_LINE_HAS_BRACE_CLOSE = re.compile(r"\}")


# Data structures ────────────────────────────────────────────────────────────


def field_is_regulatory(name: str) -> bool:
    for pat in REGULATORY_PATTERNS:
        if pat.search(name):
            return True
    return False


def _scan_helper_safety(text: str) -> dict[str, bool]:
    """
    Walk the file once and decide for each `private function addXxxFields()`
    whether it is invoked from a gated context. A helper is "safe" if every
    call-site sits inside an `if ($this->isModuleActive(...))`-style block.

    Returns a dict {helper_method_name: is_safe}.
    """
    # 1. Collect helper definitions.
    helper_defs: set[str] = set()
    for m in RE_PRIVATE_FUNCTION.finditer(text):
        name = m.group(1)
        if name in SAFE_HELPERS:
            helper_defs.add(name)

    if not helper_defs:
        return {}

    # 2. Scan call-sites with brace-depth tracking. A call to a helper is
    #    "gated" iff a `if (...isModuleActive...)` was opened at the current or
    #    a parent brace-level and the corresponding `}` has not yet closed.
    lines = text.splitlines()
    brace_depth = 0
    # Stack of brace-depths at which a module-gate `if`-block was opened.
    gate_depths: list[int] = []
    helper_calls: dict[str, list[bool]] = {h: [] for h in helper_defs}

    for raw in lines:
        # Strip line-comments to avoid matching commented-out code.
        line = re.sub(r"//.*$", "", raw)

        # Pre-process: did this line OPEN a module-gate-if?
        opens_gate = bool(
            RE_IF_MODULE_ACTIVE.search(line) or RE_IF_ANY_MODULE_ACTIVE.search(line)
        )

        # Count braces. We assume one logical statement per line for the
        # gate-tracking — this is true for ~99 % of Symfony FormTypes.
        opens = len(RE_LINE_HAS_BRACE_OPEN.findall(line))
        closes = len(RE_LINE_HAS_BRACE_CLOSE.findall(line))

        # Check helper-calls BEFORE updating brace_depth so the gate is still
        # "open" on the line that declares the if.
        for cm in RE_HELPER_CALL.finditer(line):
            helper_name = cm.group(1)
            if helper_name in helper_defs:
                is_gated = bool(gate_depths)
                helper_calls[helper_name].append(is_gated)

        # If this line opens a gate-if, register it at the current depth.
        if opens_gate:
            gate_depths.append(brace_depth)

        # Apply brace changes.
        brace_depth += opens
        brace_depth -= closes

        # Pop any gates whose brace level has been closed.
        while gate_depths and brace_depth <= gate_depths[-1]:
            gate_depths.pop()

    # A helper counts as safe iff EVERY call-site is gated AND it has ≥1 call.
    return {
        name: (len(calls) > 0 and all(calls))
        for name, calls in helper_calls.items()
    }


def check_file(path: Path) -> list[tuple[int, str, str]]:
    """
    Return list of (line_no, field_name, reason) for each ungated regulatory
    field detected in `path`.
    """
    try:
        text = path.read_text(encoding="utf-8")
    except OSError as e:
        print(f"ERROR reading {path}: {e}", file=sys.stderr)
        return []

    # Fast-path: no regulatory keywords anywhere → skip.
    text_lower = text.lower()
    if not any(
        kw in text_lower
        for kw in ("dora", "lksg", "marisk", "nis2", "tisax", "gdpr", "bafin", "dpia")
    ):
        return []

    helper_safety = _scan_helper_safety(text)

    lines = text.splitlines()
    violations: list[tuple[int, str, str]] = []

    # Brace-depth tracker mirroring _scan_helper_safety, plus current helper.
    brace_depth = 0
    gate_depths: list[int] = []
    # Stack of (body_min_depth, method_name). A method body starts AT THE
    # FIRST `{` AFTER the declaration. We register a pending function on
    # decl-line and bind body_min_depth = brace_depth + 1 at the next
    # brace-open (or same-line when PSR-12 style places the `{` on the
    # declaration line).
    func_stack: list[tuple[int, str]] = []
    # Pending function declared but not yet bound to a body-brace.
    pending_func_name: str | None = None

    for idx, raw in enumerate(lines):
        line_no = idx + 1
        line = re.sub(r"//.*$", "", raw)

        # Detect function entry — note: the `{` typically lands on the next
        # line in PSR-12 / Allman style. We register the name as pending,
        # bind it as soon as a brace opens.
        func_match = RE_PRIVATE_FUNCTION.search(line)
        line_declares_func = func_match is not None
        if line_declares_func:
            pending_func_name = func_match.group(1)

        opens_gate = bool(
            RE_IF_MODULE_ACTIVE.search(line) or RE_IF_ANY_MODULE_ACTIVE.search(line)
        )

        opens = len(RE_LINE_HAS_BRACE_OPEN.findall(line))
        closes = len(RE_LINE_HAS_BRACE_CLOSE.findall(line))

        # Walk ->add() calls on this line. Check each for gating.
        for add_match in RE_BUILDER_ADD.finditer(line):
            field_name = add_match.group(1)
            if not field_is_regulatory(field_name):
                continue

            # (e) — inline annotation on the same line or the immediately
            # preceding non-empty line. Cheap path: same-line raw.
            if RE_ANNOTATION.search(raw):
                continue
            # Look backwards up to 3 lines for a preceding annotation comment.
            for back in range(1, 4):
                if idx - back < 0:
                    break
                prev = lines[idx - back].strip()
                if prev == "":
                    continue
                if RE_ANNOTATION.search(prev):
                    break
                # Stop at any non-comment line.
                if not prev.startswith("//") and not prev.startswith("*"):
                    break
            else:
                prev = ""
            if RE_ANNOTATION.search(prev or ""):
                continue

            # (c) — wired through addModuleGatedField on the same line? The
            # builder may chain multiple `->add()` calls, but if the line
            # contains addModuleGatedField the field is considered safe.
            if RE_ADD_MODULE_GATED.search(line):
                continue

            # (a)/(b) — currently inside an isModuleActive-if-block?
            if gate_depths:
                continue

            # (d) — currently inside a safe helper-body?
            current_func = func_stack[-1][1] if func_stack else None
            if current_func and helper_safety.get(current_func, False):
                continue

            # Otherwise: violation.
            reason = (
                f"regulatory field '{field_name}' added without "
                f"isModuleActive() / isAnyModuleActive() / addModuleGatedField() / "
                f"safe-helper / @no-module-gate-required"
            )
            violations.append((line_no, field_name, reason))

        # Now update brace-state for the next iteration.
        if opens_gate:
            gate_depths.append(brace_depth)

        # If a function was pending AND this line has an opening brace, bind
        # the function body at brace_depth + 1 (since the brace on this line
        # will raise depth by 1 in the increment below). PSR-12 / Symfony
        # typically place the `{` on the *next* line — we then bind on that
        # next line instead.
        if pending_func_name is not None and opens > 0 and not line_declares_func:
            # Brace on a follow-up line — body opens at current depth + 1.
            func_stack.append((brace_depth + 1, pending_func_name))
            pending_func_name = None
        elif pending_func_name is not None and line_declares_func and opens > 0:
            # Declaration AND brace on same line — body opens at depth + 1.
            func_stack.append((brace_depth + 1, pending_func_name))
            pending_func_name = None

        brace_depth += opens
        brace_depth -= closes

        while gate_depths and brace_depth <= gate_depths[-1]:
            gate_depths.pop()
        while func_stack and brace_depth < func_stack[-1][0]:
            func_stack.pop()

    return violations


# Baseline / CLI plumbing ────────────────────────────────────────────────────


def load_baseline(path: Path | None) -> set[str]:
    """Baseline file: one `<relative-path>:<line>:<field>` entry per line."""
    if path is None or not path.exists():
        return set()
    out: set[str] = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        out.add(line)
    return out


def violation_key(rel: Path, line_no: int, field: str) -> str:
    return f"{rel}:{line_no}:{field}"


def _rel(path: Path) -> Path:
    """Return repo-root-relative path when possible, otherwise the path's name."""
    try:
        return path.relative_to(ROOT)
    except ValueError:
        return Path(path.name)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--paths",
        nargs="*",
        default=None,
        help="Scope: explicit file/dir paths (default: src/Form/**/*Type.php).",
    )
    parser.add_argument(
        "--baseline",
        type=Path,
        default=None,
        help="Optional baseline file with pre-existing violations to ignore.",
    )
    parser.add_argument(
        "--write-baseline",
        type=Path,
        default=None,
        help="Write current violation set to file and exit 0 (snapshot mode).",
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="Print summary only.",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Print scan progress per file.",
    )
    args = parser.parse_args()

    # Resolve scope.
    paths: list[Path] = []
    if args.paths:
        for p in args.paths:
            pp = Path(p)
            if not pp.is_absolute():
                pp = ROOT / pp
            if pp.is_file():
                paths.append(pp)
            elif pp.is_dir():
                paths.extend(sorted(pp.rglob("*Type.php")))
    else:
        if not FORM_DIR.is_dir():
            print(f"ERROR: {FORM_DIR} not found", file=sys.stderr)
            return 2
        paths = sorted(FORM_DIR.rglob("*Type.php"))

    baseline = load_baseline(args.baseline)
    all_violations: list[tuple[Path, int, str, str]] = []

    for path in paths:
        if args.verbose:
            print(f"scan {path.relative_to(ROOT)}", file=sys.stderr)
        for line_no, field, reason in check_file(path):
            all_violations.append((path, line_no, field, reason))

    # Snapshot-write mode.
    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write(
                "# check_module_gating.py baseline — generated snapshot.\n"
                "# Format: <relative-path>:<line>:<field-name>\n"
                "# Remove an entry once the FormType is gated properly.\n"
            )
            for path, line_no, field, _reason in all_violations:
                fh.write(f"{_rel(path)}:{line_no}:{field}\n")
        print(
            f"check_module_gating: wrote {len(all_violations)} entries to {args.write_baseline}"
        )
        return 0

    # Filter against baseline.
    new_violations: list[tuple[Path, int, str, str]] = []
    for v in all_violations:
        key = violation_key(_rel(v[0]), v[1], v[2])
        if key not in baseline:
            new_violations.append(v)

    total = len(all_violations)
    new = len(new_violations)
    baselined = total - new

    if args.quiet:
        if new == 0:
            print(
                f"check_module_gating: OK ({total} regulatory field(s) detected, "
                f"all gated or baselined)"
            )
            return 0
    if new == 0:
        if not args.quiet:
            print(
                f"check_module_gating: OK — {total} regulatory field(s) found, "
                f"all properly gated or baselined ({baselined} baselined)."
            )
        return 0

    # Report.
    print("check_module_gating: VIOLATIONS\n")
    for path, line_no, field, reason in new_violations:
        rel = _rel(path)
        print(f"FAIL {rel}:{line_no}: {reason}")
    print(
        f"\n{new} new violation(s)"
        f" ({baselined} baselined, {total} total)."
    )
    print(
        "Fix options:\n"
        "  (a) wrap field in `if ($this->isModuleActive('<key>')) { ... }`\n"
        "  (b) add via `$this->addModuleGatedField($builder, '<key>', '<field>', ...)`\n"
        "  (c) move into a private helper `add{Module}Fields()` invoked from a gated if-block\n"
        "  (d) intentional? add `// @no-module-gate-required: <reason>` above the ->add() call"
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
