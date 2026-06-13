#!/usr/bin/env python3
"""
check_compliance_catalog.py — Static consistency gate for the compliance-catalog
loader wiring.

Background (see docs/COMPLIANCE_CATALOG_ARCHITECTURE.md): catalog loading is wired
through a single registry + match-statement in
`src/Service/ComplianceFrameworkLoaderService.php`. Over time this drifted:
framework-code collisions (ISO-22301 vs ISO22301 vs ISO_22301, BSI_GRUNDSCHUTZ vs
BSI-GRUNDSCHUTZ, ...), registry entries without a loadable match-arm, and
competitor product names leaking into shipped catalog/mapping files.

This gate FAILS (above baseline) on three static defect classes:

  parity:<CODE>            registry code with no match-arm, or match-arm with no
                           registry entry — code appears installable but isn't,
                           or vice-versa.
  collision:<norm>:<raw>   two distinct raw framework-code strings normalise to
                           the same key (same standard, different spelling) where
                           the normalised key is a real registry framework.
  competitor:<path>:<line> banned competitor product name in a catalog/mapping
                           source file (team constraint — see MEMORY).

It is intentionally STATIC. The dynamic check "does every mapping target a
requirementId that the wired loader actually produces" needs a built DB and lives
in `app:audit-catalog-mappings` (Task 0.3), not here.

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

# Framework-code occurrences across PHP. yaml handled separately.
RE_CODE_ARROW = re.compile(r"'code'\s*=>\s*'([^']+)'")
RE_SETCODE = re.compile(r"->setCode\(\s*['\"]([^'\"]+)['\"]")
RE_FINDONEBY_CODE = re.compile(r"findOneBy\(\s*\[\s*'code'\s*=>\s*'([^']+)'")
RE_YAML_CODE = re.compile(r"^\s*code:\s*['\"]?([A-Za-z0-9_.\-]+)['\"]?\s*$")

# match-arm in loadFramework(): 'CODE' => $this->loadXCommand,
RE_MATCH_ARM = re.compile(r"'([^']+)'\s*=>\s*\$this->")


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


def collect_registry(text: str) -> tuple[set[str], set[str]]:
    """Return (registry_codes, match_arm_codes) from the loader service."""
    avail = _slice_method(text, "function getAvailableFrameworks")
    load = _slice_method(text, "function loadFramework")
    registry = set(RE_CODE_ARROW.findall(avail))
    arms = {c for c in RE_MATCH_ARM.findall(load) if c != "default"}
    return registry, arms


def collect_code_occurrences() -> dict[str, set[str]]:
    """normalized -> {raw codes} across PHP (code/setCode/findOneBy) + fixture yaml."""
    groups: dict[str, set[str]] = {}

    def add(raw: str) -> None:
        groups.setdefault(normalize(raw), set()).add(raw)

    for php in (ROOT / "src").rglob("*.php"):
        try:
            t = php.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        for rx in (RE_CODE_ARROW, RE_SETCODE, RE_FINDONEBY_CODE):
            for m in rx.findall(t):
                add(m)
    for mig in (ROOT / "migrations").glob("Version*.php"):
        try:
            t = mig.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        for rx in (RE_CODE_ARROW, RE_SETCODE, RE_FINDONEBY_CODE):
            for m in rx.findall(t):
                add(m)
    for sub in ("fixtures",):
        base = ROOT / sub
        if not base.is_dir():
            continue
        for y in base.rglob("*.yaml"):
            try:
                for line in y.read_text(encoding="utf-8", errors="ignore").splitlines():
                    m = RE_YAML_CODE.match(line)
                    if m:
                        add(m.group(1))
            except OSError:
                continue
    return groups


def find_competitors() -> list[tuple[Path, int, str]]:
    hits: list[tuple[Path, int, str]] = []
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
                        hits.append((f, idx, COMPETITOR_RE.search(line).group(0)))
            except OSError:
                continue
    return hits


def compute_violations() -> list[str]:
    violations: list[str] = []

    if not LOADER_SERVICE.is_file():
        violations.append(f"parity:MISSING-LOADER-SERVICE:{_rel(LOADER_SERVICE)}")
        registry, arms = set(), set()
    else:
        text = LOADER_SERVICE.read_text(encoding="utf-8", errors="ignore")
        registry, arms = collect_registry(text)
        for code in sorted(registry - arms):
            violations.append(f"parity:{code}:registry-without-match-arm")
        for code in sorted(arms - registry):
            violations.append(f"parity:{code}:match-arm-without-registry")

    registry_norms = {normalize(c) for c in registry}
    for norm, raws in sorted(collect_code_occurrences().items()):
        if len(raws) > 1 and norm in registry_norms:
            raw_list = "|".join(sorted(raws))
            violations.append(f"collision:{norm}:{raw_list}")

    for path, line, _word in find_competitors():
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
            fh.write("# Classes: parity:<code>:<why> | collision:<norm>:<raws> | competitor:<path>:<line>\n")
            for v in violations:
                fh.write(v + "\n")
        print(f"check_compliance_catalog: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if v not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        msg = f"check_compliance_catalog: OK ({total} total, all baselined)"
        if not args.quiet:
            msg = f"check_compliance_catalog: OK — {total} known issue(s), {baselined} baselined."
        print(msg)
        return 0

    print("check_compliance_catalog: VIOLATIONS\n")
    for v in new:
        print(f"FAIL {v}")
    print(f"\ncheck_compliance_catalog: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Classes: parity (registry<->match-arm), collision (code spellings), competitor (banned name).")
    print("To accept existing issues into the baseline: --write-baseline scripts/quality/baselines/compliance_catalog.txt")
    return 1


if __name__ == "__main__":
    sys.exit(main())
