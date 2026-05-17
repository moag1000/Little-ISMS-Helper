#!/usr/bin/env python3
"""
check_route_methods.py — Every #[Route(...)] attribute must declare `methods:`.

Routes without an explicit `methods:` list match ALL HTTP verbs — easy to
turn a GET-only show-page into a mutation endpoint via accidental POST.
Symfony recommends always pinning the verbs.

We parse multi-line attributes correctly by bracket-matching.
Allowed: `methods: ['GET']` / `methods: ['POST', 'PUT']` / any method list.

Exit-codes: 0 clean, 1 violations, 2 I/O error.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLER_DIR = ROOT / "src" / "Controller"

RE_ROUTE_ATTR = re.compile(r"#\[\s*Route\s*\(")


def is_skipped(path: Path) -> bool:
    return False  # already scoped to src/Controller


def find_attr_end(text: str, start_paren: int) -> int:
    """Given offset of `(` after `Route`, return offset of matching `)`."""
    depth = 0
    i = start_paren
    in_str: str | None = None
    while i < len(text):
        c = text[i]
        if in_str:
            if c == "\\":
                i += 2
                continue
            if c == in_str:
                in_str = None
            i += 1
            continue
        if c in "\"'":
            in_str = c
            i += 1
            continue
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    return -1


def scan(path: Path) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if "#[Route" not in text:
        return []
    out: list[tuple[int, str]] = []
    for m in RE_ROUTE_ATTR.finditer(text):
        # find '(' (matches end of regex)
        open_paren = m.end() - 1
        if text[open_paren] != "(":
            continue
        close_paren = find_attr_end(text, open_paren)
        if close_paren < 0:
            continue
        body = text[open_paren + 1:close_paren]
        if "methods:" in body or "methods :" in body or "methods=" in body:
            continue
        # Annotation override?
        ln = text.count("\n", 0, m.start()) + 1
        # Check the line before for an override comment
        line_start = text.rfind("\n", 0, m.start()) + 1
        line_text = text[line_start:text.find("\n", line_start) if text.find("\n", line_start) > -1 else len(text)]
        # Allow @no-methods-required comment on the same logical block
        prev_line_end = text.rfind("\n", 0, m.start())
        prev_line_start = text.rfind("\n", 0, prev_line_end) + 1 if prev_line_end > 0 else 0
        prev_line = text[prev_line_start:prev_line_end]
        if "@no-methods-required" in prev_line:
            continue
        out.append((ln, line_text.strip()[:160]))
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

    if not CONTROLLER_DIR.is_dir():
        print(f"ERROR: {CONTROLLER_DIR} not found", file=sys.stderr)
        return 2

    violations: list[tuple[Path, int, str]] = []
    for f in walk(CONTROLLER_DIR):
        for ln, snip in scan(f):
            violations.append((f, ln, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_route_methods.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_route_methods: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_route_methods: OK — {total} occurrence(s), {baselined} baselined.")
        else:
            print(f"check_route_methods: OK ({total} occurrences, all baselined)")
        return 0

    print("check_route_methods: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(f"\ncheck_route_methods: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: add `methods: ['GET']` (or other verbs) to the #[Route(...)] attribute.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
