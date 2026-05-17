#!/usr/bin/env python3
"""
check_ddl_transactional.py — Enforce isTransactional()=false on DDL migrations.

MySQL implicitly commits on ALTER TABLE / CREATE TABLE / DROP TABLE — once
that happens Doctrine's per-migration SAVEPOINT is invalidated, so any
follow-up migration in the same `migrate` run fails with
`SAVEPOINT DOCTRINE_X does not exist`.

Workaround: each DDL-touching migration must override `isTransactional()` to
return `false`. `doctrine:migrations:diff` does NOT add this automatically.

This gate scans `migrations/Version*.php` and FAILS when DDL is detected but
`isTransactional` returns true (or is missing the override).

Detected DDL: ALTER TABLE, CREATE TABLE, DROP TABLE, CREATE INDEX, DROP INDEX
              (case-insensitive, in `addSql(...)` string literals only).
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MIG_DIR = ROOT / "migrations"

DDL_KEYWORDS = re.compile(
    r"\b(ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+TABLE|CREATE\s+(?:UNIQUE\s+)?INDEX|DROP\s+INDEX)\b",
    re.IGNORECASE,
)
RE_IS_TRANSACTIONAL_FALSE = re.compile(
    r"public\s+function\s+isTransactional\s*\([^)]*\)\s*:\s*bool\s*\{\s*return\s+false\s*;\s*\}",
)


def scan(path: Path) -> tuple[bool, bool, list[int]]:
    """Return (has_ddl, has_override, ddl_line_numbers)."""
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return False, False, []
    if not DDL_KEYWORDS.search(text):
        return False, True, []
    ddl_lines: list[int] = []
    for idx, raw in enumerate(text.splitlines(), start=1):
        if DDL_KEYWORDS.search(raw):
            ddl_lines.append(idx)
    has_override = bool(RE_IS_TRANSACTIONAL_FALSE.search(text))
    return True, has_override, ddl_lines


def walk(root: Path) -> list[Path]:
    return sorted(p for p in root.glob("Version*.php") if p.is_file())


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        s = raw.strip()
        if s and not s.startswith("#"):
            out.add(s)
    return out


def _rel(p: Path) -> Path:
    try:
        return p.relative_to(ROOT)
    except ValueError:
        return Path(p.name)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    if not MIG_DIR.is_dir():
        print(f"check_ddl_transactional: no migrations dir ({MIG_DIR}) — OK")
        return 0

    violations: list[tuple[Path, int, str]] = []
    for f in walk(MIG_DIR):
        has_ddl, has_override, ddl_lines = scan(f)
        if has_ddl and not has_override:
            ln = ddl_lines[0] if ddl_lines else 1
            violations.append((f, ln, "DDL detected without isTransactional()=false override"))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_ddl_transactional.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_ddl_transactional: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_ddl_transactional: OK — {total} legacy migration(s), {baselined} baselined.")
        else:
            print(f"check_ddl_transactional: OK ({total}, all baselined)")
        return 0

    print("check_ddl_transactional: VIOLATIONS\n")
    for path, ln, snip in new:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    print(f"\ncheck_ddl_transactional: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: add the following method to the migration class:")
    print("    public function isTransactional(): bool { return false; }")
    return 1


if __name__ == "__main__":
    sys.exit(main())
