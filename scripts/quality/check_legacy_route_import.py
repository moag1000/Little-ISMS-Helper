#!/usr/bin/env python3
r"""
check_legacy_route_import.py — Forbid legacy `Routing\Annotation\Route` import.

Symfony 6+ deprecated `Symfony\Component\Routing\Annotation\Route` in favor
of `Symfony\Component\Routing\Attribute\Route`. The two are interchangeable
at runtime, but having both styles in the same codebase creates IDE warnings
and breaks the new RouterDebugCommand reflection in Symfony 7.4.

Detects: `use Symfony\Component\Routing\Annotation\Route;` in src/**/*.php.

Allowed: nothing — this is a one-line auto-fix per file.
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC_DIR = ROOT / "src"
NEEDLE = "use Symfony\\Component\\Routing\\Annotation\\Route"


def scan(path: Path) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if NEEDLE not in text:
        return []
    out: list[tuple[int, str]] = []
    for idx, raw in enumerate(text.splitlines(), start=1):
        if NEEDLE in raw:
            out.append((idx, raw.strip()[:160]))
    return out


def walk(root: Path) -> list[Path]:
    return sorted(p for p in root.rglob("*.php") if p.is_file())


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        s = raw.strip()
        if s and not s.startswith("#"):
            out.add(s)
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

    if not SRC_DIR.is_dir():
        print(f"ERROR: {SRC_DIR} not found", file=sys.stderr)
        return 2

    violations: list[tuple[Path, int, str]] = []
    for f in walk(SRC_DIR):
        for ln, snip in scan(f):
            violations.append((f, ln, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_legacy_route_import.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_legacy_route_import: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_legacy_route_import: OK — {total} occurrence(s), {baselined} baselined.")
        else:
            print(f"check_legacy_route_import: OK ({total}, all baselined)")
        return 0

    print("check_legacy_route_import: VIOLATIONS\n")
    for path, ln, snip in new:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    print(f"\ncheck_legacy_route_import: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: replace `Routing\\Annotation\\Route` with `Routing\\Attribute\\Route`.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
