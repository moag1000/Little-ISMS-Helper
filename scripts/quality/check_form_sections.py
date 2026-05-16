#!/usr/bin/env python3
"""
check_form_sections.py — S4 Foundation P-2 SectionPolicy CI-gate.

For every FormType in src/Form/ that implements SectionMapInterface,
validate that:

  1. Every field name in getSectionMap() is referenced via a builder
     ->add('<name>', ...) call in buildForm()  →  no dead section entries.
  2. Every field added via builder->add() appears in exactly one section
     →  no leaks into the catch-all "Sonstiges" bucket.
  3. No field appears in multiple sections at once.

Exits 1 on mismatch.

Mode:
  - default                 → strict: any mismatch is an error
  - --warn-only             → still report mismatches but exit 0
                              (used during rollout while individual
                              FormTypes are migrated)
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORM_DIR = ROOT / "src" / "Form"

INTERFACE_PATTERN = re.compile(r"implements\s+[^{]*\bSectionMapInterface\b")
BUILDER_ADD_PATTERN = re.compile(r"->add\(\s*['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]")
# OwnerPickerFormTrait::addOwnerPicker(...) injects up to 4 child fields
# whose names come from config-keys user_field / person_field /
# deputies_field / legacy_field. Parse those config arrays so the gate
# treats them as builder-added fields.
OWNER_PICKER_PATTERN = re.compile(
    r"addOwnerPicker\s*\([^,]+,\s*\[(.*?)\]\s*\)\s*;",
    re.DOTALL,
)
OWNER_PICKER_FIELDS = (
    "user_field",
    "person_field",
    "deputies_field",
    "legacy_field",
)
OWNER_PICKER_FIELD_PATTERN = re.compile(
    r"['\"](?:" + "|".join(OWNER_PICKER_FIELDS) + r")['\"]\s*=>\s*['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]"
)
SECTION_MAP_METHOD_PATTERN = re.compile(
    r"public\s+static\s+function\s+getSectionMap\(\)\s*:\s*array\s*\{(.*?)\n\s*\}",
    re.DOTALL,
)
# Match section entries: 'section_key' => [ 'field1', 'field2', ... ]
SECTION_ENTRY_PATTERN = re.compile(
    r"['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]\s*=>\s*\[(.*?)\]",
    re.DOTALL,
)
FIELD_LITERAL_PATTERN = re.compile(r"['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]")


def find_form_types() -> list[Path]:
    return sorted(FORM_DIR.rglob("*Type.php"))


def parse_form_type(path: Path) -> tuple[set[str], dict[str, list[str]]] | None:
    """Return (builder_fields, section_map) or None if FormType does not
    implement SectionMapInterface."""
    text = path.read_text(encoding="utf-8")
    if not INTERFACE_PATTERN.search(text):
        return None

    builder_fields = set(BUILDER_ADD_PATTERN.findall(text))
    for picker_block in OWNER_PICKER_PATTERN.finditer(text):
        builder_fields.update(OWNER_PICKER_FIELD_PATTERN.findall(picker_block.group(1)))

    match = SECTION_MAP_METHOD_PATTERN.search(text)
    if not match:
        # implements SectionMapInterface but no method body found —
        # could be on an abstract class; treat as N/A for safety
        return builder_fields, {}

    body = match.group(1)
    section_map: dict[str, list[str]] = {}
    for section_match in SECTION_ENTRY_PATTERN.finditer(body):
        section_key = section_match.group(1)
        fields_block = section_match.group(2)
        fields = FIELD_LITERAL_PATTERN.findall(fields_block)
        section_map[section_key] = fields
    return builder_fields, section_map


def validate_form_type(
    path: Path,
    builder_fields: set[str],
    section_map: dict[str, list[str]],
) -> list[str]:
    errors: list[str] = []

    # Flatten section-map to a set + duplicate-detection
    seen: dict[str, str] = {}
    duplicates: list[tuple[str, str, str]] = []
    for section_key, fields in section_map.items():
        for field in fields:
            if field in seen:
                duplicates.append((field, seen[field], section_key))
            else:
                seen[field] = section_key

    section_fields = set(seen.keys())

    # 1. unmapped builder fields (would leak to "Sonstiges")
    leaked = sorted(builder_fields - section_fields)
    if leaked:
        errors.append(
            f"  unmapped builder fields (would leak to 'Sonstiges'): {', '.join(leaked)}"
        )

    # 2. dead section entries (referenced but never added to builder)
    dead = sorted(section_fields - builder_fields)
    if dead:
        errors.append(
            f"  dead section entries (referenced but not in buildForm): {', '.join(dead)}"
        )

    # 3. duplicates
    for field, first_section, second_section in duplicates:
        errors.append(
            f"  field '{field}' appears in multiple sections: '{first_section}' and '{second_section}'"
        )

    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--warn-only",
        action="store_true",
        help="report mismatches but exit 0",
    )
    args = parser.parse_args()

    total = 0
    ok = 0
    failures: list[tuple[Path, list[str]]] = []

    for path in find_form_types():
        result = parse_form_type(path)
        if result is None:
            continue
        builder_fields, section_map = result
        total += 1
        errors = validate_form_type(path, builder_fields, section_map)
        if errors:
            failures.append((path, errors))
        else:
            ok += 1

    print(
        f"check_form_sections: {ok}/{total} FormTypes implementing SectionMapInterface pass"
    )
    if failures:
        print("")
        for path, errors in failures:
            rel = path.relative_to(ROOT)
            print(f"{rel}:")
            for err in errors:
                print(err)
        print("")
        if args.warn_only:
            print(
                "WARN-ONLY mode — exiting 0. Promote to strict by dropping --warn-only."
            )
            return 0
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
