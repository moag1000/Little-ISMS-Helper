#!/usr/bin/env python3
"""
Detects duplicate parent keys in YAML files that cause overriding
"""

import re
from collections import defaultdict


def find_duplicate_parent_keys(file_path):
    """Find duplicate parent keys at the same indentation level"""
    with open(file_path, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    # Track keys at each indentation level
    indent_stack = []
    current_path = []
    key_occurrences = defaultdict(list)

    for line_num, line in enumerate(lines, 1):
        # Skip empty lines and comments
        stripped = line.lstrip()
        if not stripped or stripped.startswith('#'):
            continue

        # Calculate indentation
        indent = len(line) - len(stripped)

        # Check if this is a key line
        if ':' in stripped and not stripped.startswith('-'):
            key_part = stripped.split(':', 1)[0].strip()
            value_part = stripped.split(':', 1)[1].strip() if ':' in stripped else ''

            # Pop from stack if we dedented
            while indent_stack and indent <= indent_stack[-1][0]:
                indent_stack.pop()
                if current_path:
                    current_path.pop()

            # Record the full path
            full_path = '.'.join(current_path) if current_path else ''
            key_occurrences[(full_path, indent, key_part)].append({
                'line': line_num,
                'value': value_part,
                'has_children': (value_part == '' or value_part is None)
            })

            # If this is a parent key, add to stack
            if not value_part or value_part == '':
                current_path.append(key_part)
                indent_stack.append((indent, key_part))

    # Find duplicates
    duplicates = []
    for (path, indent, key), occurrences in key_occurrences.items():
        if len(occurrences) > 1:
            # Check if any have children (parent keys)
            has_parent = any(occ['has_children'] for occ in occurrences)
            if has_parent:
                duplicates.append({
                    'path': path,
                    'key': key,
                    'full_key': f"{path}.{key}" if path else key,
                    'occurrences': occurrences
                })

    return duplicates


def main():
    print("=" * 80)
    print("YAML DUPLICATE PARENT KEY DETECTOR")
    print("=" * 80)
    print()

    en_file = '/home/user/Little-ISMS-Helper/translations/messages.en.yaml'
    de_file = '/home/user/Little-ISMS-Helper/translations/messages.de.yaml'

    print("Checking English file...")
    en_duplicates = find_duplicate_parent_keys(en_file)

    print("Checking German file...")
    de_duplicates = find_duplicate_parent_keys(de_file)

    print()
    print("=" * 80)
    print("RESULTS")
    print("=" * 80)
    print()

    if en_duplicates:
        print(f"⚠️  Found {len(en_duplicates)} duplicate parent keys in ENGLISH file:")
        print()
        for dup in en_duplicates:
            print(f"  Duplicate Parent Key: {dup['full_key']}")
            print(f"  Occurs {len(dup['occurrences'])} times:")
            for i, occ in enumerate(dup['occurrences'], 1):
                print(f"    [{i}] Line {occ['line']:4d}")
            print(f"  ⚠️  WARNING: In YAML, later occurrences override earlier ones!")
            print(f"             Only the LAST occurrence (line {dup['occurrences'][-1]['line']}) is effective.")
            print()
    else:
        print("✓ No duplicate parent keys in English file.")
        print()

    if de_duplicates:
        print(f"⚠️  Found {len(de_duplicates)} duplicate parent keys in GERMAN file:")
        print()
        for dup in de_duplicates:
            print(f"  Duplicate Parent Key: {dup['full_key']}")
            print(f"  Occurs {len(dup['occurrences'])} times:")
            for i, occ in enumerate(dup['occurrences'], 1):
                print(f"    [{i}] Line {occ['line']:4d}")
            print(f"  ⚠️  WARNING: In YAML, later occurrences override earlier ones!")
            print(f"             Only the LAST occurrence (line {dup['occurrences'][-1]['line']}) is effective.")
            print()
    else:
        print("✓ No duplicate parent keys in German file.")
        print()

    print("=" * 80)
    print("IMPACT")
    print("=" * 80)
    if en_duplicates or de_duplicates:
        print("⚠️  Duplicate parent keys cause DATA LOSS!")
        print("   - Child keys from earlier occurrences are IGNORED")
        print("   - Only the last occurrence of each parent key is used")
        print("   - This explains why some keys appear 'missing' in the YAML parser")
        print()
        print("RECOMMENDATION: Merge all duplicate sections into ONE section")
    else:
        print("✓ No issues found!")
    print()


if __name__ == '__main__':
    main()
