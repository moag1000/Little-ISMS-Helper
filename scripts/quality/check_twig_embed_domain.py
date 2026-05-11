#!/usr/bin/env python3
"""
Gate 2 — Embed-Block trans_default_domain Checker

Detects {% block X %}...{% endblock %} INSIDE {% embed %}...{% endembed %}
that contain |trans (without an explicit 2nd-arg domain) but do NOT have
{% trans_default_domain '...' %} at the start of that block.

Root cause: Twig {% embed %} creates a fully isolated scope. The outer
template's {% trans_default_domain %} does NOT propagate into embed block-
overrides. If a block uses |trans without either:
  a) an explicit 2nd domain arg:   'key'|trans({}, 'domain')
  b) its own {% trans_default_domain 'domain' %} directive
...Twig falls back to the 'messages' domain (often wrong) and may render
raw keys.

Conservative approach:
  - Only flag blocks that use bare |trans (no 2nd argument).
  - Blocks that exclusively use |trans({}, 'explicit-domain') are fine.
  - Skip templates in _components/ and base.html.twig.

Exit 0 = clean, Exit 1 = violations.
"""

import re
import sys
from pathlib import Path

RE_EMBED_OPEN  = re.compile(r"""\{%-?\s*embed\s+""")
RE_EMBED_CLOSE = re.compile(r"""\{%-?\s*endembed\s*-?%\}""")
RE_BLOCK_OPEN  = re.compile(r"""\{%-?\s*block\s+(\w+)\s*-?%\}""")
RE_BLOCK_CLOSE = re.compile(r"""\{%-?\s*endblock\b""")
RE_TRANS_DEFAULT_DOMAIN = re.compile(r"""\{%-?\s*trans_default_domain\b""")
# |trans WITHOUT a 2nd domain argument: 'x'|trans or 'x'|trans({params}) but no 'domain'
# We detect |trans that is NOT followed by (anything, 'word') — conservative regex
RE_TRANS_NO_DOMAIN = re.compile(
    r"""['"]([^'"]+)['"]\s*\|\s*trans\s*(?:\(\s*\{[^}]*\}\s*\)|\(\s*\))?(?!\s*\()"""
)
# |trans with explicit domain arg: 'x'|trans({}, 'domain') or 'x'|trans('domain')
RE_TRANS_WITH_DOMAIN = re.compile(
    r"""['"]([^'"]+)['"]\s*\|\s*trans\s*\([^)]*,\s*['"][a-z_]+['"]\s*\)"""
)

SKIP_PREFIX = 'templates/_components/'
SKIP_FILES = {'templates/base.html.twig'}


def check_file(path: Path, repo_root: Path) -> list[tuple[int, str, str]]:
    """
    Returns list of (block_open_lineno, block_name, reason).
    """
    rel = str(path.relative_to(repo_root))
    if rel in SKIP_FILES or rel.startswith(SKIP_PREFIX):
        return []

    try:
        content = path.read_text(encoding='utf-8', errors='replace')
    except OSError:
        return []

    # Fast-path: no embed or no trans
    if ('embed' not in content) or ('trans' not in content):
        return []

    lines = content.splitlines()
    violations: list[tuple[int, str, str]] = []

    embed_depth = 0
    # Stack of embed block contexts: each entry = {name, open_line, has_domain, has_bare_trans}
    block_stack: list[dict] = []

    for lineno, line in enumerate(lines, start=1):
        n_embed_opens = len(RE_EMBED_OPEN.findall(line))
        n_embed_closes = len(RE_EMBED_CLOSE.findall(line))
        n_block_opens = len(RE_BLOCK_OPEN.findall(line))
        n_block_closes = len(RE_BLOCK_CLOSE.findall(line))

        # Handle embed opens
        for _ in range(n_embed_opens):
            embed_depth += 1

        # Handle block opens (only track when inside embed)
        if embed_depth > 0 and n_block_opens > 0:
            m = RE_BLOCK_OPEN.search(line)
            if m:
                block_name = m.group(1)
                block_stack.append({
                    'name': block_name,
                    'open_line': lineno,
                    'has_domain': False,
                    'has_bare_trans': False,
                })

        # Analyse line content when inside an embed block
        if embed_depth > 0 and block_stack:
            ctx = block_stack[-1]
            if RE_TRANS_DEFAULT_DOMAIN.search(line):
                ctx['has_domain'] = True
            # Check for bare |trans (no explicit domain)
            # Exclude lines that have the domain form
            bare_matches = RE_TRANS_NO_DOMAIN.findall(line)
            domain_matches = RE_TRANS_WITH_DOMAIN.findall(line)
            if bare_matches and len(bare_matches) > len(domain_matches):
                ctx['has_bare_trans'] = True

        # Handle block closes (inside embed only)
        if embed_depth > 0 and n_block_closes > 0 and block_stack:
            ctx = block_stack.pop()
            if ctx['has_bare_trans'] and not ctx['has_domain']:
                violations.append((
                    ctx['open_line'],
                    ctx['name'],
                    f"embed-block uses |trans without trans_default_domain"
                ))

        # Handle embed closes — pop any remaining block entries for this embed level
        for _ in range(n_embed_closes):
            embed_depth = max(0, embed_depth - 1)
            # Flush any unclosed blocks (shouldn't happen in valid Twig)
            block_stack.clear()

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
            for open_line, block_name, reason in issues:
                rel = twig_file.relative_to(repo_root)
                print(f"{rel}:{open_line}: embed-block '{block_name}': {reason}")
            total_violations += len(issues)

    if total_violations == 0:
        print(f"OK  Gate 2 — {len(twig_files)} templates checked, no embed-block domain issues.")
        return 0

    print(f"\nGate 2 FAIL: {total_violations} embed-block domain violation(s) in {failed_files} file(s).")
    print("Fix: add {% trans_default_domain 'domain' %} at the start of each flagged block.")
    return 1


if __name__ == '__main__':
    sys.exit(main())
