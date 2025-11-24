#!/usr/bin/env python3
"""
Standardize CSS breakpoints to Bootstrap 5.3 standards
Issue 12.1 from UI/UX Audit - Consistent breakpoint usage

WARNING: This script modifies CSS files. Review changes carefully before committing.
"""
import re
from pathlib import Path

# Bootstrap 5.3 standard breakpoints
BREAKPOINT_REPLACEMENTS = {
    # Max-width replacements (mobile-first)
    r'max-width:\s*768px': 'max-width: 767.98px',  # Medium devices
    r'max-width:\s*576px': 'max-width: 575.98px',  # Small devices
    r'max-width:\s*992px': 'max-width: 991.98px',  # Large devices
    r'max-width:\s*1200px': 'max-width: 1199.98px',  # XL devices
    r'max-width:\s*1400px': 'max-width: 1399.98px',  # XXL devices

    # Min-width edge case fixes
    r'min-width:\s*769px': 'min-width: 768px',  # Should be 768px
    r'min-width:\s*1024px': 'min-width: 992px',  # Should be 992px (or 1200px)
}

def standardize_breakpoints(file_path):
    """Standardize breakpoints in CSS file"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    modifications = 0

    for pattern, replacement in BREAKPOINT_REPLACEMENTS.items():
        matches = re.findall(pattern, content)
        if matches:
            content = re.sub(pattern, replacement, content)
            modifications += len(matches)

    if content != original_content:
        # Create backup
        backup_path = file_path.with_suffix('.css.bak')
        with open(backup_path, 'w', encoding='utf-8') as f:
            f.write(original_content)

        # Write standardized version
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(content)

        return modifications

    return 0

def audit_breakpoints(file_path):
    """Audit breakpoints without modifying"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    issues = []

    # Find all media queries
    media_queries = re.findall(r'@media\s*\([^)]+\)', content)

    for mq in media_queries:
        # Check for non-standard breakpoints
        if 'max-width: 768px' in mq:
            issues.append(('max-width: 768px', 'Should be 767.98px'))
        if 'max-width: 576px' in mq:
            issues.append(('max-width: 576px', 'Should be 575.98px'))
        if 'min-width: 769px' in mq:
            issues.append(('min-width: 769px', 'Should be 768px'))
        if 'min-width: 1024px' in mq:
            issues.append(('min-width: 1024px', 'Should be 992px or 1200px'))

    return issues

def main():
    import sys

    styles_dir = Path('assets/styles')

    if '--audit' in sys.argv:
        # Audit mode: report issues without fixing
        print("=== Breakpoint Audit ===\n")

        total_issues = 0
        for css_file in styles_dir.glob('*.css'):
            issues = audit_breakpoints(css_file)
            if issues:
                print(f"\n{css_file.name}:")
                for issue, suggestion in issues:
                    print(f"  ⚠️  {issue} → {suggestion}")
                    total_issues += 1

        print(f"\n\nTotal non-standard breakpoints found: {total_issues}")
        print("\nRun without --audit flag to fix automatically (creates .bak files)")

    else:
        # Fix mode: apply standardization
        print("=== Standardizing Breakpoints ===\n")
        print("⚠️  This will modify CSS files. Backups will be created (.css.bak)\n")

        response = input("Continue? (y/n): ")
        if response.lower() != 'y':
            print("Aborted.")
            return

        total_modifications = 0
        files_modified = 0

        for css_file in styles_dir.glob('*.css'):
            modifications = standardize_breakpoints(css_file)
            if modifications > 0:
                print(f"✓ {css_file.name}: {modifications} breakpoints standardized")
                total_modifications += modifications
                files_modified += 1

        print(f"\n\nTotal: {total_modifications} breakpoints standardized in {files_modified} files")
        print("\nStandards applied:")
        print("- max-width: 768px → 767.98px")
        print("- max-width: 576px → 575.98px")
        print("- min-width: 769px → 768px")
        print("\nBackup files created with .bak extension")
        print("Review changes and delete .bak files when satisfied")

if __name__ == '__main__':
    main()
