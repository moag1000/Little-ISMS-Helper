#!/usr/bin/env python3
"""
Add sticky headers to long tables (>20 rows)
Issue 4.2 from UI/UX Audit
"""
import re
from pathlib import Path

def count_table_rows(content, table_start_pos):
    """Count approximate rows in a table"""
    # Find the table end
    table_end = content.find('</table>', table_start_pos)
    if table_end == -1:
        return 0

    table_content = content[table_start_pos:table_end]
    # Count <tr> tags (excluding those in thead)
    tbody_match = re.search(r'<tbody[^>]*>(.*?)</tbody>', table_content, re.DOTALL)
    if tbody_match:
        tbody = tbody_match.group(1)
        row_count = len(re.findall(r'<tr[^>]*>', tbody))
        return row_count

    return 0

def add_sticky_header(file_path):
    """Add stickyHeader: true to tables with many rows"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    modifications = 0

    # Find all table component embeds
    pattern = r"({%\s*embed\s+'_components/_table\.html\.twig'\s*with\s*\{([^}]+)\}\s*%})"

    matches = list(re.finditer(pattern, content, re.DOTALL))

    # Process in reverse to maintain positions
    for match in reversed(matches):
        full_match = match.group(0)
        params = match.group(2)

        # Skip if already has stickyHeader
        if 'stickyHeader' in params:
            continue

        # Find table position after this embed
        table_pos = match.end()
        row_count = count_table_rows(content, table_pos)

        # Only add sticky header if >20 rows
        if row_count > 20:
            # Add stickyHeader parameter
            new_params = params.rstrip() + ",\n    'stickyHeader': true,\n    'theadClass': 'table-light'"
            new_embed = full_match.replace(params, new_params)

            content = content[:match.start()] + new_embed + content[match.end():]
            modifications += 1
            print(f"  Added sticky header to table with {row_count} rows")

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
        # Skip PDF templates
        if 'pdf' in str(twig_file).lower():
            continue

        modifications = add_sticky_header(twig_file)
        if modifications > 0:
            print(f"Modified {twig_file.relative_to(templates_dir)}: {modifications} tables")
            total_modifications += modifications
            files_modified += 1

    print(f"\nTotal: {total_modifications} sticky headers added to {files_modified} files")

if __name__ == '__main__':
    main()
