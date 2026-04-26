#!/usr/bin/env python3
"""
Fix _fa_alert.render(body: '{{ ... }}') → embed-block form.

Two bugs being fixed:
1. SCOPE: macro import not available inside {% embed %} blocks
2. RENDER: Twig single-quoted strings are literal — {{ ... }} inside body is never interpolated

Only converts sites where body (or title) contains {{ with \' escape patterns.
Leaves literal-body macro calls (body: 'plain text') untouched.

All buggy calls are single-line — safe to process line-by-line.
"""

import re
import sys
from pathlib import Path

TEMPLATES_DIR = Path("/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/templates")
EMBED_PATH = "_components/_fa_alert.html.twig"


def line_needs_conversion(line: str) -> bool:
    """
    Returns True if line contains _fa_alert.render with body/title containing
    {{ ... }} escaped Twig patterns (the \\' escape bug).
    """
    if '_fa_alert.render' not in line:
        return False
    if '{{' not in line:
        return False
    if "\\'" not in line:
        return False
    # Must have body: or title: param
    if not re.search(r'\b(body|title)\s*:', line):
        return False
    return True


def parse_single_quoted_value(s: str, start: int) -> tuple[str, int]:
    """
    Parse a single-quoted string starting at s[start] (which must be "'").
    Handles \\' escapes inside the string.
    Returns (value_with_escapes_resolved, end_position_after_quote).
    """
    assert s[start] == "'", f"Expected ', got {repr(s[start])}"
    i = start + 1
    chars = []
    while i < len(s):
        if s[i] == '\\' and i + 1 < len(s) and s[i + 1] == "'":
            chars.append("'")
            i += 2
        elif s[i] == "'":
            i += 1
            return ''.join(chars), i
        else:
            chars.append(s[i])
            i += 1
    raise ValueError(f"Unterminated single-quoted string at position {start}")


def parse_props_from_line(line: str) -> tuple[dict, int, int] | None:
    """
    Find and parse the _fa_alert.render({...}) call in a line.
    Returns (props_dict, start_of_{{ token, end_of_}} token) or None.
    """
    # Find '{{ _fa_alert.render('
    m = re.search(r'\{\{\s*_fa_alert\.render\(\s*\{', line)
    if not m:
        return None

    call_start = m.start()  # position of first {
    # Now parse the props dict starting after the {
    props_start = m.end()  # position right after the opening {

    props = {}
    i = props_start
    s = line

    # Parse key: value pairs
    while i < len(s):
        # Skip whitespace and commas
        while i < len(s) and s[i] in ' \t\n\r,':
            i += 1

        if i >= len(s):
            break

        # Check if we hit the closing } of the props dict
        if s[i] == '}':
            # This might be closing the props dict
            # Next non-whitespace should be ) }} or ) }
            i += 1  # skip }
            # Skip whitespace
            while i < len(s) and s[i] in ' \t':
                i += 1
            # Should be )
            if i < len(s) and s[i] == ')':
                i += 1
                while i < len(s) and s[i] in ' \t':
                    i += 1
                # Should be }}
                if i + 1 < len(s) and s[i] == '}' and s[i+1] == '}':
                    call_end = i + 2
                    return props, call_start, call_end
            break

        # Read key (identifier)
        key_m = re.match(r'([a-zA-Z_][a-zA-Z0-9_]*)', s[i:])
        if not key_m:
            # Unknown character, skip
            i += 1
            continue

        key = key_m.group(1)
        i += len(key)

        # Skip whitespace and colon
        while i < len(s) and s[i] in ' \t':
            i += 1
        if i >= len(s) or s[i] != ':':
            continue
        i += 1  # skip colon
        while i < len(s) and s[i] in ' \t':
            i += 1

        if i >= len(s):
            break

        # Read value
        if s[i] == "'":
            try:
                val, i = parse_single_quoted_value(s, i)
                props[key] = val
            except ValueError:
                break
        elif s[i] == '"':
            # Double-quoted string
            i += 1
            val_chars = []
            while i < len(s):
                if s[i] == '\\':
                    i += 1
                    if i < len(s):
                        val_chars.append(s[i])
                        i += 1
                elif s[i] == '"':
                    i += 1
                    break
                else:
                    val_chars.append(s[i])
                    i += 1
            props[key] = ''.join(val_chars)
        elif s[i:i+4] == 'true':
            props[key] = True
            i += 4
        elif s[i:i+5] == 'false':
            props[key] = False
            i += 5
        else:
            # Unknown value, skip to comma
            while i < len(s) and s[i] not in ',}':
                i += 1
            continue

    return None


def build_embed(props: dict, indent: str) -> str:
    """
    Build the embed-block form from parsed props.
    """
    embed_props = {}
    for k, v in props.items():
        if k not in ('body', 'title'):
            embed_props[k] = v

    prop_parts = []
    for k, v in embed_props.items():
        if isinstance(v, bool):
            prop_parts.append(f"{k}: {'true' if v else 'false'}")
        else:
            prop_parts.append(f"{k}: '{v}'")

    props_str = ', '.join(prop_parts)

    body = props.get('body', '')
    title = props.get('title', '')

    lines = []
    lines.append(f"{indent}{{% embed '{EMBED_PATH}' with {{ props: {{ {props_str} }} }} %}}")

    if title:
        lines.append(f"{indent}    {{% block alert_title %}}{title}{{% endblock %}}")

    if body:
        lines.append(f"{indent}    {{% block alert_body %}}{body}{{% endblock %}}")

    lines.append(f"{indent}{{% endembed %}}")

    return '\n'.join(lines)


def get_indent(line: str) -> str:
    """Get leading whitespace of a line."""
    m = re.match(r'^(\s*)', line)
    return m.group(1) if m else ''


def process_file(filepath: Path) -> tuple[int, list[str]]:
    """
    Process a single file. Returns (count_converted, skipped_messages).
    """
    lines = filepath.read_text(encoding='utf-8').split('\n')
    new_lines = []
    converted = 0
    skipped = []

    for lineno, line in enumerate(lines, 1):
        if not line_needs_conversion(line):
            new_lines.append(line)
            continue

        result = parse_props_from_line(line)
        if result is None:
            skipped.append(f"Line {lineno}: Could not parse props: {line.strip()[:80]!r}")
            new_lines.append(line)
            continue

        props, call_start, call_end = result

        if not props:
            skipped.append(f"Line {lineno}: Empty props: {line.strip()[:80]!r}")
            new_lines.append(line)
            continue

        body = props.get('body', '')
        title = props.get('title', '')

        # Double-check: does the body/title actually have {{ ... }} Twig markup?
        if '{{' not in body and '{{' not in title:
            # No actual Twig in body — skip (shouldn't happen given line_needs_conversion,
            # but be safe)
            new_lines.append(line)
            continue

        indent = get_indent(line)
        # If the call doesn't start at the line's indent (i.e. there's prefix text),
        # use the prefix + embed
        prefix = line[:call_start]
        suffix = line[call_end:]

        if suffix.strip():
            # There's content after the call on the same line - unusual, skip with note
            skipped.append(f"Line {lineno}: Suffix content after call — manual review needed: {line.strip()[:80]!r}")
            new_lines.append(line)
            continue

        embed = build_embed(props, indent)

        # If there's non-whitespace prefix, we need to handle it
        if prefix.strip():
            # e.g. inside JS: html += `{{ _fa_alert... }}`
            # These are inside JS template literals - skip
            skipped.append(f"Line {lineno}: Non-whitespace prefix (JS context?) — manual review: {line.strip()[:80]!r}")
            new_lines.append(line)
            continue

        new_lines.append(embed)
        converted += 1

    if converted > 0:
        filepath.write_text('\n'.join(new_lines), encoding='utf-8')

    return converted, skipped


def main():
    # Get affected files first
    affected = []
    for f in sorted(TEMPLATES_DIR.rglob('*.html.twig')):
        content = f.read_text(encoding='utf-8')
        if any(line_needs_conversion(line) for line in content.split('\n')):
            count = sum(1 for line in content.split('\n') if line_needs_conversion(line))
            affected.append((f, count))

    print(f"Found {len(affected)} files with sites to convert\n")

    total_files = 0
    total_sites = 0
    all_skipped = []

    for filepath, num in affected:
        rel = filepath.relative_to(TEMPLATES_DIR.parent)
        print(f"[{filepath.name}] {num} site(s)...", end=' ', flush=True)

        count, skipped = process_file(filepath)
        total_sites += count
        if count > 0:
            total_files += 1
            print(f"OK ({count} converted)")
        else:
            print(f"(0 converted)")

        for s in skipped:
            all_skipped.append(f"  {rel}: {s}")
            print(f"  SKIP: {s}")

    print(f"\n{'='*60}")
    print(f"Files converted: {total_files}")
    print(f"Sites converted: {total_sites}")

    if all_skipped:
        print(f"\nSKIPPED ({len(all_skipped)}):")
        for s in all_skipped:
            print(s)


if __name__ == '__main__':
    main()
