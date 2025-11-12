#!/usr/bin/env python3
"""
Translation Consistency Checker
Performs comprehensive analysis of DE and EN translation files
"""

import yaml
from pathlib import Path
from collections import defaultdict
import sys


class TranslationChecker:
    def __init__(self, de_file, en_file):
        self.de_file = Path(de_file)
        self.en_file = Path(en_file)
        self.de_keys = {}
        self.en_keys = {}
        self.de_duplicates = defaultdict(list)
        self.en_duplicates = defaultdict(list)

    def extract_keys(self, data, prefix='', keys_dict=None, line_tracker=None):
        """Recursively extract all keys from nested dictionary"""
        if keys_dict is None:
            keys_dict = {}

        if isinstance(data, dict):
            for key, value in data.items():
                current_key = f"{prefix}.{key}" if prefix else key

                if isinstance(value, dict):
                    # This is a nested structure
                    self.extract_keys(value, current_key, keys_dict, line_tracker)
                else:
                    # This is a leaf node with a value
                    keys_dict[current_key] = value

        return keys_dict

    def parse_file_with_line_numbers(self, file_path):
        """Parse YAML file and track line numbers for duplicate detection"""
        keys_with_lines = {}
        key_occurrences = defaultdict(list)

        with open(file_path, 'r', encoding='utf-8') as f:
            lines = f.readlines()

        current_path = []
        indent_stack = [0]

        for line_num, line in enumerate(lines, 1):
            # Skip empty lines and comments
            stripped = line.lstrip()
            if not stripped or stripped.startswith('#'):
                continue

            # Calculate indentation
            indent = len(line) - len(stripped)

            # Check if this is a key-value line
            if ':' in stripped:
                key_part = stripped.split(':', 1)[0].strip()
                value_part = stripped.split(':', 1)[1].strip() if ':' in stripped else ''

                # Adjust path based on indentation
                while indent_stack and indent <= indent_stack[-1] and len(current_path) > 0:
                    indent_stack.pop()
                    current_path.pop()

                # Add current key to path
                if value_part and value_part != '':
                    # This is a leaf node
                    full_key = '.'.join(current_path + [key_part]) if current_path else key_part
                    key_occurrences[full_key].append({
                        'line': line_num,
                        'value': value_part
                    })
                else:
                    # This is a parent node
                    current_path.append(key_part)
                    indent_stack.append(indent)

        # Find duplicates
        duplicates = {}
        for key, occurrences in key_occurrences.items():
            if len(occurrences) > 1:
                duplicates[key] = occurrences

        return key_occurrences, duplicates

    def analyze(self):
        """Main analysis function"""
        print("=" * 80)
        print("TRANSLATION CONSISTENCY CHECK REPORT")
        print("=" * 80)
        print()

        # Load YAML files
        print("Loading translation files...")
        with open(self.de_file, 'r', encoding='utf-8') as f:
            de_data = yaml.safe_load(f)

        with open(self.en_file, 'r', encoding='utf-8') as f:
            en_data = yaml.safe_load(f)

        # Extract all keys
        self.de_keys = self.extract_keys(de_data)
        self.en_keys = self.extract_keys(en_data)

        # Parse files with line numbers for duplicate detection
        de_key_occurrences, de_duplicates = self.parse_file_with_line_numbers(self.de_file)
        en_key_occurrences, en_duplicates = self.parse_file_with_line_numbers(self.en_file)

        # Summary Statistics
        print("=" * 80)
        print("1. SUMMARY STATISTICS")
        print("=" * 80)
        print(f"Total keys in DE:              {len(self.de_keys)}")
        print(f"Total keys in EN:              {len(self.en_keys)}")
        print(f"Keys only in DE:               {len(set(self.de_keys.keys()) - set(self.en_keys.keys()))}")
        print(f"Keys only in EN:               {len(set(self.en_keys.keys()) - set(self.de_keys.keys()))}")
        print(f"Duplicate keys in DE:          {len(de_duplicates)}")
        print(f"Duplicate keys in EN:          {len(en_duplicates)}")
        print(f"Common keys:                   {len(set(self.de_keys.keys()) & set(self.en_keys.keys()))}")
        print()

        # Keys only in DE
        de_only = set(self.de_keys.keys()) - set(self.en_keys.keys())
        if de_only:
            print("=" * 80)
            print("2. KEYS ONLY IN GERMAN (DE) - MISSING IN ENGLISH")
            print("=" * 80)
            print(f"Found {len(de_only)} keys that exist in DE but not in EN:")
            print()
            for key in sorted(de_only):
                value = self.de_keys[key]
                if len(str(value)) > 80:
                    value = str(value)[:77] + "..."
                print(f"  Key:   {key}")
                print(f"  DE:    {value}")
                print(f"  → RECOMMENDATION: Add this key to messages.en.yaml")
                print()
        else:
            print("=" * 80)
            print("2. KEYS ONLY IN GERMAN (DE)")
            print("=" * 80)
            print("✓ No keys found only in DE. All DE keys exist in EN.")
            print()

        # Keys only in EN
        en_only = set(self.en_keys.keys()) - set(self.de_keys.keys())
        if en_only:
            print("=" * 80)
            print("3. KEYS ONLY IN ENGLISH (EN) - MISSING IN GERMAN")
            print("=" * 80)
            print(f"Found {len(en_only)} keys that exist in EN but not in DE:")
            print()
            for key in sorted(en_only):
                value = self.en_keys[key]
                if len(str(value)) > 80:
                    value = str(value)[:77] + "..."
                print(f"  Key:   {key}")
                print(f"  EN:    {value}")
                print(f"  → RECOMMENDATION: Add this key to messages.de.yaml")
                print()
        else:
            print("=" * 80)
            print("3. KEYS ONLY IN ENGLISH (EN)")
            print("=" * 80)
            print("✓ No keys found only in EN. All EN keys exist in DE.")
            print()

        # Duplicates in DE
        if de_duplicates:
            print("=" * 80)
            print("4. DUPLICATE KEYS IN GERMAN (DE)")
            print("=" * 80)
            print(f"Found {len(de_duplicates)} duplicate keys in DE:")
            print()
            for key, occurrences in sorted(de_duplicates.items()):
                print(f"  Duplicate Key: {key}")
                print(f"  Occurrences:   {len(occurrences)}")
                for i, occ in enumerate(occurrences, 1):
                    value = occ['value']
                    if len(value) > 70:
                        value = value[:67] + "..."
                    print(f"    [{i}] Line {occ['line']:4d}: {value}")
                print(f"  → RECOMMENDATION: Keep only one occurrence, remove the others")
                print()
        else:
            print("=" * 80)
            print("4. DUPLICATE KEYS IN GERMAN (DE)")
            print("=" * 80)
            print("✓ No duplicate keys found in DE.")
            print()

        # Duplicates in EN
        if en_duplicates:
            print("=" * 80)
            print("5. DUPLICATE KEYS IN ENGLISH (EN)")
            print("=" * 80)
            print(f"Found {len(en_duplicates)} duplicate keys in EN:")
            print()
            for key, occurrences in sorted(en_duplicates.items()):
                print(f"  Duplicate Key: {key}")
                print(f"  Occurrences:   {len(occurrences)}")
                for i, occ in enumerate(occurrences, 1):
                    value = occ['value']
                    if len(value) > 70:
                        value = value[:67] + "..."
                    print(f"    [{i}] Line {occ['line']:4d}: {value}")
                print(f"  → RECOMMENDATION: Keep only one occurrence, remove the others")
                print()
        else:
            print("=" * 80)
            print("5. DUPLICATE KEYS IN ENGLISH (EN)")
            print("=" * 80)
            print("✓ No duplicate keys found in EN.")
            print()

        # Structural Analysis
        print("=" * 80)
        print("6. STRUCTURAL CONSISTENCY")
        print("=" * 80)

        # Check parent keys
        de_parents = set()
        en_parents = set()

        for key in self.de_keys.keys():
            parts = key.split('.')
            for i in range(1, len(parts)):
                de_parents.add('.'.join(parts[:i]))

        for key in self.en_keys.keys():
            parts = key.split('.')
            for i in range(1, len(parts)):
                en_parents.add('.'.join(parts[:i]))

        de_parent_only = de_parents - en_parents
        en_parent_only = en_parents - de_parents

        if de_parent_only or en_parent_only:
            if de_parent_only:
                print("Parent sections only in DE:")
                for parent in sorted(de_parent_only):
                    print(f"  - {parent}")
                print()

            if en_parent_only:
                print("Parent sections only in EN:")
                for parent in sorted(en_parent_only):
                    print(f"  - {parent}")
                print()
        else:
            print("✓ Both files have the same hierarchical structure.")
            print()

        # Final Recommendations
        print("=" * 80)
        print("7. RECOMMENDATIONS SUMMARY")
        print("=" * 80)

        issues_found = False

        if de_only:
            print(f"→ Add {len(de_only)} missing keys to messages.en.yaml")
            issues_found = True

        if en_only:
            print(f"→ Add {len(en_only)} missing keys to messages.de.yaml")
            issues_found = True

        if de_duplicates:
            print(f"→ Remove {len(de_duplicates)} duplicate keys from messages.de.yaml")
            issues_found = True

        if en_duplicates:
            print(f"→ Remove {len(en_duplicates)} duplicate keys from messages.en.yaml")
            issues_found = True

        if not issues_found:
            print("✓ No issues found! Both translation files are consistent.")

        print()
        print("=" * 80)
        print("END OF REPORT")
        print("=" * 80)


if __name__ == '__main__':
    de_file = '/home/user/Little-ISMS-Helper/translations/messages.de.yaml'
    en_file = '/home/user/Little-ISMS-Helper/translations/messages.en.yaml'

    checker = TranslationChecker(de_file, en_file)
    checker.analyze()
