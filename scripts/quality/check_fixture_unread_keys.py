#!/usr/bin/env python3
"""
check_fixture_unread_keys.py — Detect shipped fixture data that no loader reads.

Motivation (a real, expensive incident):
    736 compliance mapping pairs shipped under a plural `targets:` key while
    the importer only ever read the singular `target:`. The YAML was valid,
    the files looked complete, the tests were green — and every one of those
    pairs was invisible at runtime. The defect survived review precisely
    because nothing compares "keys we ship" against "keys code reads".

This gate closes that class. For every key appearing in fixtures/library it
asks: does any PHP source file mention this key as a string literal? If not,
the key is inert — either dead payload (a `targets:`-class bug) or purely
descriptive metadata (a `license_note:`), and the baseline records which.

Descriptive keys are legitimate; they are baselined once and stay silent.
NEW unread keys fail the gate, because a newly-introduced inert key is far
more likely to be a wiring mistake than deliberate documentation.

Usage:
    check_fixture_unread_keys.py --baseline scripts/quality/baselines/fixture_unread_keys.txt
    check_fixture_unread_keys.py --write-baseline scripts/quality/baselines/fixture_unread_keys.txt
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover - CI installs pyyaml
    print("check_fixture_unread_keys: pyyaml not installed, skipping.")
    sys.exit(0)

ROOT = Path(__file__).resolve().parents[2]
FIXTURE_DIR = ROOT / "fixtures" / "library"
PHP_DIRS = [ROOT / "src"]

# Keys shorter than this are too generic to attribute reliably (e.g. "id").
MIN_KEY_LEN = 3

RE_KEY = re.compile(r"^[a-zA-Z_][a-zA-Z0-9_]*$")


def collect_fixture_keys() -> dict[str, set[str]]:
    """Map every key found in the fixture corpus to the files it appears in."""
    found: dict[str, set[str]] = {}

    def walk(node: object, origin: str) -> None:
        if isinstance(node, dict):
            for key, value in node.items():
                if isinstance(key, str) and len(key) >= MIN_KEY_LEN and RE_KEY.match(key):
                    found.setdefault(key, set()).add(origin)
                walk(value, origin)
        elif isinstance(node, list):
            for item in node:
                walk(item, origin)

    for path in sorted(FIXTURE_DIR.rglob("*.yaml")):
        try:
            data = yaml.safe_load(path.read_text(encoding="utf-8"))
        except Exception:
            # Malformed YAML is another gate's problem, not ours.
            continue
        walk(data, str(path.relative_to(ROOT)))

    return found


def collect_php_literals() -> str:
    """One big haystack of PHP source — cheaper than re-reading per key."""
    chunks: list[str] = []
    for directory in PHP_DIRS:
        if not directory.exists():
            continue
        for path in directory.rglob("*.php"):
            try:
                chunks.append(path.read_text(encoding="utf-8", errors="ignore"))
            except OSError:
                continue
    return "\n".join(chunks)


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    entries: set[str] = set()
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            entries.add(line)
    return entries


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--baseline", type=Path, default=None)
    parser.add_argument("--write-baseline", type=Path, default=None)
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    if not FIXTURE_DIR.exists():
        print("check_fixture_unread_keys: no fixtures/library directory, skipping.")
        return 0

    keys = collect_fixture_keys()
    php = collect_php_literals()

    unread: list[tuple[str, int, str]] = []
    for key, files in sorted(keys.items()):
        # A key counts as "read" when it appears as a quoted string literal.
        if re.search(r"['\"]" + re.escape(key) + r"['\"]", php):
            continue
        sample = sorted(files)[0]
        unread.append((key, len(files), sample))

    if args.write_baseline:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_fixture_unread_keys.py baseline\n")
            fh.write("# Fixture keys no PHP loader reads — descriptive metadata.\n")
            fh.write("# A NEW entry here usually means dead payload, not documentation.\n")
            for key, _count, _sample in unread:
                fh.write(f"{key}\n")
        print(f"check_fixture_unread_keys: wrote {len(unread)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [entry for entry in unread if entry[0] not in baseline]
    baselined = len(unread) - len(new)

    if not new:
        if not args.quiet:
            print(
                f"check_fixture_unread_keys: OK — {len(unread)} unread key(s), all {baselined} baselined."
            )
        else:
            print(f"check_fixture_unread_keys: OK ({len(unread)}, all baselined)")
        return 0

    print("check_fixture_unread_keys: VIOLATIONS\n")
    for key, count, sample in new:
        print(f"FAIL '{key}' — appears in {count} fixture file(s), read by no PHP loader")
        print(f"     e.g. {sample}")
    print(f"\ncheck_fixture_unread_keys: {len(new)} new unread key(s) ({baselined} baselined).")
    print("Either wire the key into its loader, or — if it is purely descriptive —")
    print("re-baseline with --write-baseline and say so in the commit message.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
