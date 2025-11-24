#!/usr/bin/env python3
"""
Audit heading hierarchy (h1 → h2 → h3, no skips)
Issue 8.1 from UI/UX Audit
"""
import re
from pathlib import Path

def extract_headings(content):
    """Extract all heading tags with their levels"""
    pattern = r'<(h[1-6])[^>]*>.*?</\1>'
    matches = re.findall(pattern, content, re.DOTALL | re.IGNORECASE)
    return [int(h[1]) for h in matches]  # Extract level numbers

def check_hierarchy(headings):
    """Check if heading hierarchy is correct"""
    issues = []

    if not headings:
        return issues

    # Check if starts with h1
    if headings[0] != 1:
        issues.append(f"First heading is h{headings[0]}, should be h1")

    # Check for skipped levels
    for i in range(1, len(headings)):
        prev_level = headings[i-1]
        curr_level = headings[i]

        # Skip if same or going back (that's OK)
        if curr_level <= prev_level:
            continue

        # Check if we skipped a level
        if curr_level > prev_level + 1:
            issues.append(f"Skipped from h{prev_level} to h{curr_level} (missing h{prev_level + 1})")

    return issues

def main():
    templates_dir = Path('templates')
    problem_files = []
    total_issues = 0

    print("=" * 80)
    print("HEADING HIERARCHY AUDIT")
    print("=" * 80)
    print()

    # Process all Twig templates
    for twig_file in templates_dir.rglob('*.twig'):
        # Skip PDF and component templates
        if 'pdf' in str(twig_file).lower() or '_components' in str(twig_file):
            continue

        with open(twig_file, 'r', encoding='utf-8') as f:
            content = f.read()

        headings = extract_headings(content)

        if not headings:
            continue

        issues = check_hierarchy(headings)

        if issues:
            problem_files.append((twig_file, headings, issues))
            total_issues += len(issues)

    # Report
    if problem_files:
        print(f"❌ Found {total_issues} hierarchy issues in {len(problem_files)} files:\n")

        # Group by issue type
        skip_issues = []
        start_issues = []

        for file_path, headings, issues in problem_files:
            rel_path = file_path.relative_to(templates_dir)

            for issue in issues:
                if "First heading" in issue:
                    start_issues.append((rel_path, issue, headings))
                else:
                    skip_issues.append((rel_path, issue, headings))

        # Report start issues
        if start_issues:
            print("🔴 PAGES NOT STARTING WITH H1:")
            print("-" * 80)
            for path, issue, headings in start_issues[:15]:  # Show first 15
                print(f"  {path}")
                print(f"    Issue: {issue}")
                print(f"    Headings: {headings}")
                print()

        # Report skip issues
        if skip_issues:
            print("\n🟡 SKIPPED HEADING LEVELS:")
            print("-" * 80)
            for path, issue, headings in skip_issues[:15]:  # Show first 15
                print(f"  {path}")
                print(f"    Issue: {issue}")
                print(f"    Headings: {headings}")
                print()

    else:
        print("✅ No heading hierarchy issues found!")

    print("\n" + "=" * 80)
    print("SUMMARY")
    print("=" * 80)
    print(f"Total issues: {total_issues}")
    print(f"Files affected: {len(problem_files)}")

    if total_issues > 0:
        print("\n📝 RECOMMENDED ACTIONS:")
        print("1. Ensure each page starts with exactly one h1")
        print("2. Don't skip heading levels (h1 → h2 → h3, not h1 → h3)")
        print("3. Card titles should be h5 (already standardized)")
        print("4. Modal titles can be h5 or match context")

    print("=" * 80)

if __name__ == '__main__':
    main()
