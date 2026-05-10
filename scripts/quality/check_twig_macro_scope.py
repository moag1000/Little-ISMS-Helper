#!/usr/bin/env python3
"""
Twig Macro-Scope Static Checker

Detects the pattern where {% import 'X' as Y %} is declared at file-scope
(outside any block) in a template that also uses {% extends %}, but the
macro variable Y is referenced inside a {% block %}...{% endblock %} section.

Twig silently parses this without error. At render-time the block has its own
scope and cannot see the file-scope import — resulting in
"Variable 'Y' does not exist" exceptions.

This bug is invisible to `php bin/console lint:twig`.

Regression guard for bulk-fix commit 075e36a4 (48 templates repaired).

Usage:
    python3 scripts/quality/check_twig_macro_scope.py
    # Exit 0 = clean, Exit 1 = issues found

    # From repo root:
    python3 scripts/quality/check_twig_macro_scope.py

    # Verbose output to file:
    python3 scripts/quality/check_twig_macro_scope.py 2>&1 | tee macro_scope_report.txt
"""

import re
import sys
from pathlib import Path

# --- Regex patterns ---

# {% extends '...' %} or {% extends "..." %}
RE_EXTENDS = re.compile(r"""\{%-?\s*extends\s+['"]""")

# {% import '...' as alias %} — captures (source_path, alias, line)
# Matches both single and double quotes
RE_IMPORT = re.compile(r"""\{%-?\s*import\s+['"][^'"]+['"]\s+as\s+(\w+)\s*-?%\}""")

# {% block name %} — opening block tag
RE_BLOCK_OPEN = re.compile(r"""\{%-?\s*block\s+\w+\s*-?%\}""")

# {% endblock %} or {% endblock name %}
RE_BLOCK_CLOSE = re.compile(r"""\{%-?\s*endblock(?:\s+\w+)?\s*-?%\}""")

# Macro usage: alias.something or alias.something(...) including whitespace variants
# We look for the alias name followed by a dot (member access), as Twig uses dot for macros
# Pattern: word boundary + alias + "." (not inside string literals — best-effort)
def make_usage_pattern(alias: str) -> re.Pattern:
    return re.compile(r'\b' + re.escape(alias) + r'\s*\.')


def check_file(path: Path) -> list[tuple[int, str, int]]:
    """
    Check a single Twig file for macro-scope issues.

    Returns a list of (import_line_no, alias, first_usage_line_no) tuples
    for each problematic import.
    """
    try:
        content = path.read_text(encoding='utf-8', errors='replace')
    except OSError:
        return []

    lines = content.splitlines()

    # Fast-path: skip files without {% extends %}
    if not RE_EXTENDS.search(content):
        return []

    # Fast-path: skip files without any import
    if '{% import' not in content and '{%- import' not in content:
        return []

    issues: list[tuple[int, str, int]] = []

    # --- Pass 1: locate file-scope imports ---
    # "File-scope" = before the first {% block %} tag that is NOT itself inside
    # a set/macro/embed block. We track nesting depth.

    # Find line number of first {% block %} occurrence
    first_block_line: int | None = None
    for i, line in enumerate(lines, start=1):
        if RE_BLOCK_OPEN.search(line) or re.search(r'\{%-?\s*block\s+\w+', line):
            first_block_line = i
            break

    if first_block_line is None:
        # No blocks at all — imports are always file-scope but also never used
        # inside blocks, so nothing to report.
        return []

    # Collect imports that appear BEFORE first_block_line
    file_scope_imports: list[tuple[int, str]] = []  # (line_no, alias)
    for i, line in enumerate(lines, start=1):
        if i >= first_block_line:
            break
        m = RE_IMPORT.search(line)
        if m:
            alias = m.group(1)
            file_scope_imports.append((i, alias))

    if not file_scope_imports:
        return []

    # --- Pass 2: for each file-scope import, check if alias is used inside a block ---
    for import_line, alias in file_scope_imports:
        usage_pattern = make_usage_pattern(alias)

        # Walk lines tracking block nesting depth; report first usage inside block
        depth = 0
        for i, line in enumerate(lines, start=1):
            # Count block opens/closes on this line
            opens = len(re.findall(r'\{%-?\s*block\s+\w+', line))
            closes = len(RE_BLOCK_CLOSE.findall(line))
            depth_before = depth
            depth += opens
            if depth > 0:
                # We are inside at least one block — check for alias usage
                if usage_pattern.search(line):
                    issues.append((import_line, alias, i))
                    break  # report first occurrence only
            depth -= closes
            if depth < 0:
                depth = 0

    return issues


def main() -> int:
    repo_root = Path(__file__).resolve().parent.parent.parent
    templates_dir = repo_root / 'templates'

    if not templates_dir.exists():
        print(f"ERROR: templates directory not found at {templates_dir}", file=sys.stderr)
        return 2

    twig_files = sorted(templates_dir.rglob('*.twig'))
    total_issues = 0
    failed_files = 0

    for twig_file in twig_files:
        issues = check_file(twig_file)
        if issues:
            failed_files += 1
            rel_path = twig_file.relative_to(repo_root)
            for import_line, alias, usage_line in issues:
                print(
                    f"FAIL {rel_path}:{import_line}: "
                    f"macro '{alias}' imported at file-scope but used inside block "
                    f"at line {usage_line}"
                )
            total_issues += len(issues)

    if total_issues == 0:
        print(f"OK  {len(twig_files)} templates checked — no macro-scope issues found.")
        return 0
    else:
        print(f"\n{total_issues} macro-scope issue(s) in {failed_files} template(s).")
        print("Fix: move the {% import %} statement inside the {% block %} where it is used.")
        return 1


if __name__ == '__main__':
    sys.exit(main())
