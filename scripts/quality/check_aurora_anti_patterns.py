#!/usr/bin/env python3
"""
Gate 5 — Aurora v4 Anti-Pattern Checker

Per CLAUDE.md "Bootstrap vs Aurora class precedence" and
templates/_components/_CARD_GUIDE.md §"Anti-Patterns":

Bootstrap utility-classes on .card / .card-header silently fail because
Aurora's CSS specificity wins via load-order or equal specificity. The
intent (e.g. a blue hero tile) never reaches the user — they see gray.

Detected anti-patterns:

  AP-1: Bootstrap bg-* or text-white on .card element
        <div class="card bg-primary ..."> — Aurora's surface token wins
        Fix: use <fa-feature-card> macro or KPI card variant

  AP-2: Bootstrap bg-* or text-white on .card-header element
        <div class="card-header bg-success"> — Aurora .card > .card-header wins
        Fix: use Aurora token-level color styling or Aurora macros

  AP-3: badge pill anti-pattern: badge + bg-*-subtle + text-body/text-muted
        <span class="badge bg-primary-subtle text-body">
        Aurora status-pills should use .fa-status-pill, not Bootstrap badge

  AP-4: text-white or text-body directly on .card (not on inner elements)
        <div class="card text-white"> — Aurora color tokens override

Allow-list:
  - templates/_components/ — design-system reference files (intentional showcase)
  - Any line that is a Twig comment {# ... #}

Exit 0 = clean, Exit 1 = violations found.
"""

import re
import sys
from pathlib import Path

SKIP_PREFIX = 'templates/_components/'

# AP-1: .card element with Bootstrap bg-* or text-white/text-body
# Matches: class="... card ... bg-primary" or class="card bg-..."
# The card class and bad class must be in the SAME class= attribute value
AP1_PATTERN = re.compile(
    r'class=["\']([^"\']*\bcard\b[^"\']*\b(?:bg-(?:primary|secondary|success|danger|info|warning|light|dark)'
    r'|text-white|text-body)\b[^"\']*)["\']',
    re.IGNORECASE
)

# AP-2: .card-header element with Bootstrap bg-* or text-white
AP2_PATTERN = re.compile(
    r'class=["\']([^"\']*\bcard-header\b[^"\']*\b(?:bg-(?:primary|secondary|success|danger|info|warning|light|dark)'
    r'|text-white)\b[^"\']*)["\']',
    re.IGNORECASE
)

# AP-3: badge + bg-*-subtle + text-body or text-muted (pill anti-pattern)
AP3_PATTERN = re.compile(
    r'class=["\']([^"\']*\bbadge\b[^"\']*\bbg-(?:primary|secondary|success|danger|info|warning|light|dark)-subtle\b'
    r'[^"\']*\btext-(?:body|muted|white)\b[^"\']*)["\']',
    re.IGNORECASE
)

PATTERNS = [
    ('AP-1', 'Bootstrap bg-*/text-white on .card overridden by Aurora surface token', AP1_PATTERN),
    ('AP-2', 'Bootstrap bg-*/text-white on .card-header overridden by Aurora', AP2_PATTERN),
    ('AP-3', 'badge+bg-*-subtle+text-body pill anti-pattern, use .fa-status-pill', AP3_PATTERN),
]


def check_file(path: Path, repo_root: Path) -> list[tuple[int, str, str, str]]:
    """
    Returns list of (lineno, pattern_id, description, matched_class).
    """
    rel = str(path.relative_to(repo_root))
    if rel.startswith(SKIP_PREFIX):
        return []

    try:
        content = path.read_text(encoding='utf-8', errors='replace')
    except OSError:
        return []

    if 'class=' not in content:
        return []

    lines = content.splitlines()
    violations: list[tuple[int, str, str, str]] = []

    for lineno, line in enumerate(lines, start=1):
        # Skip Twig comment lines
        stripped = line.strip()
        if stripped.startswith('{#'):
            continue

        for pat_id, description, pattern in PATTERNS:
            for m in pattern.finditer(line):
                matched = m.group(1)[:80]  # truncate for readability
                violations.append((lineno, pat_id, description, matched))

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
            for lineno, pat_id, description, matched in issues:
                rel = twig_file.relative_to(repo_root)
                print(f"{rel}:{lineno}: aurora-anti-pattern [{pat_id}]: {description}")
                print(f"  matched: ...{matched}...")
            total_violations += len(issues)

    if total_violations == 0:
        print(f"OK  Gate 5 — {len(twig_files)} templates checked, no Aurora anti-patterns.")
        return 0

    print(f"\nGate 5 FAIL: {total_violations} Aurora anti-pattern(s) in {failed_files} file(s).")
    print("Fix: use Aurora macros (_fa_feature_card, _fa_entity_card) or .fa-status-pill.")
    print("See: templates/_components/_CARD_GUIDE.md §Anti-Patterns")
    return 1


if __name__ == '__main__':
    sys.exit(main())
