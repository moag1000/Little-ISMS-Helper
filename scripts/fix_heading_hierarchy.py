#!/usr/bin/env python3
"""
Fix heading hierarchy issues automatically
Issue 8.1 from UI/UX Audit
"""
import re
from pathlib import Path

def fix_headings(file_path):
    """Fix heading hierarchy in a template"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    fixes = 0

    # Strategy 1: Pages starting with h2 → add h1
    # If first heading is h2 and it's the page title, make it h1
    if re.search(r'<h2[^>]*>.*?</h2>', content, re.DOTALL):
        first_h2_match = re.search(r'(<h2)([^>]*>.*?</h2>)', content, re.DOTALL)
        if first_h2_match:
            pos = first_h2_match.start()
            # Check if this is near the start of the content (likely main title)
            content_before = content[:pos]
            if content_before.count('<h') == 0:  # No headings before this
                # This is the first heading, make it h1
                content = content.replace(first_h2_match.group(0), f'<h1{first_h2_match.group(2).replace("</h2>", "</h1>")}', 1)
                fixes += 1

    # Strategy 2: Pages starting with h3 in card-header → keep as h5
    # h3 at start → check if it's in a dashboard context
    if re.search(r'<h3[^>]*>.*?</h3>', content, re.DOTALL):
        first_h3_match = re.search(r'(<h3)([^>]*>)(.*?)(</h3>)', content, re.DOTALL)
        if first_h3_match:
            pos = first_h3_match.start()
            content_before = content[:pos]

            # If this is the very first heading and it's a dashboard/card title
            if content_before.count('<h') == 0:
                # Check context - if it's in a card or has class indicators
                h3_content = first_h3_match.group(0)
                if 'card' in content_before[-200:].lower() or 'dashboard' in content[:pos+200].lower():
                    # Keep as h3 but add comment for manual review
                    pass  # Dashboard cards can have h3
                else:
                    # Regular page, should be h1
                    content = content.replace(h3_content, h3_content.replace('<h3', '<h1').replace('</h3>', '</h1>'), 1)
                    fixes += 1

    # Strategy 3: h1 → h3 skip (missing h2)
    # Add h2 wrapper or downgrade h3 to h2
    h1_to_h3_pattern = r'(<h1[^>]*>.*?</h1>.*?)(<h3[^>]*>)'
    if re.search(h1_to_h3_pattern, content, re.DOTALL):
        # Replace h3 with h2 after h1
        def replace_h3_to_h2(match):
            nonlocal fixes
            h1_part = match.group(1)
            h3_tag = match.group(2)
            fixes += 1
            return h1_part + h3_tag.replace('<h3', '<h2')

        content = re.sub(h1_to_h3_pattern, replace_h3_to_h2, content, count=1, flags=re.DOTALL)

    # Strategy 4: Card headers that are h5 at page start
    # These are OK if it's a card-based layout, but check for modal/form context
    if re.search(r'^\s*{%\s*extends\s+', content):
        # This extends a base template, likely has structure from parent
        # h5 as first heading is OK for cards
        pass

    if content != original_content:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(content)
        return fixes

    return 0

def main():
    templates_dir = Path('templates')
    total_fixes = 0
    files_fixed = 0

    # Process all Twig templates
    for twig_file in templates_dir.rglob('*.twig'):
        # Skip PDF, components, and turbo_stream templates
        if any(x in str(twig_file) for x in ['pdf', '_components', 'turbo_stream']):
            continue

        fixes = fix_headings(twig_file)
        if fixes > 0:
            print(f"Fixed {twig_file.relative_to(templates_dir)}: {fixes} heading(s)")
            total_fixes += fixes
            files_fixed += 1

    print(f"\nTotal: {total_fixes} heading fixes in {files_fixed} files")
    print("\nNote: Some issues require manual review:")
    print("- Dashboard pages with h3 cards")
    print("- Complex nested structures")
    print("- Pages with dynamic content blocks")

if __name__ == '__main__':
    main()
