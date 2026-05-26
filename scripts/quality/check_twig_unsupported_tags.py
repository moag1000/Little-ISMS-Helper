#!/usr/bin/env python3
r"""
check_twig_unsupported_tags.py — Forbid unsupported Twig 3.x control tags.

Twig 3.x does not have ``{% continue %}`` or ``{% break %}`` tags.
Using them causes a ``Twig\Error\SyntaxError: Unknown tag "continue"``
runtime exception which caused 67+ test failures in PR #689.

Correct replacement: wrap the loop body in ``{% if condition %}...{% endif %}``
to skip iterations, or restructure the loop to avoid needing break/continue.

The scanner:
- Walks all ``templates/**/*.twig`` files.
- Strips ``{# ... #}`` comment blocks first (tags inside comments are fine).
- Reports any line containing a literal ``{% continue %}`` or ``{% break %}``
  tag (with optional surrounding whitespace inside the braces).
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES_DIR = ROOT / "templates"

BAD_TAGS = ("continue", "break")

# Strip Twig block comments (multi-line aware)
RE_BLOCK_COMMENT = re.compile(r"\{#.*?#\}", re.DOTALL)

# Match {% continue %} or {% break %} (whitespace-flexible)
RE_BAD_TAG = re.compile(r"\{%-?\s*(" + "|".join(BAD_TAGS) + r")\s*-?%\}")


def scan(path: Path) -> list[tuple[int, str]]:
    try:
        raw = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []

    # Quick skip before heavy work
    if "{%" not in raw:
        return []
    tag_found = any(tag in raw for tag in BAD_TAGS)
    if not tag_found:
        return []

    # Strip {# ... #} comment blocks so tags inside comments are not flagged
    cleaned = RE_BLOCK_COMMENT.sub("", raw)

    violations: list[tuple[int, str]] = []
    for idx, line in enumerate(cleaned.split("\n"), start=1):
        m = RE_BAD_TAG.search(line)
        if m:
            violations.append((idx, line.strip()[:160]))
    return violations


def walk(root: Path) -> list[Path]:
    return sorted(p for p in root.rglob("*.twig") if p.is_file())


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out: set[str] = set()
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

    if not TEMPLATES_DIR.is_dir():
        print(f"ERROR: {TEMPLATES_DIR} not found", file=sys.stderr)
        return 2

    all_violations: list[tuple[Path, int, str]] = []
    for f in walk(TEMPLATES_DIR):
        for ln, snip in scan(f):
            all_violations.append((f, ln, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_twig_unsupported_tags.py baseline\n")
            fh.write("# Format: <relative-path>:<line>\n")
            for path, ln, _snip in all_violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(
            f"check_twig_unsupported_tags: wrote {len(all_violations)} entries"
            f" to {args.write_baseline}"
        )
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in all_violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(all_violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(
                f"check_twig_unsupported_tags: OK — {total} occurrence(s),"
                f" {baselined} baselined."
            )
        return 0

    print("check_twig_unsupported_tags: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(
        f"\ncheck_twig_unsupported_tags: {len(new)} new violation(s)"
        f" ({baselined} baselined, {total} total)."
    )
    print(
        "Fix: Twig 3.x has no `{% continue %}` / `{% break %}` tags.\n"
        "Wrap the loop body in `{% if condition %}...{% endif %}` to skip"
        " iterations, or restructure the loop to avoid the need."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
