#!/usr/bin/env python3
"""
Fix badge syntax for Bootstrap 5 consistency
Converts old badge-{variant} to bg-{variant} format
"""
import re
from pathlib import Path

# Badge variant conversions (Bootstrap 4 → Bootstrap 5)
BADGE_CONVERSIONS = [
    # Direct variant conversions
    (r'class="badge\s+badge-(success|danger|warning|info|primary|secondary|light|dark)"', r'class="badge bg-\1"'),
    (r'class="badge\s+badge-(success|danger|warning|info|primary|secondary|light|dark)\s+', r'class="badge bg-\1 '),

    # Template conditionals with badge variants
    (r"{%\s*if\s+[^%]+%}badge-(success|danger|warning|info|primary|secondary)", r"{% if ... %}bg-\1"),
    (r"{%\s*elif\s+[^%]+%}badge-(success|danger|warning|info|primary|secondary)", r"{% elif ... %}bg-\1"),
    (r"{%\s*else\s*%}badge-(success|danger|warning|info|primary|secondary)", r"{% else %}bg-\1"),
]

# Special cases for computed badge classes in templates
CONDITIONAL_BADGE_PATTERNS = [
    # Pattern: {% if condition %}badge-success{% elseif condition %}badge-warning{% else %}badge-danger{% endif %}
    (
        r'badge\s+{%\s*if\s+([^%]+)%}badge-(\w+){%\s*elif(?:if)?\s+([^%]+)%}badge-(\w+){%\s*else\s*%}badge-(\w+){%\s*endif\s*%}',
        r'badge {% if \1 %}bg-\2{% elif \3 %}bg-\4{% else %}bg-\5{% endif %}'
    ),
    # Pattern: {% if condition %}badge-success{% else %}badge-danger{% endif %}
    (
        r'badge\s+{%\s*if\s+([^%]+)%}badge-(\w+){%\s*else\s*%}badge-(\w+){%\s*endif\s*%}',
        r'badge {% if \1 %}bg-\2{% else %}bg-\3{% endif %}'
    ),
]

def fix_badges_in_file(file_path):
    """Convert badge syntax to Bootstrap 5 format"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    replacements_made = 0

    # Fix simple badge class conversions
    for pattern, replacement in BADGE_CONVERSIONS:
        matches = len(re.findall(pattern, content))
        if matches > 0:
            content = re.sub(pattern, replacement, content)
            replacements_made += matches

    # Fix conditional badge patterns
    for pattern, replacement in CONDITIONAL_BADGE_PATTERNS:
        matches = len(re.findall(pattern, content, re.DOTALL))
        if matches > 0:
            content = re.sub(pattern, replacement, content, flags=re.DOTALL)
            replacements_made += matches

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
        # Skip the badge component itself
        if twig_file.name == '_badge.html.twig':
            continue

        replacements = fix_badges_in_file(twig_file)
        if replacements > 0:
            print(f"Fixed {twig_file.relative_to(templates_dir)}: {replacements} replacements")
            total_replacements += replacements
            files_modified += 1

    print(f"\nTotal: {total_replacements} badge syntax fixes in {files_modified} files")

if __name__ == '__main__':
    main()
