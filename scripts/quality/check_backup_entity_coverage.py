#!/usr/bin/env python3
"""
check_backup_entity_coverage.py — backup completeness gate (Gate 43).

THE CONTRACT
------------
Every Doctrine entity class under `src/Entity/*.php` MUST be either:

  1) Listed in `App\\Service\\BackupService::PRODUCTIVE_ENTITIES`
     (= entity is included in the backup), OR
  2) Listed in `App\\Service\\BackupService::EXCLUDED_FROM_BACKUP`
     with an inline `=> 'reason'` string explaining WHY the entity is
     intentionally not part of the backup.

Any entity that is NEITHER listed will fail this gate. This prevents
silent backup gaps when a new entity is added but the developer forgets
to extend BackupService — historically 38 entities had silently slipped
through.

Special always-implicit entities (handled outside PRODUCTIVE_ENTITIES by
BackupService itself) are also accepted:

  - AuditLog       — backed up via the `$includeAuditLog` parameter
  - UserSession    — backed up via the `$includeUserSessions` parameter

USAGE
-----
Run from the repository root:

    python3 scripts/quality/check_backup_entity_coverage.py
    python3 scripts/quality/check_backup_entity_coverage.py --quiet

Exit codes:
  0  all entities accounted for
  1  one or more entities un-categorized (FAIL)
  2  parse / I/O error
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ENTITY_DIR = ROOT / "src" / "Entity"
BACKUP_SERVICE = ROOT / "src" / "Service" / "BackupService.php"

# Entities that BackupService treats specially — not in PRODUCTIVE_ENTITIES
# but explicitly backed up via dedicated `$includeAuditLog` / `$includeUserSessions`
# flags. They are accepted as covered.
IMPLICIT_COVERED = frozenset({"AuditLog", "UserSession"})

# Match the start of either constant array. We then walk forward until the
# matching `];` to collect entity names.
RE_PRODUCTIVE_START = re.compile(
    r"private\s+const\s+array\s+PRODUCTIVE_ENTITIES\s*=\s*\[",
)
RE_EXCLUDED_START = re.compile(
    r"private\s+const\s+array\s+EXCLUDED_FROM_BACKUP\s*=\s*\[",
)

# Extract any `'EntityName'` or `"EntityName"` token. We accept PHP-style
# identifiers starting with uppercase letter and continuing with [A-Za-z0-9_].
RE_TOKEN = re.compile(r"['\"]([A-Z][A-Za-z0-9_]+)['\"]")

# For EXCLUDED_FROM_BACKUP we additionally require an inline rationale:
# `'EntityName' => 'reason text'`.
RE_EXCLUDED_PAIR = re.compile(
    r"['\"]([A-Z][A-Za-z0-9_]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]"
)


def list_entity_class_names() -> list[str]:
    """Return sorted list of entity class basenames present under src/Entity/."""
    if not ENTITY_DIR.is_dir():
        return []
    return sorted(f.stem for f in ENTITY_DIR.glob("*.php") if f.is_file())


def _slice_array_body(text: str, start_match: re.Match[str]) -> str | None:
    """Given the regex match for `... = [`, return the substring up to the
    matching `];` (balanced-bracket aware).
    """
    open_pos = start_match.end() - 1  # position of `[`
    depth = 0
    i = open_pos
    while i < len(text):
        ch = text[i]
        if ch == "[":
            depth += 1
        elif ch == "]":
            depth -= 1
            if depth == 0:
                return text[open_pos + 1:i]
        i += 1
    return None


def parse_productive_entities(text: str) -> set[str]:
    """Extract entity names from PRODUCTIVE_ENTITIES array."""
    m = RE_PRODUCTIVE_START.search(text)
    if not m:
        return set()
    body = _slice_array_body(text, m)
    if body is None:
        return set()
    return {tok.group(1) for tok in RE_TOKEN.finditer(body)}


def parse_excluded_entities(text: str) -> tuple[set[str], list[str]]:
    """Extract entity names from EXCLUDED_FROM_BACKUP array.

    Returns (entity_set, missing_reason_list). The missing_reason_list
    contains entity names that appear as bare quoted strings but without
    an `=> 'reason'` pair — these are treated as a soft warning.
    """
    m = RE_EXCLUDED_START.search(text)
    if not m:
        return set(), []
    body = _slice_array_body(text, m)
    if body is None:
        return set(), []

    pairs = {pm.group(1) for pm in RE_EXCLUDED_PAIR.finditer(body)}
    all_tokens = {tok.group(1) for tok in RE_TOKEN.finditer(body)}
    missing = sorted(all_tokens - pairs)
    return all_tokens, missing


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Verify every src/Entity/*.php entity is covered by "
            "BackupService::PRODUCTIVE_ENTITIES or EXCLUDED_FROM_BACKUP."
        ),
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="print one-line success summary on PASS",
    )
    args = parser.parse_args()

    if not BACKUP_SERVICE.is_file():
        print(f"ERROR: BackupService not found at {BACKUP_SERVICE}", file=sys.stderr)
        return 2

    try:
        text = BACKUP_SERVICE.read_text(encoding="utf-8")
    except OSError as e:
        print(f"ERROR: Could not read {BACKUP_SERVICE}: {e}", file=sys.stderr)
        return 2

    productive = parse_productive_entities(text)
    excluded, missing_reason = parse_excluded_entities(text)

    if not productive:
        print(
            "ERROR: Could not parse PRODUCTIVE_ENTITIES from BackupService.php "
            "(constant missing or malformed).",
            file=sys.stderr,
        )
        return 2

    all_entities = set(list_entity_class_names())
    covered = productive | excluded | IMPLICIT_COVERED
    uncategorized = sorted(all_entities - covered)

    # Entities that are listed but no longer exist (drift in the other
    # direction). Not a hard failure (could be a stale reference for an
    # old entity rename) but warn loudly.
    stale_productive = sorted(productive - all_entities - IMPLICIT_COVERED)
    stale_excluded = sorted(excluded - all_entities)

    fail = bool(uncategorized) or bool(missing_reason)

    if fail:
        print(
            "FAIL: Gate 43 — Backup entity coverage incomplete.",
            file=sys.stderr,
        )
        if uncategorized:
            print(
                f"\n  {len(uncategorized)} entity class(es) in src/Entity/ are NOT covered\n"
                "  by BackupService::PRODUCTIVE_ENTITIES or EXCLUDED_FROM_BACKUP:\n",
                file=sys.stderr,
            )
            for name in uncategorized:
                print(f"    - {name}", file=sys.stderr)
            print(
                "\n  Fix: add each entity to PRODUCTIVE_ENTITIES (preferred —\n"
                "  every productive user-row deserves a backup), OR to\n"
                "  EXCLUDED_FROM_BACKUP with an inline `=> 'reason'` string\n"
                "  explaining why it is safe to skip (e.g. seeded global\n"
                "  catalogue, derived snapshot).\n",
                file=sys.stderr,
            )
        if missing_reason:
            print(
                f"\n  {len(missing_reason)} entity in EXCLUDED_FROM_BACKUP is missing\n"
                "  an inline `=> 'reason'` rationale:\n",
                file=sys.stderr,
            )
            for name in missing_reason:
                print(f"    - {name}", file=sys.stderr)
            print(
                "\n  Fix: replace bare `'X'` with `'X' => 'why excluded'`.\n",
                file=sys.stderr,
            )
        return 1

    if stale_productive or stale_excluded:
        # Warn but do not fail — stale entries usually mean a clean-up
        # is pending after an entity rename/removal.
        for name in stale_productive:
            print(
                f"WARNING: '{name}' listed in PRODUCTIVE_ENTITIES but no "
                "src/Entity/{name}.php file exists.",
                file=sys.stderr,
            )
        for name in stale_excluded:
            print(
                f"WARNING: '{name}' listed in EXCLUDED_FROM_BACKUP but no "
                "src/Entity/{name}.php file exists.",
                file=sys.stderr,
            )

    if args.quiet:
        print(
            f"OK: Gate 43 — backup coverage complete "
            f"({len(productive)} productive + {len(excluded)} excluded "
            f"+ {len(IMPLICIT_COVERED)} implicit = {len(all_entities)} entities)."
        )
    else:
        print("PASS: Backup entity coverage complete.")
        print(f"  Total entities under src/Entity/      : {len(all_entities)}")
        print(f"  In PRODUCTIVE_ENTITIES (backed up)    : {len(productive)}")
        print(f"  In EXCLUDED_FROM_BACKUP (with reason) : {len(excluded)}")
        print(f"  Implicit (AuditLog / UserSession)     : {len(IMPLICIT_COVERED)}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
