#!/usr/bin/env python3
"""
Standardize button groups in table action columns
Issue 1.2 from UI/UX Audit - Make button groups consistent
"""
import re
from pathlib import Path

def standardize_button_groups(file_path):
    """Standardize button group usage in tables"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    modifications = 0

    # Pattern 1: Multiple buttons in table cells without btn-group wrapper
    # Look for: <td> ... <a class="btn btn-sm"> ... <a class="btn btn-sm"> ... </td>
    # This is complex, so we'll focus on specific patterns

    # Pattern 2: btn-group without btn-group-sm when containing btn-sm buttons
    pattern_group_no_sm = r'<div class="btn-group"([^>]*)>((?:(?!<\/div>).)*?btn-sm.*?)<\/div>'

    def add_sm_to_group(match):
        nonlocal modifications
        attrs = match.group(1)
        inner = match.group(2)

        # Only add if btn-group-sm is not already there
        if 'btn-group-sm' not in attrs:
            modifications += 1
            return f'<div class="btn-group btn-group-sm"{attrs}>{inner}</div>'
        return match.group(0)

    content = re.sub(pattern_group_no_sm, add_sm_to_group, content, flags=re.DOTALL)

    # Pattern 3: action buttons without proper role="group" and aria-label
    pattern_group_no_role = r'<div class="btn-group([^"]*)"(?![^>]*role=)([^>]*)>'

    def add_role_to_group(match):
        nonlocal modifications
        classes = match.group(1)
        other_attrs = match.group(2)

        # Add role and aria-label
        modifications += 1
        return f'<div class="btn-group{classes}" role="group" aria-label="Actions"{other_attrs}>'

    content = re.sub(pattern_group_no_role, add_role_to_group, content)

    if content != original_content:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(content)
        return modifications

    return 0

def main():
    templates_dir = Path('templates')
    total_modifications = 0
    files_modified = 0

    # Process all Twig templates
    for twig_file in templates_dir.rglob('*.twig'):
        # Skip PDF and component templates that are already standardized
        if 'pdf' in str(twig_file).lower():
            continue

        modifications = standardize_button_groups(twig_file)
        if modifications > 0:
            print(f"Standardized {twig_file.relative_to(templates_dir)}: {modifications} changes")
            total_modifications += modifications
            files_modified += 1

    print(f"\nTotal: {total_modifications} button group fixes in {files_modified} files")
    print("\nStandards applied:")
    print("- Added btn-group-sm when containing btn-sm buttons")
    print("- Added role='group' and aria-label for accessibility")

if __name__ == '__main__':
    main()
