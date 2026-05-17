#!/usr/bin/env python3
"""
check_aurora_icon_names.py — Verify every `fa-icon--<name>` reference resolves
to a class defined in `assets/styles/fairy-aurora-icons.css`.

Scope:
  - templates/**/*.html.twig
  - src/**/*.php           (excluding vendor)
  - assets/controllers/**/*.js

Allowed dynamic forms (NOT checked, can't statically resolve):
  - class="fa-icon fa-icon--{{ var }}"
  - "fa-icon--" . $var

Exit-codes: 0 clean / baselined, 1 violations, 2 I/O error.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CANON_CSS = ROOT / "assets" / "styles" / "fairy-aurora-icons.css"

SKIP_DIRS = {"vendor", "node_modules", "var", ".claude", "migrations", "tests/Fixtures", "docs"}

# Matches `fa-icon--<lowercase-name>`; rejects names containing `{` (dynamic).
RE_ICON = re.compile(r"fa-icon--([a-z0-9][a-z0-9-]*)")
RE_DYNAMIC = re.compile(r"fa-icon--\{")  # `fa-icon--{{ var }}` or `fa-icon--{$x}`


def load_canon() -> set[str]:
    if not CANON_CSS.is_file():
        print(f"ERROR: {CANON_CSS} not found", file=sys.stderr)
        sys.exit(2)
    css = CANON_CSS.read_text(encoding="utf-8")
    return set(re.findall(r"\.fa-icon--([a-z0-9][a-z0-9-]*)", css))


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


def strip_comments(line: str, ext: str) -> str:
    """Strip line/block-comment leading marker so we don't false-positive in
    docstrings/examples."""
    s = line.lstrip()
    if ext == ".php":
        if s.startswith("//") or s.startswith("*") or s.startswith("/*") or s.startswith("#"):
            return ""
    elif ext == ".js":
        if s.startswith("//") or s.startswith("*") or s.startswith("/*"):
            return ""
    elif ext in (".twig", ".html"):
        # Twig comments {# ... #} — if entire line is a comment skip
        if s.startswith("{#"):
            return ""
    return line


def scan_file(path: Path, canon: set[str]) -> list[tuple[int, str, str]]:
    """Return list of (line_no, snippet, bad-name)."""
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if "fa-icon--" not in text:
        return []
    ext = path.suffix
    out: list[tuple[int, str, str]] = []
    for idx, raw in enumerate(text.splitlines(), start=1):
        line = strip_comments(raw, ext)
        if not line or "fa-icon--" not in line:
            continue
        # Mask dynamic placeholders, e.g. fa-icon--{{ var }}
        masked = RE_DYNAMIC.sub("fa-icon--__DYN__", line)
        for m in RE_ICON.finditer(masked):
            name = m.group(1)
            if name == "__dyn__":
                continue
            if name in canon:
                continue
            out.append((idx, raw.strip()[:160], name))
    return out


def walk(root: Path, patterns: list[str]) -> list[Path]:
    files: list[Path] = []
    for pat in patterns:
        for p in root.glob(pat):
            if p.is_file() and not is_skipped(p):
                files.append(p)
    return sorted(set(files))


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

    canon = load_canon()
    files = (
        walk(ROOT / "templates", ["**/*.html.twig"])
        + walk(ROOT / "src", ["**/*.php"])
        + walk(ROOT / "assets" / "controllers", ["**/*.js"])
    )

    violations: list[tuple[Path, int, str, str]] = []
    for f in files:
        for ln, snip, name in scan_file(f, canon):
            violations.append((f, ln, snip, name))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_aurora_icon_names.py baseline\n")
            fh.write("# Format: <relative-path>:<line>:<bad-icon-name>\n")
            for path, ln, _snip, name in violations:
                fh.write(f"{_rel(path)}:{ln}:{name}\n")
        print(f"check_aurora_icon_names: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}:{v[3]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_aurora_icon_names: OK — {total} reference(s), {baselined} baselined.")
        else:
            print(f"check_aurora_icon_names: OK ({total} refs, all baselined)")
        return 0

    print("check_aurora_icon_names: VIOLATIONS\n")
    for path, ln, snip, name in new:
        print(f"FAIL {_rel(path)}:{ln}: unknown icon 'fa-icon--{name}'  | {snip}")
    print(f"\ncheck_aurora_icon_names: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: add the class to assets/styles/fairy-aurora-icons.css OR use a canonical name.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
