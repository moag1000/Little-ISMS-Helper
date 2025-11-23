#!/usr/bin/env python3
"""
Fix hardcoded colors for dark mode compatibility
"""
import re
from pathlib import Path

# Color mapping for dark mode compatibility
COLOR_REPLACEMENTS = {
    # Gray backgrounds
    r'background:\s*#f8f9fa': 'background: var(--bg-secondary)',
    r'background:\s*#f0f0f0': 'background: var(--bg-secondary)',
    r'background:\s*#f3f4f6': 'background: var(--bg-secondary)',
    r'background:\s*#f5f5f5': 'background: var(--bg-secondary)',
    r'background:\s*#e5e7eb': 'background: var(--bg-tertiary)',

    # Border colors
    r'border:\s*1px solid #ddd': 'border: 1px solid var(--border-color)',
    r'border:\s*1px solid #dee2e6': 'border: 1px solid var(--border-color)',
    r'border-color:\s*#ddd': 'border-color: var(--border-color)',

    # Success colors (keep specific, but use variables)
    r'background:\s*#28a745': 'background: var(--color-success, #28a745)',
    r'background:\s*#10b981': 'background: var(--color-success, #10b981)',

    # Warning colors
    r'background:\s*#ffc107': 'background: var(--color-warning, #ffc107)',
    r'background:\s*#f59e0b': 'background: var(--color-warning, #f59e0b)',

    # Danger colors
    r'background:\s*#dc3545': 'background: var(--color-danger, #dc3545)',
    r'background:\s*#ef4444': 'background: var(--color-danger, #ef4444)',

    # Info colors
    r'background:\s*#17a2b8': 'background: var(--color-info, #17a2b8)',
    r'background:\s*#06b6d4': 'background: var(--color-info, #06b6d4)',

    # Secondary/gray colors
    r'background:\s*#6c757d': 'background: var(--color-secondary, #6c757d)',
    r'background:\s*#64748b': 'background: var(--color-secondary, #64748b)',

    # Purple colors
    r'background:\s*#6f42c1': 'background: var(--color-purple, #6f42c1)',
    r'background:\s*#8b5cf6': 'background: var(--color-purple, #8b5cf6)',

    # Blue colors
    r'background:\s*#60a5fa': 'background: var(--color-primary, #60a5fa)',
}

def fix_colors_in_file(file_path):
    """Replace hardcoded colors with CSS variables"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    replacements_made = 0

    for pattern, replacement in COLOR_REPLACEMENTS.items():
        matches = len(re.findall(pattern, content))
        if matches > 0:
            content = re.sub(pattern, replacement, content)
            replacements_made += matches

    if content != original_content:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(content)
        return replacements_made

    return 0

def main():
    assets_dir = Path('assets/styles')
    total_replacements = 0
    files_modified = 0

    for css_file in assets_dir.glob('*.css'):
        if css_file.name == 'dark-mode.css':
            continue  # Skip dark mode file

        replacements = fix_colors_in_file(css_file)
        if replacements > 0:
            print(f"Fixed {css_file.name}: {replacements} replacements")
            total_replacements += replacements
            files_modified += 1

    print(f"\nTotal: {total_replacements} color replacements in {files_modified} files")

if __name__ == '__main__':
    main()
