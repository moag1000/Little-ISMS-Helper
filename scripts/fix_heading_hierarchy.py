#!/usr/bin/env python3
"""
Safe heading hierarchy fixer for Twig templates.
Changes start and end tags together to avoid mismatches.
"""

import re
import os
import sys
from pathlib import Path

def fix_heading_in_content(content: str, old_tag: str, new_tag: str, context_pattern: str = None) -> tuple:
    """
    Safely replace heading tags, ensuring start and end tags are changed together.
    """
    count = 0
    pattern = rf'<{old_tag}(\s[^>]*)?>(.+?)</{old_tag}>'

    def replacer(match):
        nonlocal count
        attrs = match.group(1) or ''
        inner = match.group(2)

        if context_pattern:
            start = max(0, match.start() - 150)
            context = content[start:match.start()]
            if not re.search(context_pattern, context):
                return match.group(0)

        count += 1
        return f'<{new_tag}{attrs}>{inner}</{new_tag}>'

    new_content = re.sub(pattern, replacer, content, flags=re.DOTALL)
    return new_content, count


def validate_heading_balance(content: str) -> list:
    """Check that all heading tags are properly balanced."""
    issues = []
    for tag in ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']:
        opens = len(re.findall(rf'<{tag}[\s>]', content))
        closes = len(re.findall(rf'</{tag}>', content))
        if opens != closes:
            issues.append(f"{tag}: {opens} opens, {closes} closes")
    return issues


def main():
    rules = [
        {'old_tag': 'h2', 'new_tag': 'h3', 'context_pattern': r'card-header'}
    ]

    templates_dir = Path('templates')
    twig_files = list(templates_dir.rglob('*.twig'))
    print(f"Found {len(twig_files)} Twig files")

    dry_run = '--dry-run' in sys.argv
    results = []

    for filepath in sorted(twig_files):
        if '.bak' in str(filepath):
            continue

        try:
            content = filepath.read_text(encoding='utf-8')
            original = content
            total_changes = 0
            details = []

            for rule in rules:
                content, count = fix_heading_in_content(
                    content, rule['old_tag'], rule['new_tag'], rule.get('context_pattern')
                )
                if count > 0:
                    total_changes += count
                    details.append(f"{rule['old_tag']}>{rule['new_tag']}: {count}")

            if total_changes > 0:
                if not dry_run:
                    filepath.write_text(content, encoding='utf-8')
                results.append({'file': str(filepath), 'changes': total_changes, 'details': details})

        except Exception as e:
            print(f"ERROR: {filepath}: {e}")

    print(f"\n{'DRY RUN - ' if dry_run else ''}Results:")
    print("-" * 60)

    for r in results:
        print(f"{r['file']}: {', '.join(r['details'])}")

    print("-" * 60)
    print(f"Total: {sum(r['changes'] for r in results)} changes in {len(results)} files")

    if not dry_run and results:
        print("\nValidating...")
        for r in results:
            content = Path(r['file']).read_text(encoding='utf-8')
            issues = validate_heading_balance(content)
            if issues:
                print(f"  WARNING {r['file']}: {issues}")
        print("Done!")


if __name__ == '__main__':
    main()
