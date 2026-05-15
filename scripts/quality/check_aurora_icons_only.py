#!/usr/bin/env python3
"""
Gate 11 — Aurora-only icon policy.

Enforces two rules:
1. No Bootstrap-icon classes (bi bi-<name>) in templates/ or assets/controllers/
2. No fa-icon--<name> references that are not in the Aurora canonical icon set
   (defined in assets/styles/fairy-aurora-icons.css)

Excludes:
- assets/Little ISMS Helper Design System/  (design-system reference HTML, not shipped)
- assets/styles/fairy-aurora-icons.css      (the definition file itself)
- docs/                                     (markdown docs, not shipped)
- templates/_components/_BADGE_GUIDE.md, _CARD_GUIDE.md, _FORM_ACCESSIBILITY_GUIDE.md,
  _table_accessibility_guide.md            (dev guide docs)

Exit 0 = clean, exit 1 = violations, exit 2 = internal error.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

# ---------------------------------------------------------------------------
# Paths excluded from scanning (relative to project root)
# ---------------------------------------------------------------------------
EXCLUDE_PATH_FRAGMENTS = [
    "assets/Little ISMS Helper Design System",
    "docs/",
    "scripts/",
    "templates/_components/_BADGE_GUIDE.md",
    "templates/_components/_CARD_GUIDE.md",
    "templates/_components/_FORM_ACCESSIBILITY_GUIDE.md",
    "templates/_components/_table_accessibility_guide.md",
    "templates/_components/_CARD_GUIDE",
    "node_modules",
    "var/",
    ".git/",
]

# Size modifier classes that look like fa-icon--N (numbers) — these are
# sizing helpers, not icon references; exclude from the undefined check.
SIZE_CLASSES_RE = re.compile(r"^fa-icon--\d+$")

# Dynamic Twig expressions produce partial class names like fa-icon--util-arrow-{{ direction }}
# The regex must not match these because after the dash the content is a Twig variable.
# We match only if the class ends at a word boundary (not followed by { or }.
BI_PATTERN = re.compile(r"\bbi bi-[a-z0-9][a-z0-9-]*")
FA_ICON_PATTERN = re.compile(r"fa-icon--([a-z0-9][a-z0-9-]*?)(?![a-z0-9-])")

# Dynamic prefix fragments that are valid base classes (used with Twig variable suffix)
DYNAMIC_CLASS_PREFIXES = {
    "fa-icon--util-arrow-",  # fa-icon--util-arrow-{{ delta.direction }}
    "fa-icon--status-",  # fa-icon--status-{{ status }} dynamic
}


def load_canonical_icons(project_root: Path) -> set[str]:
    css_path = project_root / "assets" / "styles" / "fairy-aurora-icons.css"
    if not css_path.is_file():
        print(f"ERROR: Aurora CSS not found at {css_path}", file=sys.stderr)
        sys.exit(2)
    content = css_path.read_text(encoding="utf-8")
    # Extract fa-icon--<name> class selectors
    classes = re.findall(r"\.fa-icon--([a-z0-9][a-z0-9-]*)", content)
    # Return full class names as "fa-icon--<name>"
    return {f"fa-icon--{c}" for c in classes}


def should_exclude(file_path: Path, project_root: Path) -> bool:
    rel = str(file_path.relative_to(project_root))
    return any(frag in rel for frag in EXCLUDE_PATH_FRAGMENTS)


def scan_file_for_bi(file_path: Path) -> list[tuple[int, str]]:
    try:
        lines = file_path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        return []
    hits = []
    for lineno, line in enumerate(lines, 1):
        # Skip lines that already have a TODO comment marking them as pending design work
        if "TODO: design Aurora" in line or "TODO: map to Aurora" in line:
            continue
        # Skip dynamic Twig interpolated class names (e.g. bi bi-${...} or bi bi-{{ }})
        if BI_PATTERN.search(line):
            # Skip fully dynamic (JS template literal or Twig expression)
            if re.search(r'bi bi-\$\{|\bdi bi-\{\{', line):
                continue
            matches = BI_PATTERN.findall(line)
            for m in matches:
                hits.append((lineno, m.strip()))
    return hits


def scan_file_for_undefined_fa(
    file_path: Path, canonical: set[str]
) -> list[tuple[int, str]]:
    try:
        lines = file_path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        return []
    hits = []
    for lineno, line in enumerate(lines, 1):
        for name in FA_ICON_PATTERN.findall(line):
            full = f"fa-icon--{name}"
            if SIZE_CLASSES_RE.match(full):
                continue
            # Skip known dynamic class prefix patterns (Twig variable suffix)
            if any(full.startswith(prefix) or full + "-" in DYNAMIC_CLASS_PREFIXES
                   for prefix in DYNAMIC_CLASS_PREFIXES):
                continue
            if full not in canonical:
                hits.append((lineno, full))
    return hits


def walk_scan_dirs(project_root: Path):
    """Yield files to scan from templates/, assets/controllers/, src/."""
    scan_dirs = [
        project_root / "templates",
        project_root / "assets" / "controllers",
        project_root / "src",
    ]
    suffixes = {".twig", ".html", ".js", ".ts", ".php"}
    for d in scan_dirs:
        if not d.is_dir():
            continue
        for f in sorted(d.rglob("*")):
            if f.is_file() and f.suffix in suffixes:
                yield f


def main() -> int:
    project_root = Path(__file__).resolve().parents[2]
    canonical = load_canonical_icons(project_root)

    bi_violations: list[tuple[str, int, str]] = []
    undef_violations: list[tuple[str, int, str]] = []

    scanned = 0
    for fpath in walk_scan_dirs(project_root):
        if should_exclude(fpath, project_root):
            continue
        scanned += 1
        rel = str(fpath.relative_to(project_root))

        for lineno, match in scan_file_for_bi(fpath):
            bi_violations.append((rel, lineno, match))

        for lineno, icon in scan_file_for_undefined_fa(fpath, canonical):
            undef_violations.append((rel, lineno, icon))

    total = len(bi_violations) + len(undef_violations)

    if bi_violations:
        print(f"\n[Gate 11] Bootstrap-icon (bi bi-*) references found — replace with Aurora:")
        for rel, lineno, match in bi_violations:
            print(f"  {rel}:{lineno}  {match}")

    if undef_violations:
        print(f"\n[Gate 11] Undefined Aurora icon references (fa-icon--<name> not in CSS):")
        seen = set()
        for rel, lineno, icon in undef_violations:
            key = (rel, lineno, icon)
            if key not in seen:
                seen.add(key)
                print(f"  {rel}:{lineno}  {icon}")

    if total == 0:
        print(
            f"OK  Gate 11 — Aurora-only icons. {scanned} files scanned, "
            f"{len(canonical)} canonical icons, 0 violations."
        )
        return 0

    bi_count = len(bi_violations)
    undef_count = len(set((r, l, i) for r, l, i in undef_violations))
    print(
        f"\nFAIL  Gate 11 — {bi_count} Bootstrap-icon reference(s) + "
        f"{undef_count} undefined Aurora icon reference(s)."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
