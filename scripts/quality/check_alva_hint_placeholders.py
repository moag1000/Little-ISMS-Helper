#!/usr/bin/env python3
"""
Gate 9: Alva-Hint Translation Placeholder Consistency

For every AlvaHintRule under src/AlvaHint/Rule/Global/:
  1. Read bodyTranslationKey + bodyTranslationParams from PHP source.
  2. Read titleTranslationKey (title can also carry placeholders).
  3. Look up the translation strings in translations/alva.de.yaml +
     translations/alva.en.yaml.
  4. Collect all %placeholder% patterns required by those strings.
  5. Verify every required placeholder is declared in the rule's
     bodyTranslationParams (the single params array passed to the DTO).

Mismatches → violations → non-zero exit (CI-BLOCKING).

Usage:
    python3 scripts/quality/check_alva_hint_placeholders.py
"""

import re
import sys
from pathlib import Path

# ── Paths ──────────────────────────────────────────────────────────────────
REPO_ROOT = Path(__file__).resolve().parent.parent.parent
RULES_DIR = REPO_ROOT / 'src' / 'AlvaHint' / 'Rule' / 'Global'
YAML_DE = REPO_ROOT / 'translations' / 'alva.de.yaml'
YAML_EN = REPO_ROOT / 'translations' / 'alva.en.yaml'

PLACEHOLDER_RE = re.compile(r'%[a-z_][a-z0-9_]*%')


# ── YAML parser (minimal — avoids PyYAML dependency) ──────────────────────

def parse_flat_yaml(path: Path) -> dict[str, str]:
    """
    Parse a nested YAML file into a flat dict with dotted keys.
    Handles only simple string values (no multi-line, no lists).
    Sufficient for alva.de/en.yaml structure.
    """
    flat: dict[str, str] = {}
    stack: list[tuple[int, str]] = []  # (indent, key_segment)

    with path.open(encoding='utf-8') as fh:
        for raw in fh:
            line = raw.rstrip()
            if not line or line.lstrip().startswith('#'):
                continue

            stripped = line.lstrip()
            indent = len(line) - len(stripped)

            if ':' not in stripped:
                continue

            colon_pos = stripped.index(':')
            key_part = stripped[:colon_pos].strip()
            value_part = stripped[colon_pos + 1:].strip()

            # Pop stack to current indent level
            while stack and stack[-1][0] >= indent:
                stack.pop()

            if value_part == '' or value_part.startswith('#'):
                # Mapping node — push onto stack
                stack.append((indent, key_part))
            else:
                # Leaf value — strip surrounding quotes
                val = value_part
                if (val.startswith("'") and val.endswith("'")) or \
                   (val.startswith('"') and val.endswith('"')):
                    val = val[1:-1]
                full_key = '.'.join(seg for _, seg in stack) + ('.' if stack else '') + key_part
                flat[full_key] = val

    return flat


def placeholders_in(text: str) -> set[str]:
    """Return set of %placeholder% tokens found in text."""
    return set(PLACEHOLDER_RE.findall(text))


# ── PHP extractor ──────────────────────────────────────────────────────────

def extract_rule_info(php_path: Path) -> dict | None:
    """
    Extract from a PHP rule file:
      - titleTranslationKey value
      - bodyTranslationKey value
      - set of '%foo%' keys declared in bodyTranslationParams
    Returns None if the file does not look like an AlvaHint rule.
    """
    src = php_path.read_text(encoding='utf-8')

    # Must contain AlvaHint construction
    if 'new AlvaHint(' not in src:
        return None

    # Extract titleTranslationKey
    m_title = re.search(r"titleTranslationKey:\s*'([^']+)'", src)
    title_key = m_title.group(1) if m_title else None

    # Extract bodyTranslationKey
    m_body = re.search(r"bodyTranslationKey:\s*'([^']+)'", src)
    body_key = m_body.group(1) if m_body else None

    # Extract declared param keys ('%foo%' => ...) inside bodyTranslationParams block
    # Strategy: find bodyTranslationParams: [...] block, then grep '%key%' => patterns
    # We look for all occurrences of  '%word%' =>  anywhere in the file
    # (safe: action route params don't use %placeholder% keys)
    declared_params: set[str] = set(re.findall(r"'(%[a-z_][a-z0-9_]*%)'\s*=>", src))

    return {
        'file': php_path.name,
        'title_key': title_key,
        'body_key': body_key,
        'declared_params': declared_params,
    }


# ── Main ───────────────────────────────────────────────────────────────────

def main() -> int:
    # Load both translation files
    de_flat = parse_flat_yaml(YAML_DE)
    en_flat = parse_flat_yaml(YAML_EN)

    violations: list[str] = []
    rules_checked = 0

    for php_file in sorted(RULES_DIR.glob('*.php')):
        info = extract_rule_info(php_file)
        if info is None:
            continue

        rules_checked += 1
        declared = info['declared_params']

        for lang, flat in [('de', de_flat), ('en', en_flat)]:
            for slot, key in [('title', info['title_key']), ('body', info['body_key'])]:
                if key is None:
                    continue
                translation = flat.get(key)
                if translation is None:
                    # Missing translation key is a separate concern (check_translations.py)
                    continue

                required = placeholders_in(translation)
                missing = required - declared

                if missing:
                    violations.append(
                        f"  [{lang}] {info['file']} — {slot} key '{key}'\n"
                        f"    YAML requires: {sorted(required)}\n"
                        f"    PHP declares:  {sorted(declared)}\n"
                        f"    Missing:       {sorted(missing)}"
                    )

    print(f"Gate 9: Alva-Hint placeholder consistency — checked {rules_checked} rules")

    if violations:
        print(f"\n✘ {len(violations)} violation(s) found:\n")
        for v in violations:
            print(v)
        print(
            "\nFix: add missing '%placeholder%' => ... entries to bodyTranslationParams "
            "in the listed rule file(s)."
        )
        return 1

    print(f"✔ No placeholder leaks detected.")
    return 0


if __name__ == '__main__':
    sys.exit(main())
