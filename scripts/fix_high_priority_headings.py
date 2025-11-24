#!/usr/bin/env python3
"""
Fix high-priority heading hierarchy issues
Focus on user-facing pages that need h1
"""
import re
from pathlib import Path

# Files that are full pages and should start with h1
HIGH_PRIORITY_FIXES = {
    # DPIA pages - currently start with h5
    'dpia/edit.html.twig': {
        'pattern': r'<h5 class="mb-0">([^<]+)</h5>',
        'replacement': r'<h1 class="h5 mb-0">\1</h1>',
        'reason': 'Card header in modal-like page, use h1 with h5 styling'
    },
    'dpia/new.html.twig': {
        'pattern': r'<h5 class="mb-0">([^<]+)</h5>',
        'replacement': r'<h1 class="h5 mb-0">\1</h1>',
        'reason': 'Card header in modal-like page'
    },
    # Asset pages - currently start with h3
    'asset/edit.html.twig': {
        'pattern': r'<h3>([^<]+)</h3>',
        'replacement': r'<h1>\1</h1>',
        'reason': 'Main page title'
    },
    'asset/index_modern.html.twig': {
        'pattern': r'<h3>([^<]+)</h3>',
        'replacement': r'<h2>\1</h2>',  # Assuming page_header has h1
        'reason': 'Section title, page header has h1'
    },
    # License pages - currently start with h5
    'license/index.html.twig': {
        'pattern': r'<h5>([^<]+)</h5>',
        'replacement': r'<h1 class="h5">\1</h1>',
        'reason': 'Main heading with h5 styling'
    },
    'license/report.html.twig': {
        'pattern': r'<h5>([^<]+)</h5>',
        'replacement': r'<h1 class="h5">\1</h1>',
        'reason': 'Main heading with h5 styling'
    },
}

def fix_heading(file_path, config):
    """Fix heading in a specific file"""
    templates_dir = Path('templates')
    full_path = templates_dir / file_path

    if not full_path.exists():
        print(f"⚠️  File not found: {file_path}")
        return False

    with open(full_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original = content

    # Apply the fix
    content = re.sub(
        config['pattern'],
        config['replacement'],
        content,
        count=1  # Only fix the first occurrence (main heading)
    )

    if content != original:
        with open(full_path, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"✓ Fixed {file_path}")
        print(f"  Reason: {config['reason']}")
        return True
    else:
        print(f"⚠️  No match found in {file_path}")
        return False

def main():
    print("=== Fixing High Priority Heading Issues ===\n")

    fixed = 0
    not_found = 0

    for file_path, config in HIGH_PRIORITY_FIXES.items():
        if fix_heading(file_path, config):
            fixed += 1
        else:
            not_found += 1

    print(f"\n✓ Fixed {fixed} files")
    if not_found > 0:
        print(f"⚠️  {not_found} files had no matches (may already be fixed)")

    print("\nNote: Run python3 scripts/audit_heading_hierarchy.py to see remaining issues")

if __name__ == '__main__':
    main()
