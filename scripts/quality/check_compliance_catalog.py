#!/usr/bin/env python3
"""
check_compliance_catalog.py — Static consistency gate for the compliance-catalog
framework wiring.

Background (see docs/COMPLIANCE_CATALOG_ARCHITECTURE.md): framework loading is
wired through `ComplianceFrameworkLoaderService::getAvailableFrameworks()` (the
hand-maintained UI list) + a `FrameworkLoaderRegistry` that collects tagged
`FrameworkLoaderInterface` loaders by `getFrameworkCode()`. Drift between the UI
list and the loaders means a framework appears installable in the UI but has no
loader (broken "Load" button). Codes also drifted into spelling collisions
(ISO-22301 vs ISO22301, BSI_GRUNDSCHUTZ vs BSI-GRUNDSCHUTZ), and competitor names
leaked into shipped catalog/mapping files.

This gate FAILS (above baseline) on three static defect classes:

  parity:<CODE>:no-loader   a getAvailableFrameworks() code has no loader whose
                            getFrameworkCode() returns it (UI lists it, nothing
                            loads it).
  collision:<norm>:<raws>   two distinct framework-code spellings normalise to the
                            same key, where that key is a real registry framework.
  competitor:<path>:<line>  banned competitor product name in a catalog/mapping
                            source file (team constraint — see MEMORY).

STATIC by design. The dynamic check "does every mapping target a requirementId the
loader actually produces" lives in `app:audit-catalog-mappings`, not here.

Baseline-gated like the other scripts/quality/check_*.py gates.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LOADER_SERVICE = ROOT / "src" / "Service" / "ComplianceFrameworkLoaderService.php"

# Competitor product names banned from shipped code/docs/mappings (team rule).
# Standards (ISO/BSI/NIST/AICPA/ENISA) are fine.
COMPETITOR_RE = re.compile(
    r"\b(vanta|drata|secureframe|verinice|hiscout|secfix|tenfold|eramba)\b",
    re.IGNORECASE,
)
COMPETITOR_GLOBS = [
    ("src/Command", "*Mapping*.php"),
    ("src/Command", "Seed*.php"),
    ("src/Controller", "ComplianceMapping*.php"),
    ("fixtures/library/mappings", "*.yaml"),
    ("fixtures/mappings", "*.csv"),
    ("fixtures/mappings", "*.yaml"),
]

# Framework-code occurrences across PHP / fixtures (for collision detection).
RE_CODE_ARROW = re.compile(r"'code'\s*=>\s*'([^']+)'")
RE_SETCODE = re.compile(r"->setCode\(\s*['\"]([^'\"]+)['\"]")
RE_FINDONEBY_CODE = re.compile(r"findOneBy\(\s*\[\s*'code'\s*=>\s*'([^']+)'")
RE_YAML_CODE = re.compile(r"^\s*code:\s*['\"]?([A-Za-z0-9_.\-]+)['\"]?\s*$")

# Loader code: FrameworkLoaderInterface::getFrameworkCode() — literal or self::CODE.
RE_GETCODE_LITERAL = re.compile(
    r"function\s+getFrameworkCode\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s*'([^']+)'",
    re.DOTALL,
)
RE_GETCODE_CONST = re.compile(
    r"function\s+getFrameworkCode\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s*self::CODE\b",
    re.DOTALL,
)
RE_CONST_CODE = re.compile(r"const\s+CODE\s*=\s*'([^']+)'")


def _rel(p: Path) -> Path:
    try:
        return p.relative_to(ROOT)
    except ValueError:
        return Path(p.name)


def _slice_method(text: str, signature: str) -> str:
    """Return the brace-balanced body following a method signature substring."""
    start = text.find(signature)
    if start == -1:
        return ""
    brace = text.find("{", start)
    if brace == -1:
        return ""
    depth = 0
    for i in range(brace, len(text)):
        c = text[i]
        if c == "{":
            depth += 1
        elif c == "}":
            depth -= 1
            if depth == 0:
                return text[brace : i + 1]
    return text[brace:]


def normalize(code: str) -> str:
    return re.sub(r"[-_.\s]", "", code).upper()


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out: set[str] = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        s = raw.strip()
        if s and not s.startswith("#"):
            out.add(s)
    return out


def collect_registry_codes(text: str) -> list[str]:
    """getAvailableFrameworks() literal 'code' => '...' values (variable codes skipped)."""
    avail = _slice_method(text, "function getAvailableFrameworks")
    return RE_CODE_ARROW.findall(avail)


def collect_loader_codes() -> set[str]:
    """getFrameworkCode() return values across src/ (literal + self::CODE resolved)."""
    codes: set[str] = set()
    for php in (ROOT / "src").rglob("*.php"):
        try:
            t = php.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        if "function getFrameworkCode" not in t:
            continue
        m = RE_GETCODE_LITERAL.search(t)
        if m:
            codes.add(m.group(1))
            continue
        if RE_GETCODE_CONST.search(t):
            cm = RE_CONST_CODE.search(t)
            if cm:
                codes.add(cm.group(1))
    return codes


def collect_code_occurrences() -> dict[str, set[str]]:
    """normalized -> {raw codes} across PHP (code/setCode/findOneBy) + fixture yaml."""
    groups: dict[str, set[str]] = {}

    def add(raw: str) -> None:
        groups.setdefault(normalize(raw), set()).add(raw)

    # NOTE: migrations/ is deliberately NOT scanned — historical migrations
    # legitimately reference retired alias codes. Collision detection is about
    # LIVE config: src/ + fixtures/.
    for php in (ROOT / "src").rglob("*.php"):
        try:
            t = php.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        for rx in (RE_CODE_ARROW, RE_SETCODE, RE_FINDONEBY_CODE):
            for m in rx.findall(t):
                add(m)
    base = ROOT / "fixtures"
    if base.is_dir():
        for y in base.rglob("*.yaml"):
            try:
                for line in y.read_text(encoding="utf-8", errors="ignore").splitlines():
                    m = RE_YAML_CODE.match(line)
                    if m:
                        add(m.group(1))
            except OSError:
                continue
    return groups


def find_competitors() -> list[tuple[Path, int]]:
    hits: list[tuple[Path, int]] = []
    seen: set[Path] = set()
    for sub, pat in COMPETITOR_GLOBS:
        base = ROOT / sub
        if not base.is_dir():
            continue
        for f in base.rglob(pat):
            if not f.is_file() or f in seen:
                continue
            seen.add(f)
            try:
                for idx, line in enumerate(f.read_text(encoding="utf-8", errors="ignore").splitlines(), 1):
                    if COMPETITOR_RE.search(line):
                        hits.append((f, idx))
            except OSError:
                continue
    return hits


def compute_violations() -> list[str]:
    violations: list[str] = []

    if not LOADER_SERVICE.is_file():
        violations.append(f"parity:MISSING-LOADER-SERVICE:{_rel(LOADER_SERVICE)}")
        registry_codes: list[str] = []
    else:
        text = LOADER_SERVICE.read_text(encoding="utf-8", errors="ignore")
        registry_codes = collect_registry_codes(text)
        loader_codes = collect_loader_codes()
        for code in sorted(set(registry_codes)):
            if code not in loader_codes:
                violations.append(f"parity:{code}:no-loader")

    registry_norms = {normalize(c) for c in registry_codes}
    for norm, raws in sorted(collect_code_occurrences().items()):
        if len(raws) > 1 and norm in registry_norms:
            violations.append(f"collision:{norm}:{'|'.join(sorted(raws))}")

    for path, line in find_competitors():
        violations.append(f"competitor:{_rel(path)}:{line}")

    return sorted(set(violations))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    violations = compute_violations()

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_compliance_catalog.py baseline\n")
            fh.write("# Classes: parity:<code>:no-loader | collision:<norm>:<raws> | competitor:<path>:<line>\n")
            for v in violations:
                fh.write(v + "\n")
        print(f"check_compliance_catalog: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if v not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if args.quiet:
            print(f"check_compliance_catalog: OK ({total} total, all baselined)")
        else:
            print(f"check_compliance_catalog: OK — {total} known issue(s), {baselined} baselined.")
        return 0

    print("check_compliance_catalog: VIOLATIONS\n")
    for v in new:
        print(f"FAIL {v}")
    print(f"\ncheck_compliance_catalog: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Classes: parity (registry code without a loader), collision (code spellings), competitor (banned name).")
    print("To accept existing issues into the baseline: --write-baseline scripts/quality/baselines/compliance_catalog.txt")
    return 1


if __name__ == "__main__":
    sys.exit(main())
