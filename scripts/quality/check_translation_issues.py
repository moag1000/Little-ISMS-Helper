#!/usr/bin/env python3
"""
Translation Issues Checker for Twig Templates

Scans all Twig templates for:
1. Hardcoded text that should be translated
2. Incomplete translations (e.g., in labels, attributes)
3. Incorrect translation usage (missing domain, wrong syntax)

Usage: python3 check_translation_issues.py
"""

import re
from pathlib import Path
from typing import Dict, List, Tuple, Set
from dataclasses import dataclass, field

@dataclass
class TranslationIssue:
    """Represents a translation issue found in a template."""
    file: str
    line_num: int
    line_content: str
    issue_type: str
    description: str
    suggestion: str = ""

class TranslationChecker:
    """Checks Twig templates for translation issues."""

    def __init__(self, templates_dir='templates'):
        self.templates_dir = Path(templates_dir)
        self.issues: List[TranslationIssue] = []

        # Valid translation domains (from actual .de.yaml files)
        self.valid_domains = {
            'messages', 'nav', 'mfa', 'tenant', 'role_management', 'dashboard',
            'bulk_delete', 'session', 'field', 'admin', 'analytics', 'asset',
            'audit_log', 'audit', 'bc_exercises', 'bc_plans', 'bcm', 'business_process',
            'change_requests', 'compliance', 'context', 'control', 'crisis_team',
            'crypto', 'document', 'incident', 'interested_parties', 'locations',
            'management_review', 'monitoring', 'objective', 'patches', 'people',
            'physical_access', 'privacy', 'reports', 'risk_appetite',
            'risk_treatment_plan', 'risk', 'security', 'security_reports',
            'soa', 'suppliers', 'training', 'ui', 'user', 'validators',
            'vulnerabilities', 'workflows', 'threat', 'consent', 'emails', 'assets',
            'setup', 'notifications'
        }

        # Patterns for HTML attributes that should be translated
        self.translatable_attributes = {
            'title', 'alt', 'placeholder', 'aria-label', 'data-original-title',
            'data-confirm', 'data-bs-title', 'aria-description'
        }

        # Common English words that indicate hardcoded text
        self.english_indicators = {
            'the', 'and', 'or', 'for', 'to', 'of', 'in', 'on', 'at', 'by',
            'with', 'from', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'should',
            'could', 'may', 'might', 'must', 'can', 'new', 'edit', 'delete',
            'create', 'update', 'save', 'cancel', 'back', 'next', 'previous',
            'search', 'filter', 'export', 'import', 'view', 'show', 'hide',
            'loading', 'error', 'success', 'warning', 'info', 'submit', 'reset'
        }

    def check_all_templates(self) -> None:
        """Check all Twig templates in the templates directory."""
        print("="*80)
        print("TRANSLATION ISSUES CHECKER")
        print("="*80)
        print()
        print("Scanning templates for:")
        print("  1. Hardcoded text that should be translated")
        print("  2. Incomplete translations (labels, attributes)")
        print("  3. Incorrect translation usage")
        print()
        print("-"*80)

        twig_files = sorted(self.templates_dir.rglob('*.twig'))

        for filepath in twig_files:
            self.check_file(filepath)

        self.print_report()

    def check_file(self, filepath: Path) -> None:
        """Check a single Twig template file."""
        try:
            with open(filepath, 'r', encoding='utf-8') as f:
                content = f.read()
                lines = content.split('\n')
        except Exception as e:
            print(f"⚠️  Error reading {filepath}: {e}")
            return

        rel_path = str(filepath.relative_to(self.templates_dir))

        # Check if file has trans_default_domain set
        default_domain_match = re.search(r"{%\s*trans_default_domain\s+['\"](\w+)['\"]\s*%}", content)
        has_default_domain = default_domain_match is not None

        for line_num, line in enumerate(lines, 1):
            # Skip empty lines and comments
            if not line.strip() or line.strip().startswith('{#'):
                continue

            # Check for various issues
            self.check_hardcoded_text(rel_path, line_num, line)
            self.check_untranslated_attributes(rel_path, line_num, line)
            # Only check for missing trans params if no default domain is set
            if not has_default_domain:
                self.check_incorrect_trans_usage(rel_path, line_num, line)
            self.check_missing_domain(rel_path, line_num, line)

    def check_hardcoded_text(self, file: str, line_num: int, line: str) -> None:
        """Check for hardcoded text in HTML content."""
        # Skip lines that are mostly Twig code
        if line.count('{') > 3 or line.count('%') > 2:
            return

        # Pattern: HTML tags with text content that's not a variable or translation
        # e.g., <h1>Dashboard</h1>, <button>Save</button>, <label>Name</label>
        patterns = [
            # <tag>Text</tag> where Text is not {{ }} or {% %}
            r'<(h[1-6]|p|span|label|button|a|td|th|li|div)([^>]*)>([^<{]+)</\1>',
            # <tag>Text without closing tag on same line
            r'<(h[1-6]|p|span|label|button)([^>]*)>([^<{]+)$',
        ]

        for pattern in patterns:
            matches = re.finditer(pattern, line)
            for match in matches:
                text_content = match.group(3).strip()

                # Skip if empty or just whitespace
                if not text_content or text_content.isspace():
                    continue

                # Skip if it's a number, date, or symbol
                if re.match(r'^[\d\s\-\/\.\,\:\;]+$', text_content):
                    continue

                # Skip if it's already using trans filter or variable
                if '|trans' in line or '{{' in text_content or '{%' in text_content:
                    continue

                # Check if it contains English indicators
                words = text_content.lower().split()
                if any(word in self.english_indicators for word in words):
                    self.issues.append(TranslationIssue(
                        file=file,
                        line_num=line_num,
                        line_content=line.strip(),
                        issue_type="HARDCODED_TEXT",
                        description=f"Hardcoded text: '{text_content}'",
                        suggestion="Use translation: {{ 'key'|trans({}, 'domain') }}"
                    ))

    def check_untranslated_attributes(self, file: str, line_num: int, line: str) -> None:
        """Check for HTML attributes with hardcoded text."""
        for attr in self.translatable_attributes:
            # Pattern: attribute="hardcoded text"
            pattern = rf'{attr}=(["\'])([^"\'{{]+)\1'
            matches = re.finditer(pattern, line)

            for match in matches:
                attr_value = match.group(2).strip()

                # Skip if empty, just symbols, or numbers
                if not attr_value or re.match(r'^[\d\s\-\/\.\,\:\;#]+$', attr_value):
                    continue

                # Skip if it's a URL, path, or CSS class
                if attr_value.startswith(('http', '/', '.', '#', 'bi-', 'btn-')):
                    continue

                # Check if it contains English words
                words = attr_value.lower().split()
                if any(word in self.english_indicators for word in words):
                    self.issues.append(TranslationIssue(
                        file=file,
                        line_num=line_num,
                        line_content=line.strip(),
                        issue_type="UNTRANSLATED_ATTRIBUTE",
                        description=f"Untranslated {attr}: '{attr_value}'",
                        suggestion=f"{attr}=\"{{{{ 'key'|trans({{ }}, 'domain') }}}}\""
                    ))

    def check_incorrect_trans_usage(self, file: str, line_num: int, line: str) -> None:
        """Check for incorrect translation filter usage."""
        # Pattern: |trans without parameters (should have domain)
        # Allow |trans alone only in very specific cases
        pattern = r"'([^']+)'\|trans(?!\()"
        matches = re.finditer(pattern, line)

        for match in matches:
            trans_key = match.group(1)
            self.issues.append(TranslationIssue(
                file=file,
                line_num=line_num,
                line_content=line.strip(),
                issue_type="MISSING_TRANS_PARAMS",
                description=f"Translation without parameters: '{trans_key}|trans'",
                suggestion=f"'{trans_key}'|trans({{ }}, 'domain')"
            ))

    def check_missing_domain(self, file: str, line_num: int, line: str) -> None:
        """Check for translations without explicit domain or with invalid domain."""
        # Pattern: |trans({}, 'domain') or |trans({params}, 'domain')
        # Use a more robust pattern that handles nested braces and quotes
        pattern = r"'([^']+)'\|trans\((\{[^}]*\}(?:\s*,\s*'[^']*')?|\s*'[^']*'|)\)"
        matches = re.finditer(pattern, line)

        for match in matches:
            trans_key = match.group(1)
            params = match.group(2)

            # Domain is the LAST quoted string after a comma in the trans() call
            # e.g., trans({}, 'domain') or trans({'param': value}, 'domain')
            # Split by comma and look for the last argument that's a quoted string
            domain = None

            # Look for pattern: , 'domain') at the end
            domain_at_end = re.search(r",\s*['\"](\w+)['\"]\s*$", params)
            if domain_at_end:
                domain = domain_at_end.group(1)
            elif params.strip() == '{}':
                # Empty params without domain
                domain = None
            else:
                # Check if params is just a domain string like "'messages'"
                simple_domain = re.match(r"^\s*['\"](\w+)['\"]\s*$", params)
                if simple_domain:
                    domain = simple_domain.group(1)
                # Also check for: {}, 'domain' pattern
                domain_after_empty = re.search(r"\{\s*\}\s*,\s*['\"](\w+)['\"]", params)
                if domain_after_empty:
                    domain = domain_after_empty.group(1)

            if not domain:
                # No domain specified
                self.issues.append(TranslationIssue(
                    file=file,
                    line_num=line_num,
                    line_content=line.strip(),
                    issue_type="NO_DOMAIN",
                    description=f"Translation without domain: '{trans_key}'",
                    suggestion="Add explicit domain parameter"
                ))
            elif domain not in self.valid_domains:
                # Invalid domain
                self.issues.append(TranslationIssue(
                    file=file,
                    line_num=line_num,
                    line_content=line.strip(),
                    issue_type="INVALID_DOMAIN",
                    description=f"Invalid domain '{domain}' for key '{trans_key}'",
                    suggestion=f"Use one of: {', '.join(sorted(self.valid_domains))}"
                ))

    def print_report(self) -> None:
        """Print a formatted report of all issues found."""
        print()
        print("="*80)
        print("SCAN RESULTS")
        print("="*80)
        print()

        if not self.issues:
            print("✅ No translation issues found!")
            print()
            return

        # Group issues by type
        issues_by_type: Dict[str, List[TranslationIssue]] = {}
        for issue in self.issues:
            if issue.issue_type not in issues_by_type:
                issues_by_type[issue.issue_type] = []
            issues_by_type[issue.issue_type].append(issue)

        # Print summary
        print(f"Found {len(self.issues)} issue(s) across {len(set(i.file for i in self.issues))} file(s)")
        print()

        for issue_type, issues in sorted(issues_by_type.items()):
            print(f"{'='*80}")
            print(f"{issue_type}: {len(issues)} issue(s)")
            print(f"{'='*80}")
            print()

            # Group by file
            issues_by_file: Dict[str, List[TranslationIssue]] = {}
            for issue in issues:
                if issue.file not in issues_by_file:
                    issues_by_file[issue.file] = []
                issues_by_file[issue.file].append(issue)

            for file, file_issues in sorted(issues_by_file.items()):
                print(f"📄 {file}")
                for issue in file_issues:
                    print(f"   Line {issue.line_num}: {issue.description}")
                    if issue.suggestion:
                        print(f"   💡 Suggestion: {issue.suggestion}")
                    print(f"   Code: {issue.line_content[:100]}...")
                    print()
            print()

        print("="*80)
        print("SUMMARY BY TYPE")
        print("="*80)
        for issue_type, issues in sorted(issues_by_type.items()):
            print(f"  {issue_type:.<40} {len(issues):>4} issue(s)")
        print(f"  {'TOTAL':.<40} {len(self.issues):>4} issue(s)")
        print()

def main():
    checker = TranslationChecker()
    checker.check_all_templates()

    print("="*80)
    print("NEXT STEPS")
    print("="*80)
    print()
    print("1. Review the issues listed above")
    print("2. For each hardcoded text, add a translation key")
    print("3. For missing domains, add explicit domain parameters")
    print("4. For untranslated attributes, use {{ 'key'|trans() }} syntax")
    print()
    print("Example fixes:")
    print("  Before: <h1>Dashboard</h1>")
    print("  After:  <h1>{{ 'dashboard.title'|trans({}, 'dashboard') }}</h1>")
    print()
    print("  Before: title=\"Click here\"")
    print("  After:  title=\"{{ 'common.click_here'|trans({}, 'messages') }}\"")
    print()
    print("="*80)

if __name__ == '__main__':
    main()
