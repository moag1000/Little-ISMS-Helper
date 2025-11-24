#!/usr/bin/env python3
"""
Audit custom utility classes and identify Bootstrap duplicates
Issue 7.2 from UI/UX Audit
"""
import re
from pathlib import Path
from collections import defaultdict

# Bootstrap 5 utility classes we should use instead of custom
BOOTSTRAP_UTILITIES = {
    # Spacing
    'mt-': 'Bootstrap margin-top utilities (mt-0 to mt-5)',
    'mb-': 'Bootstrap margin-bottom utilities (mb-0 to mb-5)',
    'ms-': 'Bootstrap margin-start utilities (ms-0 to ms-5)',
    'me-': 'Bootstrap margin-end utilities (me-0 to me-5)',
    'mx-': 'Bootstrap margin horizontal utilities',
    'my-': 'Bootstrap margin vertical utilities',
    'pt-': 'Bootstrap padding-top utilities',
    'pb-': 'Bootstrap padding-bottom utilities',
    'ps-': 'Bootstrap padding-start utilities',
    'pe-': 'Bootstrap padding-end utilities',
    'px-': 'Bootstrap padding horizontal utilities',
    'py-': 'Bootstrap padding vertical utilities',
    'p-': 'Bootstrap padding utilities (p-0 to p-5)',
    'm-': 'Bootstrap margin utilities (m-0 to m-5)',

    # Text
    '.text-': 'Bootstrap text utilities (text-start, text-center, text-end)',
    '.fw-': 'Bootstrap font-weight utilities',
    '.fs-': 'Bootstrap font-size utilities (fs-1 to fs-6)',
    '.small': 'Bootstrap small text',
    '.lead': 'Bootstrap lead text',

    # Display
    '.d-': 'Bootstrap display utilities',
    '.flex-': 'Bootstrap flex utilities',
    '.justify-content-': 'Bootstrap justify-content',
    '.align-items-': 'Bootstrap align-items',
    '.gap-': 'Bootstrap gap utilities',

    # Width/Height
    '.w-': 'Bootstrap width utilities',
    '.h-': 'Bootstrap height utilities',

    # Colors
    '.text-primary': 'Bootstrap text color',
    '.text-secondary': 'Bootstrap text color',
    '.text-success': 'Bootstrap text color',
    '.text-danger': 'Bootstrap text color',
    '.text-warning': 'Bootstrap text color',
    '.text-info': 'Bootstrap text color',
    '.text-muted': 'Bootstrap text color',
    '.bg-': 'Bootstrap background utilities',
}

def extract_utility_classes(css_file):
    """Extract custom utility class definitions from CSS"""
    with open(css_file, 'r', encoding='utf-8') as f:
        content = f.read()

    # Find utility-style classes (single-purpose, short names)
    # Pattern: .classname { ... }
    pattern = r'\.([a-z-]+)\s*\{([^}]+)\}'
    matches = re.findall(pattern, content, re.MULTILINE)

    utilities = {}
    for class_name, rules in matches:
        # Filter for utility-style classes
        if (class_name.startswith(('mt-', 'mb-', 'ms-', 'me-', 'pt-', 'pb-', 'p-', 'm-')) or
            class_name in ['text-small', 'text-large', 'text-xs', 'text-sm', 'text-lg', 'text-xl'] or
            re.match(r'^[mwh]-\d+$', class_name)):  # m-10, w-100, etc.
            utilities[class_name] = rules.strip()

    return utilities

def check_bootstrap_duplicate(class_name, rules):
    """Check if this class duplicates Bootstrap functionality"""
    # Margin/padding classes
    if re.match(r'^[mp][tblrxy]?-\d+$', class_name):
        return True, "Bootstrap has built-in spacing utilities"

    # Text size classes
    if class_name in ['text-small', 'text-xs', 'text-sm']:
        return True, "Use .small or .fs-6"
    if class_name in ['text-large', 'text-lg', 'text-xl']:
        return True, "Use .lead or .fs-1 to .fs-5"

    # Width/Height with px values
    if re.match(r'^[wh]-\d+$', class_name) and 'px' in rules:
        return True, "Bootstrap has w-25, w-50, w-75, w-100 utilities"

    return False, None

def main():
    # Scan CSS files
    css_dir = Path('assets/styles')
    all_utilities = {}

    for css_file in css_dir.glob('*.css'):
        if css_file.name == 'dark-mode.css':
            continue

        utilities = extract_utility_classes(css_file)
        if utilities:
            all_utilities[css_file.name] = utilities

    # Report
    print("=" * 80)
    print("UTILITY CLASS AUDIT REPORT")
    print("=" * 80)
    print()

    duplicates = []
    custom_needed = []

    for css_file, utilities in all_utilities.items():
        if not utilities:
            continue

        print(f"\n📄 {css_file}")
        print("-" * 80)

        for class_name, rules in utilities.items():
            is_dup, suggestion = check_bootstrap_duplicate(class_name, rules)

            if is_dup:
                duplicates.append((css_file, class_name, rules, suggestion))
                print(f"  ⚠️  .{class_name}")
                print(f"      Rules: {rules[:60]}...")
                print(f"      → {suggestion}")
            else:
                custom_needed.append((css_file, class_name, rules))

    # Summary
    print("\n" + "=" * 80)
    print("SUMMARY")
    print("=" * 80)
    print(f"\n❌ Potential Bootstrap Duplicates: {len(duplicates)}")
    print(f"✅ Custom Utilities (may be needed): {len(custom_needed)}")

    if duplicates:
        print("\n🔧 RECOMMENDED ACTIONS:")
        print("\n1. Replace these custom classes with Bootstrap equivalents:")
        seen = set()
        for css_file, class_name, rules, suggestion in duplicates:
            key = (class_name, suggestion)
            if key not in seen:
                print(f"   .{class_name} → {suggestion}")
                seen.add(key)

        print("\n2. Search and replace in templates:")
        print("   Example: class=\"text-small\" → class=\"small\"")
        print("   Example: class=\"mt-10\" → class=\"mt-3\"")

    if custom_needed:
        print("\n📝 Custom utilities to review (may be legitimate):")
        for css_file, class_name, rules in custom_needed[:10]:  # Show first 10
            print(f"   .{class_name} in {css_file}")

    print("\n" + "=" * 80)

if __name__ == '__main__':
    main()
