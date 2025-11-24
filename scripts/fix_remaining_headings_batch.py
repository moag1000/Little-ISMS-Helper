#!/usr/bin/env python3
"""
Batch fix remaining heading hierarchy issues
Handles multiple patterns automatically
"""
import re
from pathlib import Path

def fix_file(file_path, fixes):
    """Apply multiple fixes to a file"""
    templates_dir = Path('templates')
    full_path = templates_dir / file_path

    if not full_path.exists():
        return False, f"File not found: {file_path}"

    with open(full_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original = content
    applied = []

    for fix in fixes:
        pattern = fix['pattern']
        replacement = fix['replacement']
        count = fix.get('count', 1)

        if re.search(pattern, content):
            content = re.sub(pattern, replacement, content, count=count)
            applied.append(fix.get('description', 'Applied fix'))

    if content != original:
        with open(full_path, 'w', encoding='utf-8') as f:
            f.write(content)
        return True, applied

    return False, "No changes needed"

# Define fixes for each file
FIXES = {
    # DPIA pages - h5 card headers should be h1
    'dpia/new.html.twig': [{
        'pattern': r'<div class="card-header"><h5 class="mb-0">([^<]+)</h5>',
        'replacement': r'<div class="card-header"><h1 class="h5 mb-0">\1</h1>',
        'description': 'Changed h5 to h1 with h5 styling'
    }],

    'dpia/show.html.twig': [{
        'pattern': r'<h5>([^<]+)</h5>',
        'replacement': r'<h2>\1</h2>',
        'count': 8,
        'description': 'Changed section h5 to h2 (assuming page has h1)'
    }],

    # License pages
    'license/index.html.twig': [{
        'pattern': r'<h5>',
        'replacement': r'<h1 class="h5">',
        'description': 'Changed h5 to h1 with h5 styling'
    }, {
        'pattern': r'</h5>',
        'replacement': r'</h1>',
        'description': 'Closing tag'
    }],

    'license/report.html.twig': [{
        'pattern': r'<h5>',
        'replacement': r'<h1 class="h5">',
        'count': 1
    }, {
        'pattern': r'</h5>',
        'replacement': r'</h1>',
        'count': 1
    }],

    'license/summary.html.twig': [{
        'pattern': r'<h5>',
        'replacement': r'<h2>',
        'count': 1,
        'description': 'First h5 to h1'
    }, {
        'pattern': r'</h5>',
        'replacement': r'</h2>',
        'count': 1
    }],

    # Asset pages
    'asset/edit.html.twig': [{
        'pattern': r'<h3>Asset bearbeiten</h3>',
        'replacement': r'<h1>Asset bearbeiten</h1>',
        'description': 'Main page title'
    }],

    # Security pages
    'security/index.html.twig': [{
        'pattern': r'<h3>Sicherheitsübersicht</h3>',
        'replacement': r'<h1>Sicherheitsübersicht</h1>',
        'count': 1
    }],

    'security/report.html.twig': [{
        'pattern': r'<h3>',
        'replacement': r'<h1>',
        'count': 1
    }, {
        'pattern': r'</h3>',
        'replacement': r'</h1>',
        'count': 1
    }],

    # Training pages
    'training/show.html.twig': [{
        'pattern': r'<h5 class="mb-0">',
        'replacement': r'<h1 class="h5 mb-0">',
        'count': 1
    }, {
        'pattern': r'</h5>',
        'replacement': r'</h1>',
        'count': 1
    }],

    # Data management
    'data_management/backup.html.twig': [{
        'pattern': r'<h5>',
        'replacement': r'<h1 class="h5">',
        'count': 1
    }, {
        'pattern': r'</h5>',
        'replacement': r'</h1>',
        'count': 1
    }],

    # Context pages
    'context/index_modern.html.twig': [{
        'pattern': r'<h2>',
        'replacement': r'<h1 class="h2">',
        'count': 1,
        'description': 'First h2 to h1'
    }, {
        'pattern': r'</h2>',
        'replacement': r'</h1>',
        'count': 1
    }],

    # Business process
    'business_process/update.turbo_stream.html.twig': [{
        'pattern': r'<h3>',
        'replacement': r'<h2>',  # Turbo stream is partial
        'description': 'Turbo stream - use h2 as it updates page section'
    }, {
        'pattern': r'</h3>',
        'replacement': r'</h2>'
    }],
}

def main():
    print("=== Batch Fixing Remaining Heading Issues ===\n")

    fixed = 0
    errors = 0

    for file_path, fixes in FIXES.items():
        success, result = fix_file(file_path, fixes)

        if success:
            print(f"✓ Fixed: {file_path}")
            if isinstance(result, list):
                for desc in result:
                    print(f"  - {desc}")
            fixed += 1
        else:
            print(f"⚠️  {file_path}: {result}")
            errors += 1

    print(f"\n=== Summary ===")
    print(f"✓ Fixed: {fixed} files")
    print(f"⚠️  Errors/Skipped: {errors} files")
    print(f"\nRun audit again: python3 scripts/audit_heading_hierarchy.py")

if __name__ == '__main__':
    main()
