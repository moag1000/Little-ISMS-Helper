#!/usr/bin/env python3
"""
check_god_class_size.py — Soft-fail when a service/controller balloons.

Thresholds (configurable inside the script):
  src/Service/*.php    LOC > 1500    OR    >15 constructor deps
  src/Controller/*.php LOC >  600

A "constructor dep" is any parameter on `__construct(...)` (counting comma
separators at depth 1). Doctrine entity-manager / translator etc. all count.

Soft-fail: a baseline file captures the current offenders. New additions
that push a file over threshold fail CI; growing an already-baselined file
beyond its current size is also caught by re-running the gate (the baseline
stores file:LOC, so any LOC > baseline-LOC triggers).
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICE_LOC_MAX = 1500
SERVICE_DEPS_MAX = 15
CONTROLLER_LOC_MAX = 600

RE_CTOR = re.compile(r"public\s+function\s+__construct\s*\(")


def count_loc(path: Path) -> int:
    try:
        return len(path.read_text(encoding="utf-8", errors="ignore").splitlines())
    except OSError:
        return 0


def count_ctor_deps(path: Path) -> int:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return 0
    m = RE_CTOR.search(text)
    if not m:
        return 0
    # Find matching closing paren
    start = m.end() - 1  # the '('
    depth = 0
    i = start
    end = -1
    while i < len(text):
        c = text[i]
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                end = i
                break
        i += 1
    if end < 0:
        return 0
    body = text[start + 1:end]
    if not body.strip():
        return 0
    # Count commas at depth 0
    depth = 0
    deps = 1
    for c in body:
        if c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
        elif c == "," and depth == 0:
            deps += 1
    return deps


def scan_service(path: Path) -> tuple[int, int, str] | None:
    loc = count_loc(path)
    deps = count_ctor_deps(path)
    if loc > SERVICE_LOC_MAX or deps > SERVICE_DEPS_MAX:
        return loc, deps, f"LOC={loc} deps={deps}"
    return None


def scan_controller(path: Path) -> tuple[int, int, str] | None:
    loc = count_loc(path)
    if loc > CONTROLLER_LOC_MAX:
        return loc, 0, f"LOC={loc}"
    return None


def load_baseline(path: Path | None) -> dict[str, tuple[int, int]]:
    """Parse `<rel>:LOC=N deps=M` into {rel: (loc, deps)}."""
    out: dict[str, tuple[int, int]] = {}
    if path is None or not path.exists():
        return out
    for raw in path.read_text(encoding="utf-8").splitlines():
        s = raw.strip()
        if not s or s.startswith("#"):
            continue
        # format: rel:LOC=NNN deps=MMM    or    rel:LOC=NNN
        parts = s.split(":", 1)
        if len(parts) != 2:
            continue
        rel = parts[0]
        rest = parts[1]
        m_loc = re.search(r"LOC=(\d+)", rest)
        m_dep = re.search(r"deps=(\d+)", rest)
        out[rel] = (int(m_loc.group(1)) if m_loc else 0, int(m_dep.group(1)) if m_dep else 0)
    return out


def _rel(p: Path) -> Path:
    try:
        return p.relative_to(ROOT)
    except ValueError:
        return Path(p.name)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    offenders: list[tuple[Path, int, int, str]] = []  # path, loc, deps, snippet
    for f in sorted((ROOT / "src" / "Service").rglob("*.php")):
        r = scan_service(f)
        if r:
            loc, deps, snip = r
            offenders.append((f, loc, deps, snip))
    for f in sorted((ROOT / "src" / "Controller").rglob("*.php")):
        r = scan_controller(f)
        if r:
            loc, deps, snip = r
            offenders.append((f, loc, deps, snip))

    offenders.sort(key=lambda x: -x[1])  # biggest first

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_god_class_size.py baseline\n")
            fh.write("# Format: <relative-path>:LOC=NNN deps=MMM\n")
            fh.write("# NEW file added OR existing file growing beyond LOC will fail CI.\n")
            for path, loc, deps, _snip in offenders:
                fh.write(f"{_rel(path)}:LOC={loc} deps={deps}\n")
        print(f"check_god_class_size: wrote {len(offenders)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new: list[tuple[Path, int, int, str]] = []
    grown: list[tuple[Path, int, int, str, int]] = []  # +baseline_loc
    for path, loc, deps, snip in offenders:
        rel = str(_rel(path))
        if rel not in baseline:
            new.append((path, loc, deps, snip))
        else:
            b_loc, _ = baseline[rel]
            # allow shrinkage; only fail when LOC grows beyond previously-baselined value
            if loc > b_loc:
                grown.append((path, loc, deps, snip, b_loc))

    if not new and not grown:
        if not args.quiet:
            print(f"check_god_class_size: OK — {len(offenders)} file(s) over threshold, all baselined.")
        else:
            print(f"check_god_class_size: OK ({len(offenders)} baselined)")
        return 0

    print("check_god_class_size: VIOLATIONS\n")
    for path, loc, deps, snip in new:
        print(f"FAIL {_rel(path)}:1: NEW god-class ({snip})")
    for path, loc, deps, snip, b_loc in grown:
        print(f"FAIL {_rel(path)}:1: GREW {snip} (was LOC={b_loc})")
    total_bad = len(new) + len(grown)
    print(f"\ncheck_god_class_size: {total_bad} new violation(s) ({len(offenders) - total_bad} baselined, {len(offenders)} total).")
    print("Fix: extract sub-services, split controller actions, or refactor toward smaller classes.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
