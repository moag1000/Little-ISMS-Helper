#!/usr/bin/env python3
r"""
check_no_generic_throws.py — Forbid generic exception throws.

`throw new \Exception('...')` (and the equivalent generic SPL exceptions)
defeats centralized error handling, breaks the `App\Exception\*` hierarchy
that drives our API/HTTP error mapping, and makes catch-blocks ambiguous.

Forbidden globally in src/:
  - Exception
  - RuntimeException
  - InvalidArgumentException
  - LogicException
  - DomainException

Allowed:
  - src/Exception/* (the canonical hierarchy may extend SPL types).
  - any line whose previous non-empty line carries
    `// @intentional-assertion: <reason>` — e.g. for guard-clauses that the
    author has decided should remain a LogicException.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC_DIR = ROOT / "src"
EXCEPTION_DIR = SRC_DIR / "Exception"

GENERIC_EXC = ("Exception", "RuntimeException", "InvalidArgumentException", "LogicException", "DomainException")
# `throw new \Foo(...)` or `throw new Foo(...)`
RE_THROW = re.compile(
    r"throw\s+new\s+\\?(" + "|".join(GENERIC_EXC) + r")\s*\("
)
RE_ANNOTATION = re.compile(r"//\s*@intentional-assertion(?::\s*.+)?")


def is_in_exception_dir(path: Path) -> bool:
    try:
        path.relative_to(EXCEPTION_DIR)
        return True
    except ValueError:
        return False


def scan(path: Path) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if "throw new" not in text:
        return []
    out: list[tuple[int, str]] = []
    lines = text.splitlines()
    for idx, raw in enumerate(lines):
        line_no = idx + 1
        s = raw.lstrip()
        if s.startswith("//") or s.startswith("*") or s.startswith("#"):
            continue
        m = RE_THROW.search(raw)
        if not m:
            continue
        # Look back up to 3 prior non-empty lines for annotation override.
        skip = False
        for back in range(1, 4):
            j = idx - back
            if j < 0:
                break
            prev = lines[j].strip()
            if not prev:
                continue
            if RE_ANNOTATION.search(prev):
                skip = True
                break
            if not (prev.startswith("//") or prev.startswith("*")):
                break
        if skip:
            continue
        # Same-line annotation
        if RE_ANNOTATION.search(raw):
            continue
        out.append((line_no, raw.strip()[:160]))
    return out


def walk(root: Path) -> list[Path]:
    return sorted(p for p in root.rglob("*.php") if p.is_file() and not is_in_exception_dir(p))


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
            fh.write("# check_no_generic_throws.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_no_generic_throws: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_no_generic_throws: OK — {total} occurrence(s), {baselined} baselined.")
        else:
            print(f"check_no_generic_throws: OK ({total}, all baselined)")
        return 0

    print("check_no_generic_throws: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(f"\ncheck_no_generic_throws: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: use App\\Exception\\* hierarchy, OR add `// @intentional-assertion: <reason>` above the throw.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
