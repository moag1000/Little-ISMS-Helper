#!/usr/bin/env python3
"""
Migrate FontAwesome icons to Bootstrap Icons
Issue 6.1 from UI/UX Audit - Standardize on Bootstrap Icons only
"""
import re
from pathlib import Path

# FontAwesome to Bootstrap Icons mapping
FA_TO_BI_MAPPING = {
    # Common icons
    'fa-magic': 'bi-magic',
    'fa-shield-alt': 'bi-shield',
    'fa-shield': 'bi-shield-check',
    'fa-list-check': 'bi-list-check',
    'fa-check': 'bi-check',
    'fa-times': 'bi-x',
    'fa-info-circle': 'bi-info-circle',
    'fa-exclamation-triangle': 'bi-exclamation-triangle',
    'fa-exclamation-circle': 'bi-exclamation-circle',
    'fa-question-circle': 'bi-question-circle',
    'fa-cog': 'bi-gear',
    'fa-cogs': 'bi-gears',
    'fa-user': 'bi-person',
    'fa-users': 'bi-people',
    'fa-envelope': 'bi-envelope',
    'fa-home': 'bi-house',
    'fa-database': 'bi-database',
    'fa-server': 'bi-server',
    'fa-cloud': 'bi-cloud',
    'fa-download': 'bi-download',
    'fa-upload': 'bi-upload',
    'fa-file': 'bi-file-earmark',
    'fa-folder': 'bi-folder',
    'fa-save': 'bi-save',
    'fa-edit': 'bi-pencil',
    'fa-trash': 'bi-trash',
    'fa-plus': 'bi-plus',
    'fa-minus': 'bi-dash',
    'fa-search': 'bi-search',
    'fa-filter': 'bi-funnel',
    'fa-sort': 'bi-arrow-down-up',
    'fa-calendar': 'bi-calendar',
    'fa-clock': 'bi-clock',
    'fa-play': 'bi-play',
    'fa-pause': 'bi-pause',
    'fa-stop': 'bi-stop',
    'fa-chart-bar': 'bi-bar-chart',
    'fa-chart-line': 'bi-graph-up',
    'fa-chart-pie': 'bi-pie-chart',
    'fa-lock': 'bi-lock',
    'fa-unlock': 'bi-unlock',
    'fa-key': 'bi-key',
    'fa-eye': 'bi-eye',
    'fa-eye-slash': 'bi-eye-slash',
    'fa-print': 'bi-printer',
    'fa-copy': 'bi-clipboard',
    'fa-paste': 'bi-clipboard-check',
    'fa-cut': 'bi-scissors',
    'fa-link': 'bi-link',
    'fa-unlink': 'bi-link-45deg',
    'fa-paperclip': 'bi-paperclip',
    'fa-flag': 'bi-flag',
    'fa-bookmark': 'bi-bookmark',
    'fa-star': 'bi-star',
    'fa-heart': 'bi-heart',
    'fa-thumbs-up': 'bi-hand-thumbs-up',
    'fa-thumbs-down': 'bi-hand-thumbs-down',
    'fa-comment': 'bi-chat',
    'fa-comments': 'bi-chat-dots',
    'fa-bell': 'bi-bell',
    'fa-phone': 'bi-telephone',
    'fa-mobile': 'bi-phone',
    'fa-laptop': 'bi-laptop',
    'fa-desktop': 'bi-display',
    'fa-tablet': 'bi-tablet',
    'fa-wrench': 'bi-wrench',
    'fa-hammer': 'bi-hammer',
    'fa-screwdriver': 'bi-tools',
    'fa-bug': 'bi-bug',
    'fa-code': 'bi-code',
    'fa-terminal': 'bi-terminal',
    'fa-wifi': 'bi-wifi',
    'fa-plug': 'bi-plug',
    'fa-power-off': 'bi-power',
    'fa-arrow-right': 'bi-arrow-right',
    'fa-arrow-left': 'bi-arrow-left',
    'fa-arrow-up': 'bi-arrow-up',
    'fa-arrow-down': 'bi-arrow-down',
    'fa-chevron-right': 'bi-chevron-right',
    'fa-chevron-left': 'bi-chevron-left',
    'fa-chevron-up': 'bi-chevron-up',
    'fa-chevron-down': 'bi-chevron-down',
    # Additional mappings from audit
    'fa-memory': 'bi-memory',
    'fa-puzzle-piece': 'bi-puzzle',
    'fa-lightbulb': 'bi-lightbulb',
    'fa-toggle-on': 'bi-toggle-on',
    'fa-forward': 'bi-skip-forward',
    'fa-vial': 'bi-flask',
    'fa-balance-scale': 'bi-balance',
    'fa-scale-balanced': 'bi-balance',
    'fa-book': 'bi-book',
    'fa-redo': 'bi-arrow-clockwise',
    'fa-hourglass-half': 'bi-hourglass-split',
    'fa-rotate': 'bi-arrow-clockwise',
    'fa-clipboard-check': 'bi-clipboard-check',
    'fa-graduation-cap': 'bi-mortarboard',
    'fa-sitemap': 'bi-diagram-3',
    'fa-building': 'bi-building',
    # Size modifiers (remove, not needed in Bootstrap Icons)
    'fa-2x': '',
    'fa-3x': '',
    'fa-4x': '',
    'fa-5x': '',
    'fa-lg': '',
    'fa-sm': '',
    'fa-xs': '',
}

def find_fontawesome_icons(file_path):
    """Find all FontAwesome icon usage in a file"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    # Find all fa-* class names
    fa_pattern = r'(?:fas|far|fab|fa)\s+(fa-[a-z0-9\-]+)'
    matches = re.findall(fa_pattern, content)

    # Also find standalone fa-* classes
    standalone_pattern = r'class="[^"]*\b(fa-[a-z0-9\-]+)\b[^"]*"'
    standalone_matches = re.findall(standalone_pattern, content)

    all_icons = set(matches + standalone_matches)
    return all_icons

def migrate_icons(file_path, dry_run=True):
    """Migrate FontAwesome icons to Bootstrap Icons"""
    with open(file_path, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    replacements = []

    # Replace FontAwesome style classes (fas, far, fab) with bi
    content = re.sub(r'\b(fas|far|fab)\s+', 'bi ', content)

    # Replace individual FontAwesome icon classes
    for fa_icon, bi_icon in FA_TO_BI_MAPPING.items():
        if fa_icon in content:
            content = content.replace(fa_icon, bi_icon)
            replacements.append((fa_icon, bi_icon))

    # Check for unmapped icons by parsing the updated content
    fa_pattern = r'(?:fas|far|fab|fa)\s+(fa-[a-z0-9\-]+)'
    remaining_fa_matches = re.findall(fa_pattern, content)
    standalone_pattern = r'class="[^"]*\b(fa-[a-z0-9\-]+)\b[^"]*"'
    remaining_standalone = re.findall(standalone_pattern, content)
    remaining_fa = set(remaining_fa_matches + remaining_standalone)
    unmapped = [icon for icon in remaining_fa if icon not in FA_TO_BI_MAPPING]

    if not dry_run and content != original_content:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(content)

    return replacements, unmapped

def main():
    import sys

    dry_run = '--execute' not in sys.argv

    if dry_run:
        print("=== FontAwesome to Bootstrap Icons Migration (DRY RUN) ===\n")
        print("Run with --execute to apply changes\n")
    else:
        print("=== FontAwesome to Bootstrap Icons Migration (EXECUTING) ===\n")

    templates_dir = Path('templates')
    total_replacements = 0
    files_modified = 0
    all_unmapped = set()

    for twig_file in templates_dir.rglob('*.twig'):
        fa_icons = find_fontawesome_icons(twig_file)

        if fa_icons:
            replacements, unmapped = migrate_icons(twig_file, dry_run=dry_run)

            if replacements:
                print(f"\n{twig_file.relative_to(templates_dir)}:")
                for fa_icon, bi_icon in replacements:
                    print(f"  {fa_icon} → {bi_icon}")
                total_replacements += len(replacements)
                files_modified += 1

            if unmapped:
                print(f"  ⚠️  Unmapped icons: {', '.join(unmapped)}")
                all_unmapped.update(unmapped)

    print(f"\n\n=== Summary ===")
    print(f"Files with FontAwesome icons: {files_modified}")
    print(f"Total icon replacements: {total_replacements}")

    if all_unmapped:
        print(f"\n⚠️  Unmapped FontAwesome icons ({len(all_unmapped)}):")
        for icon in sorted(all_unmapped):
            print(f"  - {icon}")
        print("\nThese icons need manual mapping or may not have Bootstrap equivalents.")

    if dry_run:
        print("\n✓ Dry run complete. Run with --execute to apply changes.")
    else:
        print("\n✓ Migration complete!")

    print("\n📋 Icon Library Reference:")
    print("  - Bootstrap Icons: https://icons.getbootstrap.com/")
    print("  - Browse all 2000+ Bootstrap Icons for alternatives")

if __name__ == '__main__':
    main()
