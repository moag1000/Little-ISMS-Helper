#!/usr/bin/env python3
"""
Standardize card styling classes
Issue 3.1 from UI/UX Audit - Make card styles consistent
"""
import re
from pathlib import Path

def standardize_cards(file_path):
    """Standardize card class usage"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    modifications = 0

    # Fix 1: Remove 'shadow' class (Bootstrap cards have subtle shadow by default)
    # Keep shadow-sm, but remove standalone 'shadow'
    pattern1 = r'class="card shadow(["\s])'
    if re.search(pattern1, content):
        content = re.sub(pattern1, r'class="card\1', content)
        modifications += content.count('class="card ') - original_content.count('class="card ')

    # Fix 2: Standardize border-left- to use correct Bootstrap 5 class
    # border-left-danger → should be custom class or use border-start
    border_left_pattern = r'border-left-(primary|secondary|success|danger|warning|info)'
    if re.search(border_left_pattern, content):
        # Replace with our custom card border class
        content = re.sub(border_left_pattern, r'card-border-left-\1', content)
        modifications += 1

    # Fix 3: Remove inline styles from cards (except specific cases like max-width on login)
    # Pattern: <div class="card[^"]*" style="[^"]*">
    inline_style_pattern = r'(<div class="[^"]*card[^"]*")\s+style="([^"]*)"'

    def check_inline_style(match):
        nonlocal modifications
        full_tag = match.group(1)
        style_content = match.group(2)

        # Keep max-width on login/auth cards
        if 'max-width' in style_content and 'login' in str(file_path):
            return match.group(0)

        # Remove other inline styles
        modifications += 1
        return full_tag

    content = re.sub(inline_style_pattern, check_inline_style, content)

    # Fix 4: Ensure cards have consistent spacing (mb-3 or mb-4)
    # Add mb-3 to cards that don't have margin classes
    card_no_margin = r'<div class="card"(?!\s*[^>]*mb-)'
    if re.search(card_no_margin, content):
        # This is complex, skip for now - too many edge cases
        pass

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
        # Skip PDF and component templates
        if 'pdf' in str(twig_file).lower() or '_components' in str(twig_file):
            continue

        modifications = standardize_cards(twig_file)
        if modifications > 0:
            print(f"Standardized {twig_file.relative_to(templates_dir)}: {modifications} changes")
            total_modifications += modifications
            files_modified += 1

    print(f"\nTotal: {total_modifications} card styling fixes in {files_modified} files")
    print("\nStandards applied:")
    print("- Removed redundant 'shadow' class (Bootstrap default has shadow)")
    print("- Standardized border-left-* to card-border-left-*")
    print("- Removed inline styles (except login card max-width)")

if __name__ == '__main__':
    main()
