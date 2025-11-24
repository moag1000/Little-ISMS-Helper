#!/usr/bin/env python3
"""
Standardize card headers for consistency
- Use h5 for all card titles (standard Bootstrap 5 pattern)
- Always include mb-0 class to prevent extra spacing
"""
import re
from pathlib import Path

def fix_card_headers(file_path):
    """Standardize card header heading levels and spacing"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    replacements_made = 0

    # Pattern 1: <h4 ... in card-header → <h5 ...
    # Match: <h4 (any attributes) class="...">
    pattern1 = r'(<div class="[^"]*card-header[^"]*">)\s*<h4(\s+[^>]*)class="([^"]*)">'
    def replace_h4_to_h5(match):
        nonlocal replacements_made
        header_div = match.group(1)
        h4_attrs = match.group(2) if match.group(2) else ''
        classes = match.group(3)

        # Add mb-0 if not present
        if 'mb-0' not in classes:
            classes = classes.strip() + ' mb-0' if classes.strip() else 'mb-0'

        replacements_made += 1
        return f'{header_div}<h5{h4_attrs}class="{classes.strip()}">'

    content = re.sub(pattern1, replace_h4_to_h5, content)

    # Pattern 2: <h4 without existing class in card-header → <h5 class="mb-0">
    pattern2 = r'(<div class="[^"]*card-header[^"]*">)\s*<h4(?!\s+class)([^>]*)>'
    def add_class_to_h4(match):
        nonlocal replacements_made
        header_div = match.group(1)
        h4_attrs = match.group(2) if match.group(2) else ''
        replacements_made += 1
        return f'{header_div}<h5{h4_attrs} class="mb-0">'

    content = re.sub(pattern2, add_class_to_h4, content)

    # Pattern 3: <h6 in card-header → <h5
    pattern3 = r'(<div class="[^"]*card-header[^"]*">)\s*<h6(\s+[^>]*)class="([^"]*)">'
    def replace_h6_to_h5(match):
        nonlocal replacements_made
        header_div = match.group(1)
        h6_attrs = match.group(2) if match.group(2) else ''
        classes = match.group(3)

        # Add mb-0 if not present
        if 'mb-0' not in classes:
            classes = classes.strip() + ' mb-0' if classes.strip() else 'mb-0'

        replacements_made += 1
        return f'{header_div}<h5{h6_attrs}class="{classes.strip()}">'

    content = re.sub(pattern3, replace_h6_to_h5, content)

    # Pattern 4: <h5 without mb-0 in card-header → add mb-0
    pattern4 = r'(<div class="[^"]*card-header[^"]*">)\s*<h5([^>]*)class="([^"]*)">'
    def ensure_mb0(match):
        nonlocal replacements_made
        header_div = match.group(1)
        h5_attrs = match.group(2) if match.group(2) else ''
        classes = match.group(3)

        # Add mb-0 if not present
        if 'mb-0' not in classes:
            classes = classes.strip() + ' mb-0' if classes.strip() else 'mb-0'
            replacements_made += 1

        return f'{header_div}<h5{h5_attrs}class="{classes.strip()}">'

    content = re.sub(pattern4, ensure_mb0, content)

    # Pattern 5: Close </h4> and </h6> tags → </h5>
    content = re.sub(r'</h4>', '</h5>', content)
    content = re.sub(r'</h6>', '</h5>', content)

    if content != original_content:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(content)
        return replacements_made

    return 0

def main():
    templates_dir = Path('templates')
    total_replacements = 0
    files_modified = 0

    # Process all Twig templates
    for twig_file in templates_dir.rglob('*.twig'):
        # Skip component templates (they're already correct)
        if twig_file.name == '_card.html.twig':
            continue

        replacements = fix_card_headers(twig_file)
        if replacements > 0:
            print(f"Fixed {twig_file.relative_to(templates_dir)}: {replacements} headers")
            total_replacements += replacements
            files_modified += 1

    print(f"\nTotal: {total_replacements} card header fixes in {files_modified} files")

if __name__ == '__main__':
    main()
