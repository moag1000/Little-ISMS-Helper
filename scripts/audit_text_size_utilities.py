#!/usr/bin/env python3
"""
Audit text size utility classes
Issue 8.2 from UI/UX Audit - Identify custom text size classes that should use Bootstrap
"""
import re
from pathlib import Path
from collections import defaultdict

# Bootstrap 5 standard font size utilities
BOOTSTRAP_FONT_SIZES = {
    '.fs-1': '2.5rem (40px)',
    '.fs-2': '2rem (32px)',
    '.fs-3': '1.75rem (28px)',
    '.fs-4': '1.5rem (24px)',
    '.fs-5': '1.25rem (20px)',
    '.fs-6': '1rem (16px)',
    '.small': '0.875em (smaller than parent)',
    '.text-muted': 'Bootstrap text color utility'
}

def audit_css_file(file_path):
    """Audit custom text size classes in CSS file"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    custom_classes = defaultdict(list)

    # Find all font-size related custom classes
    pattern = r'\.((?:fs-|text-(?:sm|xs|small|large|lg))[a-z0-9\-]*)\s*\{'
    matches = re.findall(pattern, content)

    for match in matches:
        # Get the full class definition
        class_pattern = rf'\.{re.escape(match)}\s*\{{([^}}]+)\}}'
        class_def = re.search(class_pattern, content)
        if class_def:
            properties = class_def.group(1).strip()
            custom_classes[match].append(properties)

    return custom_classes

def find_usage_in_templates(class_name):
    """Find usage of class in templates"""
    templates_dir = Path('templates')
    usage_count = 0

    for twig_file in templates_dir.rglob('*.twig'):
        with open(twig_file, 'r', encoding='utf-8') as f:
            content = f.read()
            if class_name in content:
                usage_count += content.count(class_name)

    return usage_count

def suggest_bootstrap_alternative(class_name, properties):
    """Suggest Bootstrap alternative for custom class"""
    props_lower = properties.lower()

    # Font size mapping
    if 'font-size: 2.5rem' in props_lower or 'font-size: 40px' in props_lower:
        return '.fs-1 (2.5rem)'
    elif 'font-size: 2rem' in props_lower or 'font-size: 32px' in props_lower:
        return '.fs-2 (2rem)'
    elif 'font-size: 1.75rem' in props_lower or 'font-size: 28px' in props_lower:
        return '.fs-3 (1.75rem)'
    elif 'font-size: 1.5rem' in props_lower or 'font-size: 24px' in props_lower:
        return '.fs-4 (1.5rem)'
    elif 'font-size: 1.25rem' in props_lower or 'font-size: 20px' in props_lower:
        return '.fs-5 (1.25rem)'
    elif 'font-size: 1rem' in props_lower or 'font-size: 16px' in props_lower:
        return '.fs-6 (1rem)'
    elif 'font-size: 0.875' in props_lower or 'font-size: 14px' in props_lower:
        return '.small or .fs-6'
    elif 'font-size: 0.75' in props_lower or 'font-size: 12px' in props_lower:
        return 'Custom needed (Bootstrap doesn\'t go this small)'

    return 'Review manually'

def main():
    print("=== Text Size Utility Audit ===\n")

    styles_dir = Path('assets/styles')
    all_custom_classes = {}

    # Audit all CSS files
    for css_file in styles_dir.glob('*.css'):
        custom_classes = audit_css_file(css_file)
        if custom_classes:
            all_custom_classes[css_file.name] = custom_classes

    print(f"Found {sum(len(classes) for classes in all_custom_classes.values())} custom text size classes\n")

    # Categorize classes
    simple_duplicates = []  # Can be replaced with Bootstrap
    complex_classes = []     # Combine multiple properties
    keep_custom = []         # Need to stay custom

    for file_name, classes in all_custom_classes.items():
        for class_name, properties_list in classes.items():
            properties = properties_list[0] if properties_list else ''

            # Count properties in the class
            prop_count = len([p for p in properties.split(';') if p.strip()])

            if prop_count == 1 and 'font-size' in properties:
                # Simple font-size only class
                suggestion = suggest_bootstrap_alternative(class_name, properties)
                simple_duplicates.append((class_name, properties, suggestion))
            elif prop_count > 1:
                # Complex class with multiple properties
                complex_classes.append((class_name, properties))
            else:
                keep_custom.append((class_name, properties))

    print("\n## 1. SIMPLE DUPLICATES (Use Bootstrap Instead)\n")
    print("These classes only set font-size and can be replaced:\n")
    for class_name, properties, suggestion in simple_duplicates[:10]:
        usage = find_usage_in_templates(class_name)
        print(f"  .{class_name}")
        print(f"    Properties: {properties.strip()}")
        print(f"    Suggestion: {suggestion}")
        print(f"    Usage: {usage} occurrences in templates")
        print()

    if len(simple_duplicates) > 10:
        print(f"  ... and {len(simple_duplicates) - 10} more\n")

    print(f"\n## 2. COMPLEX CLASSES (Multiple Properties)\n")
    print("These classes combine font-size with other properties:\n")
    for class_name, properties in complex_classes[:10]:
        print(f"  .{class_name}")
        print(f"    {properties.strip()}")
        print()

    if len(complex_classes) > 10:
        print(f"  ... and {len(complex_classes) - 10} more\n")

    print("\n## Summary:\n")
    print(f"  Simple duplicates (can replace): {len(simple_duplicates)}")
    print(f"  Complex classes (need review): {len(complex_classes)}")
    print(f"  Total custom text classes: {len(simple_duplicates) + len(complex_classes)}")

    print("\n## Bootstrap 5 Standard Font Sizes:\n")
    for bs_class, description in BOOTSTRAP_FONT_SIZES.items():
        print(f"  {bs_class}: {description}")

    print("\n## Recommendation:\n")
    print("  - Replace simple font-size classes with Bootstrap .fs-* utilities")
    print("  - Keep complex classes that combine multiple properties")
    print("  - Consider using .small for smaller text instead of custom classes")
    print("  - Use utility class combinations: .fs-6.text-muted instead of custom classes")

if __name__ == '__main__':
    main()
