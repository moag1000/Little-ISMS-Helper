#!/usr/bin/env python3
"""
check_no_bi_classes.py — Forbid Bootstrap-Icons `bi-*` class literals.

The Aurora design system replaces Bootstrap-Icons with `.fa-icon--<name>`
classes (see fairy-aurora-icons.css). Stray `bi-*` references render as
empty boxes because the BI font is not loaded.

Detects:
  - Twig:  class="bi …"  or  class="bi-<name> …"  or  <i class="bi-X">
  - PHP:   'bi-<name>'   or   "bi-<name>"          string literals
           (excluding src/Lifecycle/ — parallel agent territory)

Allowed:
  - the bare identifier `bi` (no hyphen) and `$bi`, `Bi*` PHP names
  - Twig dynamic class output `class="{{ ... }}"`
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

SKIP_DIRS = {"vendor", "node_modules", "var", ".claude", "tests/Fixtures", "migrations", "docs"}
PHP_SKIP_PREFIX = ("src/Lifecycle",)

# Twig: class="bi bi-foo" / class="bi-foo bar" / single `class="bi"` (font-only)
RE_TWIG_BI_CLASS = re.compile(
    r"""class\s*=\s*["']               # class="
        (?:[^"']*\s)?                   # optional preceding classes
        bi(?:-[a-z0-9-]+)?              # `bi` alone OR `bi-foo`
        (?:\s[^"']*)?                   # optional trailing classes
        ["']
    """,
    re.VERBOSE,
)

# PHP: 'bi-name' / "bi-name" string literals
RE_PHP_BI_LITERAL = re.compile(r"""['"]bi-[a-z0-9][a-z0-9-]*['"]""")


def is_skipped(path: Path) -> bool:
    parts = path.relative_to(ROOT).parts
    for skip in SKIP_DIRS:
        if "/" in skip:
            seg = tuple(skip.split("/"))
            if any(parts[i:i + len(seg)] == seg for i in range(len(parts))):
                return True
        elif skip in parts:
            return True
    return False


def scan_twig(path: Path) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if "bi" not in text:
        return []
    out: list[tuple[int, str]] = []
    for idx, raw in enumerate(text.splitlines(), start=1):
        s = raw.lstrip()
        if s.startswith("{#"):
            continue
        if RE_TWIG_BI_CLASS.search(raw):
            out.append((idx, raw.strip()[:160]))
    return out


def scan_php(path: Path) -> list[tuple[int, str]]:
    rel = path.relative_to(ROOT).as_posix()
    if any(rel.startswith(p) for p in PHP_SKIP_PREFIX):
        return []
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if "bi-" not in text:
        return []
    out: list[tuple[int, str]] = []
    for idx, raw in enumerate(text.splitlines(), start=1):
        s = raw.lstrip()
        if s.startswith("//") or s.startswith("*") or s.startswith("#"):
            continue
        if RE_PHP_BI_LITERAL.search(raw):
            out.append((idx, raw.strip()[:160]))
    return out


def walk(root: Path, pat: str) -> list[Path]:
    return sorted(p for p in root.glob(pat) if p.is_file() and not is_skipped(p))


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

    violations: list[tuple[Path, int, str]] = []
    for f in walk(ROOT / "templates", "**/*.html.twig"):
        for ln, snip in scan_twig(f):
            violations.append((f, ln, snip))
    for f in walk(ROOT / "src", "**/*.php"):
        for ln, snip in scan_php(f):
            violations.append((f, ln, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_no_bi_classes.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_no_bi_classes: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_no_bi_classes: OK — {total} reference(s), {baselined} baselined.")
        else:
            print(f"check_no_bi_classes: OK ({total} refs, all baselined)")
        return 0

    print("check_no_bi_classes: VIOLATIONS\n")
    for path, ln, snip in new:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    print(f"\ncheck_no_bi_classes: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: replace `bi-<name>` with `<i class=\"fa-icon fa-icon--<name>\">`.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
