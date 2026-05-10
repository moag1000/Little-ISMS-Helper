#!/usr/bin/env python3
"""
Twig Macro-Scope Static Checker

Detects the pattern where {% import 'X' as Y %} is declared at file-scope
(outside any block) in a template that also uses {% extends %}, but the
macro variable Y is referenced inside a {% embed %}...{% endembed %} block
WITHOUT a local {% import %} for the alias within that embed context.

Key distinction:
- Regular {% block %} in extending templates CAN see file-scope imports (Twig
  inheritance propagates them).  These are NOT bugs.
- {% embed %} has its own isolated scope and CANNOT see file-scope imports.
  A local {% import %} must appear inside the embed block for the alias to work.

The checker also avoids false-positives caused by alias names matching embed
path strings (e.g. `{% embed '_components/_fa_alert.html.twig' %}` would
naively match the alias `_fa_alert` — this checker only flags real macro calls
i.e. patterns like `alias.method`, not string-literal occurrences).

Twig silently parses this without error. At render-time the embed block has its
own scope and cannot see the file-scope import — resulting in
"Variable 'Y' does not exist" exceptions.

This bug is invisible to `php bin/console lint:twig`.

Regression guard for bulk-fix commits 075e36a4 and 8797122c.

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

# {% import '...' as alias %} — captures alias
RE_IMPORT = re.compile(r"""\{%-?\s*import\s+['"][^'"]+['"]\s+as\s+(\w+)\s*-?%\}""")

# {% embed '...' %} opening tag
RE_EMBED_OPEN = re.compile(r"""\{%-?\s*embed\s+""")

# {% endembed %}
RE_EMBED_CLOSE = re.compile(r"""\{%-?\s*endembed\s*-?%\}""")

# {% block name %} — opening block tag
RE_BLOCK_OPEN = re.compile(r"""\{%-?\s*block\s+\w+""")

# {% endblock %} or {% endblock name %}
RE_BLOCK_CLOSE = re.compile(r"""\{%-?\s*endblock(?:\s+\w+)?\s*-?%\}""")


def make_real_call_pattern(alias: str) -> re.Pattern:
    """
    Match `alias.word` as a Twig macro call, but NOT when the alias appears
    inside a string literal (e.g. '_components/_fa_alias.html.twig' would
    otherwise match because / is not a word character — we exclude preceding
    /, ', and " characters).
    """
    return re.compile(r'(?<![\'"/\w])' + re.escape(alias) + r'\s*\.\s*\w')


def check_file(path: Path) -> list[tuple[int, str, int]]:
    """
    Check a single Twig file for embed-scope macro issues.

    Returns a list of (import_line_no, alias, first_usage_line_no) tuples
    for each problematic import (file-scope import used inside an embed block
    without a local import in that embed context).
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

    # Fast-path: skip files without any embed
    if '{% embed' not in content and '{%- embed' not in content:
        return []

    # Find line number of first {% block %} occurrence
    first_block_line: int | None = None
    for i, line in enumerate(lines, start=1):
        if RE_BLOCK_OPEN.search(line):
            first_block_line = i
            break

    if first_block_line is None:
        return []

    # Collect imports that appear BEFORE first_block_line (file-scope imports)
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

    issues: list[tuple[int, str, int]] = []

    for import_line, alias in file_scope_imports:
        real_call_re = make_real_call_pattern(alias)

        block_depth = 0
        # embed_stack: list of sets, each set holds aliases locally imported
        # within that embed level. Push on embed open, pop on endembed.
        embed_stack: list[set[str]] = []

        for i, line in enumerate(lines, start=1):
            embed_opens = len(RE_EMBED_OPEN.findall(line))
            embed_closes = len(RE_EMBED_CLOSE.findall(line))
            block_opens = len(RE_BLOCK_OPEN.findall(line))
            block_closes = len(RE_BLOCK_CLOSE.findall(line))

            # Push new embed context(s) before checking imports/usage on this line
            for _ in range(embed_opens):
                embed_stack.append(set())

            # Track local imports within the current embed context
            if embed_stack:
                m = RE_IMPORT.search(line)
                if m:
                    embed_stack[-1].add(m.group(1))

            block_depth += block_opens

            # Check for real macro call inside embed + block context
            if block_depth > 0 and embed_stack and real_call_re.search(line):
                # Alias is locally imported if any enclosing embed level imported it
                locally_imported = any(alias in s for s in embed_stack)
                if not locally_imported:
                    issues.append((import_line, alias, i))
                    break  # report first occurrence only

            block_depth -= block_closes
            if block_depth < 0:
                block_depth = 0

            # Pop embed context(s) after processing closes
            for _ in range(embed_closes):
                if embed_stack:
                    embed_stack.pop()

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
                    f"macro '{alias}' imported at file-scope but used inside embed-block "
                    f"at line {usage_line} without local import"
                )
            total_issues += len(issues)

    if total_issues == 0:
        print(f"OK  {len(twig_files)} templates checked — no embed-scope macro issues found.")
        return 0
    else:
        print(f"\n{total_issues} embed-scope macro issue(s) in {failed_files} template(s).")
        print(
            "Fix: add {%% import '_components/_fa_X.html.twig' as alias %%} "
            "at the top of the embed block where the macro is used."
        )
        return 1


if __name__ == '__main__':
    sys.exit(main())
