#!/usr/bin/env python3
"""
Gate 1 — Twig Macro Import-Order Checker

Detects {{ _fa_xxx.method(...) }} USAGE before the FIRST
{% import '...' as _fa_xxx %} for that alias appears in the same file.

This catches the "Variable '_fa_xxx' does not exist" runtime error that
occurs when a macro is used before it is imported, e.g. when an import
statement was moved to the bottom of a template by mistake.

NOTE: This gate handles the file-level import ordering case.
      Embed-scope isolation is handled by check_twig_macro_scope.py
      (already in CI). This gate is intentionally conservative: it only
      flags when a usage appears BEFORE THE FIRST occurrence of the import
      anywhere in the file, regardless of block scope.

Conservative approach (minimises false-positives):
  - Track the FIRST import line for each alias in the file
  - Track the FIRST usage line for each alias in the file
  - Flag only when first_usage < first_import
  - Skip allow-listed files

Allow-list:
  - templates/_components/ — design-system reference files
  - templates/dev/ — developer preview/showcase files (intentional ordering)
  - templates/base.html.twig

Exit 0 = clean, Exit 1 = violations found.
"""

import re
import sys
from pathlib import Path

RE_IMPORT = re.compile(r"""\{%-?\s*import\s+['"][^'"]+['"]\s+as\s+(\w+)\s*-?%\}""")
# Match _fa_xxx.something or any _xx_yyy.something macro alias call
# Exclude: inside string literals (preceded by ', ", /)
RE_MACRO_USAGE = re.compile(r'(?<![\'"/\w])(_[a-z][a-z0-9_]+)\s*\.\s*\w')

SKIP_PREFIXES = (
    'templates/_components/',
    'templates/dev/',
)
SKIP_FILES = {
    'templates/base.html.twig',
}


def check_file(path: Path, repo_root: Path) -> list[tuple[int, str, int]]:
    """
    Returns list of (usage_line, alias, import_line) for each violation.
    Only one violation per alias (first occurrence).
    """
    rel = str(path.relative_to(repo_root))

    for prefix in SKIP_PREFIXES:
        if rel.startswith(prefix):
            return []
    if rel in SKIP_FILES:
        return []

    try:
        content = path.read_text(encoding='utf-8', errors='replace')
    except OSError:
        return []

    if 'import' not in content:
        return []

    lines = content.splitlines()

    # first_import[alias] = line number of first import
    first_import: dict[str, int] = {}
    # first_usage[alias] = line number of first usage
    first_usage: dict[str, int] = {}

    for lineno, line in enumerate(lines, start=1):
        # Record imports
        for m in RE_IMPORT.finditer(line):
            alias = m.group(1)
            if alias not in first_import:
                first_import[alias] = lineno

        # Record macro usages (only aliases that look like _fa_* or similar)
        for m in RE_MACRO_USAGE.finditer(line):
            alias = m.group(1)
            if alias not in first_usage:
                first_usage[alias] = lineno

    violations: list[tuple[int, str, int]] = []
    for alias, usage_line in first_usage.items():
        if alias in first_import:
            import_line = first_import[alias]
            if usage_line < import_line:
                violations.append((usage_line, alias, import_line))

    return violations


def main() -> int:
    repo_root = Path(__file__).resolve().parent.parent.parent
    templates_dir = repo_root / 'templates'

    if not templates_dir.exists():
        print(f"ERROR: templates/ not found at {templates_dir}", file=sys.stderr)
        return 2

    twig_files = sorted(templates_dir.rglob('*.twig'))
    total_violations = 0
    failed_files = 0

    for twig_file in twig_files:
        issues = check_file(twig_file, repo_root)
        if issues:
            failed_files += 1
            for usage_line, alias, import_line in issues:
                rel = twig_file.relative_to(repo_root)
                print(
                    f"{rel}:{usage_line}: macro '{alias}' used at line {usage_line} "
                    f"before first import at line {import_line}"
                )
            total_violations += len(issues)

    if total_violations == 0:
        print(f"OK  Gate 1 — {len(twig_files)} templates checked, no import-order violations.")
        return 0

    print(f"\nGate 1 FAIL: {total_violations} import-order violation(s) in {failed_files} file(s).")
    print("Fix: move {% import %} statements above the first usage of the macro alias.")
    return 1


if __name__ == '__main__':
    sys.exit(main())
