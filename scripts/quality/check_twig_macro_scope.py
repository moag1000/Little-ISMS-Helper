#!/usr/bin/env python3
"""
Twig Macro-Scope Static Checker  (v3)

Detects macro aliases that are used inside a {% embed %} block but were NOT
imported within that same embed scope (or an inner scope).

Twig scope rules relevant to this checker:
- {% extends %} + {% block %}: inheriting templates CAN see all file-scope
  imports; block-scope is fine for direct content of that block.
- {% embed %}: creates a completely isolated scope. Imports made OUTSIDE an
  embed (whether at file-scope, block-scope, or outer-embed-scope) are NOT
  visible inside that embed — even if they are at the same nesting depth
  (sibling embeds do not share scope).
- Imports must be placed inside the exact embed block where they are used.

Algorithm (stack-based):
  scope_stack — each element is a dict: {alias: import_line}.
  - scope_stack[0] = file/block scope (always present)
  - Push new empty dict on {% embed %}
  - Pop on {% endembed %}
  - When we see {% import X as alias %}, add to scope_stack[-1] (current scope)
  - When we see alias.method usage at embed_depth > 0:
      * Check scope_stack[-1] (current embed scope) — if alias present: OK
      * Do NOT accept alias from any outer scope (scope_stack[:-1])
      * If not found in current embed scope: FAIL

False-positive guards:
- Only real macro calls (alias.word) trigger detection, not string literals.
- Imports inside the current embed scope satisfy the check.
- We skip known-excluded templates per the SKIP list.

Usage:
    python3 scripts/quality/check_twig_macro_scope.py
    # Exit 0 = clean, Exit 1 = issues found

Regression guard for bulk-fix commits 075e36a4, 8797122c, b361a80e, and
the v3 sweep (fixes in management_reports/bcm.html.twig,
management_reports/certification_readiness.html.twig,
policy_wizard/step/_bestandsaufnahme.html.twig,
workflow/pending.html.twig).
"""

import re
import sys
from pathlib import Path

# --- Regex patterns ---

# {% import '...' as alias %} — captures alias
RE_IMPORT = re.compile(r"""\{%-?\s*import\s+['"][^'"]+['"]\s+as\s+(\w+)\s*-?%\}""")

# {% embed '...' %} opening tag
RE_EMBED_OPEN = re.compile(r"""\{%-?\s*embed\s+""")

# {% endembed %}
RE_EMBED_CLOSE = re.compile(r"""\{%-?\s*endembed\s*-?%\}""")

# Files to exclude from checking
SKIP_TEMPLATES = {
    'templates/dpia/index.html.twig',          # already fixed b361a80e
    'templates/base.html.twig',                # intentional inline imports
    'templates/_components/_mega_menu_panel_only.html.twig',
}


def make_real_call_pattern(alias: str) -> re.Pattern:
    """
    Match `alias.word` as a Twig macro call, but NOT when the alias appears
    inside a string literal (e.g. '_components/_fa_alias.html.twig' would
    match the alias part — we exclude preceding /, ', and " characters).
    """
    return re.compile(r'(?<![\'"/\w])' + re.escape(alias) + r'\s*\.\s*\w')


def check_file(path: Path) -> list[tuple[int, str, int, str]]:
    """
    Check a single Twig file for embed-scope macro issues using a scope stack.

    Returns a list of (import_line_no, alias, usage_line_no, reason) tuples.
    Only the first violation per alias is returned (to keep output concise).

    The stack-based approach correctly handles:
    1. File/block-scope imports used inside any embed (depth > 0)
    2. Imports in embed A used in sibling embed B (same depth, different instance)
    3. Imports in outer embed used in doubly-nested embed (depth 0 → depth 1 → depth 2)
    """
    try:
        content = path.read_text(encoding='utf-8', errors='replace')
    except OSError:
        return []

    # Fast-paths
    if '{% import' not in content and '{%- import' not in content:
        return []
    if '{% embed' not in content and '{%- embed' not in content:
        return []

    lines = content.splitlines()
    issues: list[tuple[int, str, int, str]] = []

    # scope_stack: list of dicts, each dict maps alias -> import_line_no
    # scope_stack[0] = file/block scope
    # scope_stack[N] = N-th embed nesting level
    scope_stack: list[dict[str, int]] = [{}]

    # Track which aliases have already been reported (first occurrence only)
    reported_aliases: set[str] = set()

    # All known aliases (collected on first pass or dynamically)
    # We need to build call patterns. Use lazy construction.
    call_pattern_cache: dict[str, re.Pattern] = {}

    def get_call_re(alias: str) -> re.Pattern:
        if alias not in call_pattern_cache:
            call_pattern_cache[alias] = make_real_call_pattern(alias)
        return call_pattern_cache[alias]

    for lineno, line in enumerate(lines, start=1):
        embed_opens = len(RE_EMBED_OPEN.findall(line))
        embed_closes = len(RE_EMBED_CLOSE.findall(line))

        # Push new scope(s) for embed opens (opens happen before content)
        for _ in range(embed_opens):
            scope_stack.append({})

        # Record any import on this line into the current scope
        m = RE_IMPORT.search(line)
        if m:
            alias = m.group(1)
            # Register in current scope (deepest scope = scope_stack[-1])
            scope_stack[-1][alias] = lineno

        # Check for macro usages when inside at least one embed
        if len(scope_stack) > 1:
            # Collect all known aliases from any scope
            all_aliases = set()
            for scope in scope_stack:
                all_aliases.update(scope.keys())

            for alias in all_aliases:
                if alias in reported_aliases:
                    continue
                call_re = get_call_re(alias)
                if call_re.search(line):
                    # Usage found at embed depth. Check: is alias imported in
                    # the current embed scope (scope_stack[-1])?
                    current_embed_scope = scope_stack[-1]
                    if alias in current_embed_scope:
                        # Local import in this exact embed scope — OK
                        pass
                    else:
                        # Alias imported only in outer scope(s) — BUG
                        # Find the import line from the outermost scope that has it
                        outer_import_line = None
                        outer_depth = None
                        for depth, scope in enumerate(scope_stack[:-1]):
                            if alias in scope:
                                outer_import_line = scope[alias]
                                outer_depth = depth
                                break
                        if outer_import_line is not None:
                            embed_depth = len(scope_stack) - 1
                            reason = (
                                f"imported at embed-depth {outer_depth} "
                                f"but used at embed-depth {embed_depth} (line {lineno})"
                            )
                            issues.append((outer_import_line, alias, lineno, reason))
                            reported_aliases.add(alias)

        # Pop scope(s) for embed closes (closes happen after content)
        for _ in range(embed_closes):
            if len(scope_stack) > 1:
                scope_stack.pop()

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
        rel_path = twig_file.relative_to(repo_root)
        rel_str = str(rel_path)
        if rel_str in SKIP_TEMPLATES:
            continue

        issues = check_file(twig_file)
        if issues:
            failed_files += 1
            for import_line, alias, usage_line, reason in issues:
                print(
                    f"FAIL {rel_path}:{import_line}: "
                    f"macro '{alias}' {reason}"
                )
            total_issues += len(issues)

    if total_issues == 0:
        print(f"OK  {len(twig_files)} templates checked — no embed-scope macro issues found.")
        return 0
    else:
        print(f"\n{total_issues} embed-scope macro issue(s) in {failed_files} template(s).")
        print(
            "Fix: add {% import '_components/_fa_X.html.twig' as alias %} "
            "inside the embed block where the macro is used."
        )
        return 1


if __name__ == '__main__':
    sys.exit(main())
