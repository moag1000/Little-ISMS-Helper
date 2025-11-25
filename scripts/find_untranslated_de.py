#!/usr/bin/env python3
"""
Find untranslated English entries in German YAML translation files.
"""
import re
import sys
from pathlib import Path

def is_likely_english(value):
    """Check if a value is likely untranslated English."""
    # Skip if it's a technical term, acronym, or already quoted German
    skip_patterns = [
        r'ISO\s',
        r'ISMS',
        r'DSGVO',
        r'PDF',
        r'CSV',
        r'Excel',
        r'API',
        r'UUID',
        r'JSON',
        r'SSO',
        r'MFA',
        r'AD',
        r'EUR',
        r'DSFA',
        r'Art\.',
        r'WARNUNG',
        r'Tipp',
        r'BCM',
        r'TOTP',
        r'QR',
        r'^[A-Z]{2,}$',  # All caps acronyms
        r'^\d+$',  # Just numbers
    ]

    for pattern in skip_patterns:
        if re.search(pattern, value):
            return False

    # Check for common English patterns
    english_patterns = [
        r'^[A-Z][a-z]+\s+[A-Z]',  # "Test User", "Update Status"
        r'\b(by|with|for|from|into|onto|upon|over|under)\s+[A-Z]',  # English prepositions
        r'\b(Set|Get|Update|Create|Delete|Remove|Add|View)\s',  # English verbs
        r'\b(Title|Description|Message|Hint|Text|Label|Info|Desc)\s*$',  # Generic English words
    ]

    for pattern in english_patterns:
        if re.search(pattern, value):
            return True

    return False

def find_untranslated(file_path):
    """Find untranslated entries in a YAML file."""
    untranslated = []

    with open(file_path, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    for line_num, line in enumerate(lines, 1):
        # Match YAML key: value pairs
        match = re.match(r'^(\s*)([\w.]+):\s*["\']?([^"\']+)["\']?\s*$', line)
        if match:
            indent, key, value = match.groups()
            value = value.strip()

            if is_likely_english(value):
                untranslated.append({
                    'line': line_num,
                    'key': key,
                    'value': value,
                    'indent': len(indent)
                })

    return untranslated

def main():
    translations_dir = Path('translations')

    all_untranslated = {}

    for yaml_file in sorted(translations_dir.glob('*.de.yaml')):
        untranslated = find_untranslated(yaml_file)
        if untranslated:
            all_untranslated[yaml_file.name] = untranslated

    # Print summary
    total = sum(len(entries) for entries in all_untranslated.values())
    print(f"Found {total} potentially untranslated entries in {len(all_untranslated)} files\n")

    # Print top files
    sorted_files = sorted(all_untranslated.items(), key=lambda x: len(x[1]), reverse=True)

    print("Top 10 files by untranslated count:")
    for filename, entries in sorted_files[:10]:
        print(f"  {filename}: {len(entries)} entries")

    print("\nDetailed list (first 50 entries):")
    count = 0
    for filename, entries in sorted_files:
        if count >= 50:
            break
        print(f"\n=== {filename} ===")
        for entry in entries[:10]:
            print(f"  Line {entry['line']:4d}: {entry['key']:30s} = {entry['value']}")
            count += 1
            if count >= 50:
                break

if __name__ == '__main__':
    main()
