#!/usr/bin/env python3
"""
Fix missing ARIA labels on btn-close buttons
"""
import os
import re
from pathlib import Path

def fix_close_buttons(file_path):
    """Add aria-label to close buttons"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content

    # Fix alert close buttons
    content = re.sub(
        r'class="btn-close"\s+data-bs-dismiss="alert"(?!.*aria-label)>',
        'class="btn-close" data-bs-dismiss="alert" aria-label="{{ \'action.close\'|trans({}, \'messages\') }}">',
        content
    )

    # Fix modal close buttons
    content = re.sub(
        r'class="btn-close"\s+data-bs-dismiss="modal"(?!.*aria-label)>',
        'class="btn-close" data-bs-dismiss="modal" aria-label="{{ \'action.close\'|trans({}, \'messages\') }}">',
        content
    )

    # Fix white close buttons
    content = re.sub(
        r'class="btn-close btn-close-white"(?!.*aria-label)\s+data-bs-dismiss',
        'class="btn-close btn-close-white" aria-label="{{ \'action.close\'|trans({}, \'messages\') }}" data-bs-dismiss',
        content
    )

    if content != original_content:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(content)
        return True
    return False

def main():
    templates_dir = Path('templates')
    fixed_count = 0

    for twig_file in templates_dir.rglob('*.twig'):
        if fix_close_buttons(twig_file):
            print(f"Fixed: {twig_file}")
            fixed_count += 1

    print(f"\nTotal files fixed: {fixed_count}")

if __name__ == '__main__':
    main()
