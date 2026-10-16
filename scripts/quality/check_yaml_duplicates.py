#!/usr/bin/env python3
"""
check_yaml_duplicates.py — duplicate mapping keys in translation YAML files.

A key that appears twice in the same mapping is silently collapsed by most
YAML loaders (PyYAML keeps the last value, so a whole parent section can
vanish) while Symfony's YAML parser rejects the file with a ParseException —
the translation domain then fails to load at runtime. This gate parses every
`translations/*.yaml` file and reports each duplicate with both line numbers.

Usage:
    python3 scripts/quality/check_yaml_duplicates.py            # all translation files
    python3 scripts/quality/check_yaml_duplicates.py FILE ...   # specific files

Exit codes: 0 clean, 1 duplicates or unparsable file, 2 PyYAML missing.
"""
from __future__ import annotations

import sys
from pathlib import Path

try:
    import yaml  # type: ignore
except ImportError as exc:  # pragma: no cover
    print(f"ERROR: PyYAML is required ({exc})", file=sys.stderr)
    sys.exit(2)

ROOT = Path(__file__).resolve().parents[2]


class _DuplicateKeyLoader(yaml.SafeLoader):
    """SafeLoader that records duplicate keys instead of overwriting them."""

    duplicates: list[tuple[str, int, int]]


def _construct_mapping(loader: _DuplicateKeyLoader, node: yaml.MappingNode, deep: bool = False) -> dict:
    seen: dict[object, int] = {}
    for key_node, _value_node in node.value:
        key = loader.construct_object(key_node, deep=deep)
        try:
            hash(key)
        except TypeError:
            continue
        line = key_node.start_mark.line + 1
        if key in seen:
            loader.duplicates.append((str(key), seen[key], line))
        else:
            seen[key] = line
    return yaml.SafeLoader.construct_mapping(loader, node, deep=deep)


_DuplicateKeyLoader.add_constructor(yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, _construct_mapping)


def check_file(path: Path) -> list[str]:
    loader = _DuplicateKeyLoader(path.read_text(encoding="utf-8"))
    loader.duplicates = []
    rel = path.relative_to(ROOT) if path.is_relative_to(ROOT) else path
    try:
        loader.get_single_data()
    except yaml.YAMLError as exc:
        return [f"FAIL {rel}: not parsable: {exc}"]
    finally:
        loader.dispose()
    return [
        f"FAIL {rel}:{second}: duplicate key '{key}' (first defined on line {first}; "
        "the later one silently replaces it)"
        for key, first, second in loader.duplicates
    ]


def main(argv: list[str]) -> int:
    files = [Path(a).resolve() for a in argv] or sorted((ROOT / "translations").glob("*.yaml"))
    problems: list[str] = []
    for f in files:
        problems.extend(check_file(f))
    if problems:
        print("\n".join(problems))
        print(f"\ncheck_yaml_duplicates: {len(problems)} problem(s) in {len(files)} file(s). "
              "Merge the duplicate sections into one.")
        return 1
    print(f"check_yaml_duplicates: OK — {len(files)} file(s), no duplicate keys.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
